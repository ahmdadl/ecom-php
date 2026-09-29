<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Database;
use HZ\Illuminate\Mongez\Support\NidIndexPlan;
use HZ\Illuminate\Mongez\Support\NidIndexSpec;
use HZ\Illuminate\Mongez\Support\NidKeyRenamer;
use Illuminate\Console\Command;
use MongoDB\Collection;
use MongoDB\Database as MongoDatabase;

/**
 * The whole `id` -> `nid` cutover in one command, mirroring
 * scripts/mongo-nid-migration.js.
 *
 * Phases run in order, each one read-only unless `--execute` is passed:
 *
 *   inventory  read the current state and report blockers (duplicate nid/id
 *              values, documents with no identity at all, half-migrated
 *              collections). Purely diagnostic — it never writes.
 *   rename     rewrite documents, renaming `id` to `nid` at every nesting and
 *              array level (deep by default, top-level only with --top-level-only).
 *   counters   collapse the `ids` collection down to one canonical counter row
 *              per collection, at max(stored counter, highest nid in the
 *              collection and its *Trash sibling), and make sure it is unique-indexed.
 *   indexes    drop the legacy `id_1` indexes and create the `nid` indexes the
 *              app needs, per `mongez.nid.indexes`.
 *   verify     re-read the database and fail if any of the above did not stick.
 *
 * Duplicate `nid` values are reported, never deleted: a unique index is refused
 * until a human decides which document wins. Back up before running with
 * `--execute`.
 */
class MigrateNid extends Command
{
    /**
     * The pipeline of the migration, in the order it must run.
     */
    public const PHASES = ['inventory', 'rename', 'counters', 'indexes', 'verify'];

    /**
     * How many documents to inspect per collection before the verify pass gives
     * up on a collection, so a huge miss cannot stall the command.
     */
    protected const VERIFY_DOCUMENT_LIMIT = 5;

    /**
     * Collections with no business integer key, mirroring SKIP_EXACT in the JS
     * migration.
     *
     * @var list<string>
     */
    protected const SKIP_EXACT = [
        'ids',
        'migrations',
        'sessions',
        'jobs',
        'failed_jobs',
        'job_batches',
        'queue_records',
        'cache',
        'cache_locks',
        'personal_access_tokens',
        'password_resets',
    ];

    /**
     * @var list<string>
     */
    protected const SKIP_PREFIXES = ['oauth_', 'system.'];

    /**
     * The `ids` collection row fields that have ever held a counter value.
     *
     * @var list<string>
     */
    protected const COUNTER_FIELDS = ['id', 'cid', 'idwe'];

    protected $signature = 'mongez:migrate-nid
        {--execute : Apply the migration instead of reporting what it would do}
        {--collection=* : Limit the migration to the given collection names}
        {--include-trash : Also rename/index *Trash collections, not just count them}
        {--top-level-only : Rename only the top-level `id`, leaving nested and array `id` keys alone}
        {--skip-path=* : Dotted path segments whose nested `id` is an opaque payload, e.g. providerResponse}
        {--phase=* : Run only these phases: inventory, rename, counters, indexes, verify}
        {--batch=500 : Documents per bulk write while renaming}';

    protected $description = 'Migrate the MongoDB business key from id to nid: rename, dedupe report, rebuild ids counters, fix indexes, verify';

    /**
     * Whether the current run may write to the database.
     */
    protected bool $execute = false;

    /**
     * @var list<string>
     */
    protected array $phases = self::PHASES;

    public function handle(): int
    {
        $phases = $this->resolvePhases();

        if ($phases === null) {
            return self::FAILURE;
        }

        $this->phases = $phases;
        $this->execute = (bool) $this->option('execute');

        $database = Database::getDatabase();
        $targets = $this->targetCollections($database);
        $renamer = new NidKeyRenamer($this->skipPaths());

        $this->line(sprintf(
            'DB: %s | MODE: %s | collections: %d',
            $database->getDatabaseName(),
            $this->execute ? 'EXECUTE' : 'DRY RUN',
            count($targets),
        ));
        $this->line('Phases: ' . implode(', ', $this->phases));

        if (! $this->execute) {
            $this->comment('Dry run — nothing is written. Pass --execute to apply.');
        }

        $blockers = 0;

        if (in_array('inventory', $this->phases, true)) {
            $blockers += $this->inventory($database, $targets);
        }

        if (in_array('rename', $this->phases, true)) {
            $this->rename($database, $targets, $renamer);
        }

        if (in_array('counters', $this->phases, true)) {
            $blockers += $this->counters($database, $targets);
        }

        if (in_array('indexes', $this->phases, true)) {
            $blockers += $this->indexes($database, $targets);
        }

        if (in_array('verify', $this->phases, true)) {
            // A verify-only run is an explicit request to gate on the current
            // state, so it fails even without --execute.
            $gate = $this->execute || $this->phases === ['verify'];
            $blockers += $this->verify($database, $targets, $renamer, $gate);
        }

        if ($blockers > 0) {
            $this->error("RESULT: {$blockers} problem(s) found — see above.");

            return self::FAILURE;
        }

        $this->info($this->execute ? 'RESULT: OK' : 'RESULT: DRY RUN (no writes, nothing left to apply)');

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null  Null when the requested phase names are invalid.
     */
    protected function resolvePhases(): ?array
    {
        $requested = array_values(array_filter(array_map(
            static fn ($phase): string => strtolower(trim((string) $phase)),
            (array) $this->option('phase'),
        )));

        if ($requested === []) {
            return self::PHASES;
        }

        $unknown = array_values(array_diff($requested, self::PHASES));

        if ($unknown !== []) {
            $this->error('Unknown phase(s): ' . implode(', ', $unknown));
            $this->line('Available phases: ' . implode(', ', self::PHASES));

            return null;
        }

        return array_values(array_unique($requested));
    }

    /**
     * Collections to migrate, honouring --collection, the skip list, and
     * --include-trash.
     *
     * @return list<string>
     */
    protected function targetCollections(MongoDatabase $database): array
    {
        $selected = array_values(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            (array) $this->option('collection'),
        )));

        $names = $selected !== [] ? $selected : Database::collectionsList();

        $names = array_values(array_unique(array_filter($names, function (string $name): bool {
            if (in_array($name, self::SKIP_EXACT, true)) {
                return false;
            }

            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return false;
                }
            }

            return true;
        })));

        if ($this->option('include-trash')) {
            return $names;
        }

        return array_values(array_filter($names, static fn (string $name): bool => ! str_ends_with($name, 'Trash')));
    }

    /**
     * @return list<string>
     */
    protected function skipPaths(): array
    {
        $paths = array_merge(
            (array) config('mongez.nid.skip_paths', []),
            (array) $this->option('skip-path'),
        );

        $cleaned = [];

        foreach ($paths as $path) {
            $path = trim((string) $path);

            if ($path !== '') {
                $cleaned[] = $path;
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * Report the current state and everything that will block a unique `nid`
     * index. Read-only, so it runs identically in dry-run and execute mode.
     *
     * @param  list<string>  $targets
     */
    protected function inventory(MongoDatabase $database, array $targets): int
    {
        $this->line("\n--- Phase: inventory ---");
        $blockers = 0;

        foreach ($targets as $name) {
            $collection = $database->selectCollection($name);

            $docs = $collection->countDocuments([]);
            $legacy = $collection->countDocuments(['id' => ['$exists' => true]]);
            $nid = $collection->countDocuments(['nid' => ['$exists' => true]]);
            $without = $collection->countDocuments(['nid' => ['$exists' => false]]);
            $duplicates = $this->duplicateGroups($collection, 'nid');

            $this->line(sprintf(
                '  %s: docs=%d legacy_id=%d nid=%d without_nid=%d duplicate_nid_groups=%d',
                $name,
                $docs,
                $legacy,
                $nid,
                $without,
                $duplicates,
            ));

            if ($duplicates > 0) {
                $blockers++;
                $this->error("  $name: {$duplicates} duplicate nid group(s) — the unique nid index will be refused until these are resolved by hand");
                $this->reportDuplicateGroups($collection, 'nid');
            }

            if ($legacy > 0 && $nid > 0) {
                $this->warn("  $name: half migrated — {$legacy} document(s) still on id, {$nid} already on nid");
            }

            if ($without > 1 && $nid > 0) {
                $blockers++;
                $this->error("  $name: {$without} document(s) carry no nid at all — they will collide on the unique nid index");
            }

            if ($legacy === 0 && $without > 1) {
                $blockers++;
                $this->error("  $name: {$without} document(s) carry neither id nor nid and will stay unidentified after the migration");
            }
        }

        if ($blockers === 0) {
            $this->info('  inventory clean — no blockers');
        }

        return $blockers;
    }

    /**
     * @param  list<string>  $targets
     */
    protected function rename(MongoDatabase $database, array $targets, NidKeyRenamer $renamer): void
    {
        $deep = ! $this->option('top-level-only');
        $batch = max(1, (int) $this->option('batch'));

        $this->line("\n--- Phase: rename id -> nid ---");
        $this->line('  scope: ' . ($deep ? 'deep (top level, nested objects, and array elements)' : 'top level only'));

        $renamedKeys = 0;
        $droppedKeys = 0;
        $changedDocs = 0;

        foreach ($targets as $name) {
            $collection = $database->selectCollection($name);

            if (! $deep) {
                [$renamed, $dropped] = $this->renameTopLevel($collection);
                $renamedKeys += $renamed;
                $droppedKeys += $dropped;
                $changedDocs += $renamed + $dropped;
                $this->line("  $name: id->nid {$renamed} document(s), stale id dropped on {$dropped}");
                continue;
            }

            /** @var list<array{replaceOne: array{0: array<string, mixed>, 1: array<string, mixed>}}> $pending */
            $pending = [];
            /** @var array<string, true> $paths */
            $paths = [];
            $scanned = 0;
            $renamed = 0;
            $dropped = 0;

            foreach ($collection->find([], ['typeMap' => NidKeyRenamer::TYPE_MAP, 'batchSize' => $batch]) as $document) {
                if (! is_array($document)) {
                    continue;
                }

                $scanned++;

                $result = $renamer->rename($document);

                if ($result['renamedKeys'] === 0 && $result['droppedKeys'] === 0) {
                    continue;
                }

                $renamed += $result['renamedKeys'];
                $dropped += $result['droppedKeys'];

                foreach ($result['paths'] as $path) {
                    $paths[$path] = true;
                }

                if (! $this->execute) {
                    continue;
                }

                // Positional form: [filter, replacement]. The named
                // filter/replacement keys are not part of bulkWrite's contract.
                $pending[] = [
                    'replaceOne' => [
                        ['_id' => $document['_id'] ?? null],
                        $result['document'],
                    ],
                ];

                if (count($pending) >= $batch) {
                    $collection->bulkWrite($pending, ['ordered' => false]);
                    $pending = [];
                }
            }

            if ($pending !== []) {
                $collection->bulkWrite($pending, ['ordered' => false]);
            }

            $renamedKeys += $renamed;
            $droppedKeys += $dropped;
            $changedDocs += $renamed > 0 || $dropped > 0 ? 1 : 0;

            $pathNames = array_keys($paths);
            sort($pathNames);

            $this->line(sprintf(
                '  %s: scanned=%d keys_renamed=%d stale_id_dropped=%d%s',
                $name,
                $scanned,
                $renamed,
                $dropped,
                $pathNames === [] ? '' : ' paths=[' . implode(', ', $pathNames) . ']',
            ));
        }

        $this->line(sprintf(
            '  total: %d key(s) renamed, %d stale id(s) dropped%s',
            $renamedKeys,
            $droppedKeys,
            $this->execute ? '' : ' (dry run — nothing written)',
        ));
    }

    /**
     * Cheap top-level-only rename: two server-side updates per collection
     * instead of a full document rewrite per record.
     *
     * The pipeline form is required — a classic `['$set' => ['nid' => '$id']]`
     * stores the literal string "$id" instead of the field's value.
     *
     * @return array{int, int}  [documents renamed, stale ids dropped]
     */
    protected function renameTopLevel(Collection $collection): array
    {
        $renamed = $collection->countDocuments(['id' => ['$exists' => true], 'nid' => ['$exists' => false]]);
        $dropped = $collection->countDocuments(['id' => ['$exists' => true], 'nid' => ['$exists' => true]]);

        if ($this->execute) {
            $collection->updateMany(
                ['id' => ['$exists' => true], 'nid' => ['$exists' => false]],
                [['$set' => ['nid' => '$id']], ['$unset' => 'id']],
            );

            $collection->updateMany(
                ['id' => ['$exists' => true], 'nid' => ['$exists' => true]],
                [['$unset' => 'id']],
            );
        }

        return [$renamed, $dropped];
    }

    /**
     * Collapse the `ids` collection to one canonical counter row per collection,
     * advanced past the highest `nid` in the collection and its *Trash sibling.
     *
     * @param  list<string>  $targets
     */
    protected function counters(MongoDatabase $database, array $targets): int
    {
        $this->line("\n--- Phase: ids counters ---");
        $ids = $database->selectCollection('ids');
        $blockers = 0;

        $junk = ['$or' => [['collection' => ''], ['collection' => ['$exists' => false]]]];
        $junkRows = $ids->countDocuments($junk);

        if ($junkRows > 0) {
            $this->line("  ids: {$junkRows} junk row(s) with an empty or missing collection key" . ($this->execute ? ' -> deleted' : ''));

            if ($this->execute) {
                $ids->deleteMany($junk);
            }
        }

        foreach ($this->counterPlan($database, $targets) as $name => $plan) {
            $this->line(sprintf(
                '  %s: counter_max=%d data_max=%d -> %d%s',
                $name,
                $plan['counterMax'],
                $plan['dataMax'],
                $plan['final'],
                $plan['rows'] > 1 ? " ({$plan['rows']} rows collapsed)" : '',
            ));

            if ($this->execute) {
                $this->writeCounter($ids, $name, $plan);
            }
        }

        if ($this->execute) {
            try {
                $ids->createIndex(['collection' => 1], ['unique' => true]);
                $this->info('  ids: unique index on {collection: 1} in place');
            } catch (\Throwable $exception) {
                $blockers++;
                $this->error('  ids: unique index on {collection: 1} failed — ' . $exception->getMessage());
            }
        } elseif (! in_array('collection_1', $this->indexNames($ids), true)) {
            $this->warn('  ids: unique index on {collection: 1} is missing and will be created by --execute');
        }

        return $blockers;
    }

    /**
     * What each counter row should end up as, and what it is today.
     *
     * @param  list<string>  $targets
     * @return array<string, array{rows: int, counterMax: int, dataMax: int, final: int}>
     */
    protected function counterPlan(MongoDatabase $database, array $targets): array
    {
        $ids = $database->selectCollection('ids');
        $plan = [];

        foreach ($targets as $name) {
            $rows = 0;
            $counterMax = 0;

            foreach ($ids->find(['collection' => $name], ['typeMap' => ['root' => 'array', 'document' => 'array']]) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $rows++;

                foreach (self::COUNTER_FIELDS as $field) {
                    $value = $row[$field] ?? null;

                    if (is_numeric($value) && (int) $value > $counterMax) {
                        $counterMax = (int) $value;
                    }
                }
            }

            // A counter behind the data re-issues ids that are already taken.
            $dataMax = max(
                $this->maxNid($database, $name),
                $this->maxNid($database, $name . 'Trash'),
            );

            $plan[$name] = [
                'rows' => $rows,
                'counterMax' => $counterMax,
                'dataMax' => $dataMax,
                'final' => max($counterMax, $dataMax),
            ];
        }

        return $plan;
    }

    /**
     * @param  array{rows: int, counterMax: int, dataMax: int, final: int}  $plan
     */
    protected function writeCounter(Collection $ids, string $name, array $plan): void
    {
        $current = $ids->findOne(['collection' => $name], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        $isArray = is_array($current);
        $extraKeys = $isArray ? array_diff(array_keys($current), ['collection', 'id']) : ['<no row>'];
        $value = $isArray && isset($current['id']) ? (int) $current['id'] : null;

        // Leave the row alone only when it is already exactly what we want.
        if ($plan['rows'] === 1 && $extraKeys === [] && $value === $plan['final']) {
            return;
        }

        $ids->deleteMany(['collection' => $name]);

        if ($plan['final'] > 0) {
            $ids->insertOne(['collection' => $name, 'id' => $plan['final']]);
        }
    }

    /**
     * The highest identity in a collection, whether it is already `nid` or
     * still the legacy `id`.
     *
     * Both fields are considered because the *Trash sibling is normally not
     * renamed in the same run, and a counter that ignored it would re-issue ids
     * that a later restore of that document would collide with.
     */
    protected function maxNid(MongoDatabase $database, string $collection): int
    {
        $result = $database->selectCollection($collection)->aggregate([
            ['$group' => ['_id' => null, 'nid' => ['$max' => '$nid'], 'id' => ['$max' => '$id']]],
        ])->toArray();

        if ($result === []) {
            return 0;
        }

        $nid = $result[0]->nid ?? null;
        $id = $result[0]->id ?? null;

        return max(
            is_numeric($nid) ? (int) $nid : 0,
            is_numeric($id) ? (int) $id : 0,
        );
    }

    /**
     * Drop the legacy `id_1` indexes and create the `nid` indexes each collection
     * needs. Refuses to build a unique `nid` index while duplicate values would
     * make MongoDB reject it.
     *
     * @param  list<string>  $targets
     */
    protected function indexes(MongoDatabase $database, array $targets): int
    {
        $this->line("\n--- Phase: indexes ---");
        $blockers = 0;

        foreach ($targets as $name) {
            $collection = $database->selectCollection($name);
            $plan = $this->indexPlan($name);
            $existing = $this->indexNames($collection);

            foreach ($plan->dropIndexes as $index) {
                if (! in_array($index, $existing, true)) {
                    continue;
                }

                $this->line("  $name: drop index {$index}" . ($this->execute ? '' : ' (would drop)'));

                if ($this->execute) {
                    try {
                        $collection->dropIndex($index);
                    } catch (\Throwable $exception) {
                        $this->warn("  $name: could not drop {$index} — " . $exception->getMessage());
                    }
                }

                $existing = array_values(array_diff($existing, [$index]));
            }

            foreach ($plan->required() as $spec) {
                if (in_array($spec->name, $existing, true)) {
                    continue;
                }

                $blocked = $this->blocksIndex($collection, $spec);

                if ($blocked !== null) {
                    $blockers++;
                    $this->error("  $name: cannot create {$spec->name} — {$blocked}");

                    continue;
                }

                $this->line("  $name: create index {$spec->name}" . ($this->execute ? '' : ' (would create)'));

                if (! $this->execute) {
                    continue;
                }

                try {
                    $collection->createIndex($spec->keys, $spec->createOptions());
                } catch (\Throwable $exception) {
                    $blockers++;
                    $this->error("  $name: creating {$spec->name} failed — " . $exception->getMessage());
                }
            }
        }

        return $blockers;
    }

    protected function indexPlan(string $collection): NidIndexPlan
    {
        $config = config('mongez.nid.indexes', []);

        /** @var array<string, array{nid?: array<int, array<string, mixed>>, additional?: array<int, array<string, mixed>>, drop?: array<int, string>}> $config */
        $config = is_array($config) ? $config : [];

        return NidIndexPlan::resolve($collection, $config, (bool) config('mongez.nid.default_nid_index', true));
    }

    /**
     * Why a unique `nid` index cannot be built yet, or null when it can.
     */
    protected function blocksIndex(Collection $collection, NidIndexSpec $spec): ?string
    {
        if (! $spec->isUniqueNidIndex()) {
            return null;
        }

        $orphans = $collection->countDocuments(['nid' => ['$exists' => false]]);

        if ($orphans > 1) {
            return "{$orphans} document(s) have no nid and would all collide on the missing key";
        }

        $duplicates = $this->duplicateGroups($collection, 'nid');

        if ($duplicates > 0) {
            $this->reportDuplicateGroups($collection, 'nid');

            return "{$duplicates} duplicate nid group(s) remain";
        }

        return null;
    }

    /**
     * @param  list<string>  $targets
     */
    protected function verify(MongoDatabase $database, array $targets, NidKeyRenamer $renamer, bool $gate): int
    {
        $this->line("\n--- Phase: verify ---");
        $failures = 0;
        $ids = $database->selectCollection('ids');

        foreach ($targets as $name) {
            $collection = $database->selectCollection($name);
            $leftover = $this->documentsWithIdKeys($collection, $renamer);

            if ($leftover > 0) {
                $failures++;
                $this->error("  $name: {$leftover} document(s) still carry an `id` key");
            } else {
                $this->info("  $name: no `id` keys remain");
            }

            $existing = $this->indexNames($collection);

            if (in_array(NidIndexPlan::LEGACY_INDEX, $existing, true)) {
                $failures++;
                $this->error("  $name: legacy " . NidIndexPlan::LEGACY_INDEX . ' index still present');
            }

            foreach ($this->indexPlan($name)->required() as $spec) {
                if (in_array($spec->name, $existing, true)) {
                    continue;
                }

                $failures++;
                $this->error("  $name: required index {$spec->name} is missing");
            }
        }

        foreach ($this->counterPlan($database, $targets) as $name => $plan) {
            if ($plan['final'] === 0) {
                continue;
            }

            $row = $ids->findOne(['collection' => $name], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            $value = is_array($row) && isset($row['id']) ? (int) $row['id'] : null;

            if ($value !== null && $value >= $plan['final']) {
                continue;
            }

            $failures++;
            $this->error("  ids[{$name}]: expected >= {$plan['final']}, got " . ($value ?? '<missing row>'));
        }

        if ($gate || $failures === 0) {
            return $failures;
        }

        $this->comment('  dry run: the findings above are informational, the exit code is not gated');

        return 0;
    }

    protected function documentsWithIdKeys(Collection $collection, NidKeyRenamer $renamer): int
    {
        $found = 0;

        foreach ($collection->find([], ['typeMap' => NidKeyRenamer::TYPE_MAP]) as $document) {
            if (! $renamer->containsIdKey($document)) {
                continue;
            }

            $found++;

            if ($found >= self::VERIFY_DOCUMENT_LIMIT) {
                break;
            }
        }

        return $found;
    }

    protected function duplicateGroups(Collection $collection, string $field): int
    {
        $groups = $collection->aggregate([
            ['$match' => [$field => ['$exists' => true]]],
            ['$group' => ['_id' => '$' . $field, 'c' => ['$sum' => 1]]],
            ['$match' => ['c' => ['$gt' => 1]]],
        ])->toArray();

        return count($groups);
    }

    protected function reportDuplicateGroups(Collection $collection, string $field, int $top = 5): void
    {
        $groups = $collection->aggregate([
            ['$match' => [$field => ['$exists' => true]]],
            ['$group' => ['_id' => '$' . $field, 'c' => ['$sum' => 1]]],
            ['$match' => ['c' => ['$gt' => 1]]],
            ['$sort' => ['c' => -1]],
            ['$limit' => $top],
        ])->toArray();

        foreach ($groups as $group) {
            $this->line("      {$field}=" . json_encode($group->_id) . " x{$group->c}");
        }
    }

    /**
     * @return list<string>
     */
    protected function indexNames(Collection $collection): array
    {
        $names = [];

        foreach ($collection->listIndexes() as $index) {
            $names[] = $index->getName();
        }

        return $names;
    }
}
