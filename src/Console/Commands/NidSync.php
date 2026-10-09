<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Database;
use HZ\Illuminate\Mongez\Support\NidKeyRenamer;
use Illuminate\Console\Command;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database as MongoDatabase;

/**
 * Incremental delta sync between an old (`id`) and a new (`nid`) database.
 *
 * Two directions:
 *   forward  old(id)  -> new(nid)  : deep id->nid via NidKeyRenamer
 *   reverse  new(nid) -> old(id)   : deep nid->id via NidKeyRenamer::revert()
 *
 * Watermark: `--manifest` (from mongez:nid-snapshot) gives `generatedAt` plus
 * per-collection `maxObjectId`. `--since` (ISO8601) overrides the timestamp.
 * Query per collection: `updatedAt >= since OR _id > maxObjectId`.
 * Without a watermark the command copies every document (full sync).
 *
 * `ids` counters are max-merged forward; reversed they are overwritten from the
 * source (canonical single-row counters still work on old code).
 */
class NidSync extends Command
{
    protected const SKIP_EXACT = [
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

    protected const SKIP_PREFIXES = ['oauth_', 'system.'];

    protected const COUNTER_FIELDS = ['id', 'cid', 'idwe'];

    protected $signature = 'mongez:nid-sync
        {--from-uri= : MongoDB URI of the source database (required)}
        {--to-uri= : MongoDB URI of the target database (required)}
        {--direction=forward : forward (old id -> new nid) or reverse (new nid -> old id)}
        {--manifest= : Path to manifest JSON from mongez:nid-snapshot (provides since watermark and maxObjectId per collection)}
        {--since= : ISO8601 timestamp to use as updatedAt watermark (overrides manifest generatedAt; also accepts a manifest path)}
        {--collection=* : Limit sync to the given collection names}
        {--include-trash : Also sync *Trash collections}
        {--skip-path=* : Dotted path segments whose nested id/nid is opaque (e.g. providerResponse)}
        {--top-level-only : Rename only the top-level key, leaving nested and array keys alone}
        {--batch=500 : Documents per bulk write}
        {--ids-strategy= : How to sync the ids collection: max (merge) or overwrite (copy source). Defaults to max for forward, overwrite for reverse}
        {--delete-missing : Also delete documents in target that no longer exist in source (for the synced collections)}
        {--execute : Apply the sync instead of dry-run}';

    protected $description = 'Sync delta between old (id) and new (nid) databases by _id + updatedAt watermark, with id<->nid deep rename and ids counter merge';

    public function handle(): int
    {
        $fromRaw = $this->option('from-uri');
        $toRaw = $this->option('to-uri');
        $dirRaw = $this->option('direction');
        $fromUri = is_string($fromRaw) ? trim($fromRaw) : '';
        $toUri = is_string($toRaw) ? trim($toRaw) : '';
        $direction = strtolower(trim(is_string($dirRaw) ? $dirRaw : 'forward'));

        if ($fromUri === '' || $toUri === '') {
            $this->error('--from-uri and --to-uri are both required (hardcoded URIs, not config names).');

            return self::FAILURE;
        }

        if (! in_array($direction, ['forward', 'reverse'], true)) {
            $this->error('--direction must be forward or reverse.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $topLevelOnly = (bool) $this->option('top-level-only');
        $includeTrash = (bool) $this->option('include-trash');
        $deleteMissing = (bool) $this->option('delete-missing');
        $batch = max(1, (int) $this->option('batch'));

        $skipPaths = $this->skipPaths();
        $renamer = new NidKeyRenamer($skipPaths);

        // Watermark: manifest and/or --since. --since may itself be a manifest path.
        $manRaw = $this->option('manifest');
        $sinceRaw = $this->option('since');
        $manifestPath = is_string($manRaw) ? trim($manRaw) : '';
        $sinceOption = is_string($sinceRaw) ? trim($sinceRaw) : '';

        // Allow --since to be a manifest file for convenience.
        if ($sinceOption !== '' && is_file($sinceOption)) {
            $manifestPath = $manifestPath !== '' ? $manifestPath : $sinceOption;
            $sinceOption = '';
        }

        $manifest = null;
        $maxObjectIdMap = [];
        $sinceUtc = null;
        $sinceIso = null;

        if ($manifestPath !== '') {
            $manifest = $this->loadManifest($manifestPath);

            if ($manifest === null) {
                return self::FAILURE;
            }

            $maxObjectIdMap = $this->maxObjectIdMapFromManifest($manifest);

            if ($sinceOption === '' && isset($manifest['generatedAt'])) {
                $sinceOption = (string) $manifest['generatedAt'];
            }
        }

        if ($sinceOption !== '') {
            $parsed = $this->parseSinceToUtc($sinceOption);

            if ($parsed === null) {
                $this->error("Invalid --since timestamp: {$sinceOption} (expected ISO8601, e.g. 2026-10-04T10:00:00Z)");

                return self::FAILURE;
            }

            [$sinceUtc, $sinceIso] = $parsed;
        }

        $sourceDb = $this->resolveDatabase($fromUri, 'source');
        $targetDb = $this->resolveDatabase($toUri, 'target');

        if ($sourceDb->getDatabaseName() === $targetDb->getDatabaseName()
            && $this->sanitizeUri($fromUri) === $this->sanitizeUri($toUri)) {
            $this->error('Source and target resolve to the same database — refusing to sync a database to itself.');

            return self::FAILURE;
        }

        $targets = $this->targetCollections($sourceDb, $includeTrash);
        $hasCollectionFilter = count(array_values(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            (array) $this->option('collection'),
        )))) > 0;

        $idsRaw = $this->option('ids-strategy');
        $idsStrategy = is_string($idsRaw) ? trim($idsRaw) : '';
        if ($idsStrategy === '') {
            $idsStrategy = $direction === 'reverse' ? 'overwrite' : 'max';
        }
        if (! in_array($idsStrategy, ['max', 'overwrite'], true)) {
            $this->error('--ids-strategy must be max or overwrite.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'FROM: %s | TO: %s | direction: %s | %s | batch: %d',
            $sourceDb->getDatabaseName(),
            $targetDb->getDatabaseName(),
            $direction,
            $execute ? 'EXECUTE' : 'DRY RUN',
            $batch,
        ));

        if ($direction === 'forward') {
            $this->line('  transform: id -> nid ' . ($topLevelOnly ? '(top-level only)' : '(deep)'));
        } else {
            $this->line('  transform: nid -> id ' . ($topLevelOnly ? '(top-level only)' : '(deep)'));
        }

        if ($skipPaths !== []) {
            $this->line('  skip-paths: ' . implode(', ', $skipPaths));
        }

        if ($sinceIso !== null) {
            $this->line("  watermark: updatedAt >= {$sinceIso}" . ($maxObjectIdMap !== [] ? ' OR _id > manifest.maxObjectId' : ''));
        } elseif ($maxObjectIdMap !== []) {
            $this->line('  watermark: _id > manifest.maxObjectId (no timestamp)');
        } else {
            $this->warn('  watermark: none — full copy (pass --manifest or --since for delta)');
        }

        $this->line(sprintf('  ids strategy: %s', $idsStrategy));
        $this->line('  collections: ' . (empty($targets) ? '<none>' : implode(', ', $targets)));
        if ($deleteMissing) {
            $this->line('  delete-missing: enabled (target docs absent in source will be deleted)');
        }
        if ($hasCollectionFilter) {
            $this->line('  ids scope: synced collections only (due to --collection)');
        }

        if (! $execute) {
            $this->comment('Dry run — nothing is written. Pass --execute to apply.');
        }

        $totalUpserts = 0;
        $totalScanned = 0;

        foreach ($targets as $name) {
            $sourceColl = $sourceDb->selectCollection($name);
            $targetColl = $targetDb->selectCollection($name);

            $filter = $this->buildFilter($name, $sinceUtc, $maxObjectIdMap);

            $candidateCount = null;

            try {
                $candidateCount = $sourceColl->countDocuments($filter);
            } catch (\Throwable) {
                // Fall through — will still attempt find.
            }

            $filterDesc = $this->describeFilter($filter);

            if ($candidateCount !== null) {
                $this->line(sprintf('  %s: candidates=%d filter=%s', $name, $candidateCount, $filterDesc));

                if ($candidateCount === 0) {
                    continue;
                }
            } else {
                $this->line(sprintf('  %s: filter=%s', $name, $filterDesc));
            }

            $renamedKeys = 0;
            $droppedKeys = 0;
            $scanned = 0;
            $upserts = 0;
            $pending = [];

            try {
                $cursor = $sourceColl->find($filter, ['typeMap' => NidKeyRenamer::TYPE_MAP, 'batchSize' => $batch]);
            } catch (\Throwable $e) {
                $this->error("  {$name}: find failed — " . $e->getMessage());

                continue;
            }

            foreach ($cursor as $document) {
                if (! is_array($document)) {
                    continue;
                }

                $scanned++;

                if (! isset($document['_id'])) {
                    $this->warn("  {$name}: skipping document without _id");

                    continue;
                }

                $transformed = null;
                $stats = null;

                $isTrash = str_ends_with($name, 'Trash');

                if ($isTrash) {
                    // Trash is {primaryId, record:{...}, deletedAt} — record still uses `id` even in nid_final,
                    // so deep rename would diverge. Copy verbatim.
                    $transformed = $document;
                } elseif ($topLevelOnly) {
                    if ($direction === 'forward') {
                        $stats = $renamer->renameTopLevelDoc($document);
                    } else {
                        $stats = $renamer->revertTopLevelDoc($document);
                    }
                    $transformed = $stats['document'];
                    $renamedKeys += $stats['renamedKeys'];
                    $droppedKeys += $stats['droppedKeys'];
                    // Skip if no top-level change and we only care about renames?
                    // Still need to upsert if document delta exists: updatedAt/_id match means it changed.
                    // So always upsert matched candidates.
                } else {
                    if ($direction === 'forward') {
                        $stats = $renamer->rename($document);
                    } else {
                        $stats = $renamer->revert($document);
                    }
                    $transformed = $stats['document'];
                    $renamedKeys += $stats['renamedKeys'];
                    $droppedKeys += $stats['droppedKeys'];
                }

                if (! $execute) {
                    $upserts++;

                    continue;
                }

                $pending[] = [
                    'replaceOne' => [
                        ['_id' => $document['_id']],
                        $transformed,
                        ['upsert' => true],
                    ],
                ];

                $upserts++;

                if (count($pending) >= $batch) {
                    try {
                        $targetColl->bulkWrite($pending, ['ordered' => false]);
                    } catch (\Throwable $e) {
                        $this->error("  {$name}: bulkWrite failed — " . $e->getMessage());
                    }
                    $pending = [];
                }
            }

            if ($pending !== [] && $execute) {
                try {
                    $targetColl->bulkWrite($pending, ['ordered' => false]);
                } catch (\Throwable $e) {
                    $this->error("  {$name}: bulkWrite (tail) failed — " . $e->getMessage());
                }
            }

            $totalScanned += $scanned;
            $totalUpserts += $upserts;

            $this->line(sprintf(
                '  %s: scanned=%d upserts=%d renamedKeys=%d droppedKeys=%d%s',
                $name,
                $scanned,
                $upserts,
                $renamedKeys,
                $droppedKeys,
                $execute ? '' : ' (dry run)',
            ));
        }

        if ($deleteMissing) {
            $this->line("\n--- deletes (missing in source) ---");
            $totalDeletes = 0;
            foreach ($targets as $name) {
                if (str_ends_with($name, 'Trash')) {
                    $this->line("  {$name}: skipped (trash is append-only)");
                    continue;
                }
                $sourceColl = $sourceDb->selectCollection($name);
                $targetColl = $targetDb->selectCollection($name);
                // Quick check: if target count <= source count, still need diff but report.
                try {
                    $targetCount = $targetColl->countDocuments([]);
                    $sourceCount = $sourceColl->countDocuments([]);
                } catch (\Throwable $e) {
                    $this->warn("  {$name}: count failed — " . $e->getMessage());
                    continue;
                }
                if ($targetCount === 0) {
                    $this->line("  {$name}: target empty — nothing to delete");
                    continue;
                }
                // Collect source _ids into hash for fast lookup. For large collections, stream in batches.
                $sourceIds = [];
                try {
                    $cur = $sourceColl->find([], ['projection' => ['_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']]);
                    foreach ($cur as $doc) {
                        if (! isset($doc['_id'])) continue;
                        $id = $doc['_id'];
                        $key = $id instanceof ObjectId ? (string) $id : (is_string($id) ? $id : json_encode($id));
                        $sourceIds[$key] = true;
                    }
                } catch (\Throwable $e) {
                    $this->error("  {$name}: source _id scan failed — " . $e->getMessage());
                    continue;
                }
                // Scan target, collect missing
                $missing = [];
                try {
                    $cur = $targetColl->find([], ['projection' => ['_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']]);
                    foreach ($cur as $doc) {
                        if (! isset($doc['_id'])) continue;
                        $id = $doc['_id'];
                        $key = $id instanceof ObjectId ? (string) $id : (is_string($id) ? $id : json_encode($id));
                        if (! isset($sourceIds[$key])) {
                            $missing[] = $id;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->error("  {$name}: target _id scan failed — " . $e->getMessage());
                    continue;
                }
                $deleteCount = count($missing);
                $this->line(sprintf('  %s: target=%d source=%d missing=%d%s', $name, $targetCount, $sourceCount, $deleteCount, $execute ? '' : ' (would delete)'));
                $totalDeletes += $deleteCount;
                if ($deleteCount === 0 || ! $execute) {
                    continue;
                }
                // Batch deletes
                $batchIds = [];
                foreach ($missing as $oid) {
                    $batchIds[] = $oid;
                    if (count($batchIds) >= $batch) {
                        try {
                            $targetColl->deleteMany(['_id' => ['$in' => $batchIds]]);
                        } catch (\Throwable $e) {
                            $this->error("  {$name}: deleteMany failed — " . $e->getMessage());
                        }
                        $batchIds = [];
                    }
                }
                if ($batchIds !== []) {
                    try {
                        $targetColl->deleteMany(['_id' => ['$in' => $batchIds]]);
                    } catch (\Throwable $e) {
                        $this->error("  {$name}: deleteMany (tail) failed — " . $e->getMessage());
                    }
                }
            }
            $this->line(sprintf('  deletes total: %d%s', $totalDeletes, $execute ? ' EXECUTED' : ' (dry run)'));
        }

        // Sync ids counters afterwards, so target data max reflects freshly copied docs.
        $this->line("\n--- ids counters ---");
        $idsBlockers = $this->syncIds($sourceDb, $targetDb, $idsStrategy, $execute, $targets, $hasCollectionFilter);

        $this->line(sprintf(
            "\nTotal: scanned=%d upserts=%d (ids blockers: %d) %s",
            $totalScanned,
            $totalUpserts,
            $idsBlockers,
            $execute ? 'EXECUTED' : 'dry run — nothing written',
        ));

        if ($idsBlockers > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveDatabase(string $uri, string $label): MongoDatabase
    {
        $client = new Client($uri);
        $dbName = $this->extractDatabaseName($uri);

        if ($dbName !== null && $dbName !== '') {
            return $client->selectDatabase($dbName);
        }

        // Fall back to default connection's database name.
        return $client->selectDatabase(Database::getDatabase()->getDatabaseName());
    }

    private function extractDatabaseName(string $uri): ?string
    {
        $parsed = parse_url($uri);

        if (! is_array($parsed) || ! isset($parsed['path'])) {
            return null;
        }

        $path = ltrim($parsed['path'], '/');

        if ($path === '') {
            return null;
        }

        $path = explode('?', $path, 2)[0];
        $path = explode('/', $path, 2)[0];

        return $path !== '' ? $path : null;
    }

    private function sanitizeUri(string $uri): string
    {
        return preg_replace('#(//[^:/@]+:)([^@]+)(@)#', '$1***$3', $uri) ?? $uri;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, string|null>  collection => maxObjectId hex or null
     */
    private function maxObjectIdMapFromManifest(array $manifest): array
    {
        $map = [];
        $collections = $manifest['collections'] ?? [];

        if (! is_array($collections)) {
            return [];
        }

        foreach ($collections as $name => $info) {
            if (! is_array($info)) {
                continue;
            }
            $hex = $info['maxObjectId'] ?? null;
            $map[(string) $name] = is_string($hex) && $hex !== '' ? $hex : null;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadManifest(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("Manifest not found: {$path}");

            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            $this->error("Cannot read manifest: {$path}");

            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            $this->error("Invalid JSON in manifest: {$path}");

            return null;
        }

        $this->line("  manifest: {$path} (generatedAt=" . ($data['generatedAt'] ?? 'unknown') . ')');

        return $data;
    }

    /**
     * @return array{UTCDateTime,string}|null  [utc, iso]
     */
    private function parseSinceToUtc(string $since): ?array
    {
        $since = trim($since);

        if ($since === '') {
            return null;
        }

        // ctime may be milliseconds since epoch? Support numeric.
        if (is_numeric($since)) {
            $ms = (int) $since;
            // Heuristic: if seconds (10 digits) vs ms (13)
            if ($ms < 1000000000000) {
                $ms *= 1000;
            }

            return [new UTCDateTime($ms), $this->utcToIso(new UTCDateTime($ms))];
        }

        try {
            $dt = new \DateTimeImmutable($since);
        } catch (\Throwable) {
            return null;
        }

        $ms = (int) ($dt->getTimestamp() * 1000 + (int) $dt->format('v'));

        $utc = new UTCDateTime($ms);

        return [$utc, $dt->format('Y-m-d\TH:i:s.v\Z')];
    }

    private function utcToIso(UTCDateTime $utc): string
    {
        return $utc->toDateTime()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @return list<string>
     */
    private function skipPaths(): array
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
     * @return list<string>
     */
    private function targetCollections(MongoDatabase $sourceDb, bool $includeTrash): array
    {
        $selected = array_values(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            (array) $this->option('collection'),
        )));

        $names = $selected !== [] ? $selected : $this->listCollectionNames($sourceDb);

        $names = array_values(array_unique(array_filter($names, function (string $name): bool {
            if ($name === 'ids') {
                return false;
            }
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

        if (! $includeTrash) {
            $names = array_values(array_filter($names, static fn (string $name): bool => ! str_ends_with($name, 'Trash')));
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function listCollectionNames(MongoDatabase $db): array
    {
        $names = [];
        foreach ($db->listCollections() as $info) {
            $names[] = $info->getName();
        }

        return $names;
    }

    /**
     * @param  array<string, string|null>  $maxObjectIdMap
     * @return array<string, mixed>
     */
    private function buildFilter(string $collection, ?UTCDateTime $sinceUtc, array $maxObjectIdMap): array
    {
        $hex = $maxObjectIdMap[$collection] ?? null;
        $maxOid = null;

        if (is_string($hex) && $hex !== '') {
            try {
                $maxOid = new ObjectId($hex);
            } catch (\Throwable) {
                $maxOid = null;
            }
        }

        if ($sinceUtc !== null && $maxOid !== null) {
            return ['$or' => [
                ['updatedAt' => ['$gte' => $sinceUtc]],
                ['_id' => ['$gt' => $maxOid]],
            ]];
        }

        if ($sinceUtc !== null) {
            return ['updatedAt' => ['$gte' => $sinceUtc]];
        }

        if ($maxOid !== null) {
            return ['_id' => ['$gt' => $maxOid]];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function describeFilter(array $filter): string
    {
        if ($filter === []) {
            return '{} (full)';
        }

        // Lightweight description — avoid dumping heavy UTCDateTime/ObjectId objects as JSON if large.
        return json_encode($filter, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param  list<string>  $targets
     */
    private function syncIds(MongoDatabase $sourceDb, MongoDatabase $targetDb, string $strategy, bool $execute, array $targets, bool $hasCollectionFilter = false): int
    {
        $sourceIds = $sourceDb->selectCollection('ids');
        $targetIds = $targetDb->selectCollection('ids');

        // Read all source rows keyed by collection.
        $sourceMap = $this->collectIdsMap($sourceIds);
        $targetMap = $this->collectIdsMap($targetIds);

        // Universe of collections to consider: union of ids rows + data targets.
        // When --collection is scoped, limit ids to those collections only.
        $allNames = array_unique(array_merge(array_keys($sourceMap), array_keys($targetMap), $targets));
        if ($hasCollectionFilter) {
            $allow = array_flip($targets);
            $allNames = array_values(array_filter($allNames, static fn (string $n): bool => isset($allow[$n])));
        }
        sort($allNames);

        // Filter to those not skipped? Already targets filtered; for ids we also want to sync all ids rows though.
        $allNames = array_values(array_filter($allNames, function (string $name): bool {
            if (in_array($name, self::SKIP_EXACT, true)) {
                return false;
            }
            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return false;
                }
            }

            return true;
        }));

        if ($strategy === 'overwrite') {
            // Copy source rows verbatim (canonical form) — scoped when --collection given.
            foreach ($sourceMap as $name => $rows) {
                if ($hasCollectionFilter && ! in_array($name, $targets, true)) {
                    continue;
                }
                $max = $this->maxCounterFromRows($rows);
                // Find source canonical row value (first row's id if single, else max)
                $sourceValue = $max;

                // Also include dataMax check? For overwrite we just copy source counter.
                $this->line(sprintf(
                    '  ids[%s]: source=%d -> target%s',
                    $name,
                    $sourceValue,
                    $execute ? '' : ' (would overwrite)',
                ));

                if (! $execute) {
                    continue;
                }

                $targetIds->deleteMany(['collection' => $name]);

                if ($sourceValue > 0) {
                    $targetIds->insertOne(['collection' => $name, 'id' => $sourceValue]);
                }
            }

            // Also report ids in target that have no source entry (orphans) — leave them.
            return 0;
        }

        // Strategy max: merge to highest of counters and data maxes.
        $blockers = 0;

        foreach ($allNames as $name) {
            $sourceRows = $sourceMap[$name] ?? [];
            $targetRows = $targetMap[$name] ?? [];

            $sourceCounterMax = $this->maxCounterFromRows($sourceRows);
            $targetCounterMax = $this->maxCounterFromRows($targetRows);

            $sourceDataMax = $this->maxIdentity($sourceDb, $name);
            $targetDataMax = $this->maxIdentity($targetDb, $name);

            $final = max($sourceCounterMax, $targetCounterMax, $sourceDataMax, $targetDataMax);

            if ($final === 0) {
                continue;
            }

            $targetRowsCount = count($targetRows);
            $needsWrite = true;

            if ($targetRowsCount === 1) {
                $row = $targetRows[0];
                $extra = array_diff(array_keys($row), ['_id', 'collection', 'id']);
                $val = isset($row['id']) ? (int) $row['id'] : null;

                if ($extra === [] && $val === $final) {
                    $needsWrite = false;
                }
            }

            $detail = sprintf('counter src=%d tgt=%d data src=%d tgt=%d -> %d', $sourceCounterMax, $targetCounterMax, $sourceDataMax, $targetDataMax, $final);

            if (! $needsWrite) {
                $this->line("  ids[{$name}]: {$detail} (in place)");

                continue;
            }

            $this->line(sprintf(
                '  ids[%s]: %s%s',
                $name,
                $detail,
                $execute ? '' : ' (would merge)',
            ));

            if (! $execute) {
                continue;
            }

            try {
                $targetIds->deleteMany(['collection' => $name]);
                $targetIds->insertOne(['collection' => $name, 'id' => $final]);
            } catch (\Throwable $e) {
                $blockers++;
                $this->error("  ids[{$name}]: write failed — " . $e->getMessage());
            }
        }

        // Junk rows with empty collection key
        $junkFilter = ['$or' => [['collection' => ''], ['collection' => ['$exists' => false]]]];
        $junkCount = $targetIds->countDocuments($junkFilter);

        if ($junkCount > 0) {
            $this->line("  ids: {$junkCount} junk row(s) empty collection" . ($execute ? ' -> deleted' : ' (would delete)'));

            if ($execute) {
                $targetIds->deleteMany($junkFilter);
            }
        }

        if ($execute) {
            try {
                $targetIds->createIndex(['collection' => 1], ['unique' => true]);
            } catch (\Throwable $e) {
                $this->warn('  ids: unique index ensure failed — ' . $e->getMessage());
            }
        }

        return $blockers;
    }

    /**
     * @return array<string, list<array<string,mixed>>>
     */
    private function collectIdsMap(Collection $ids): array
    {
        $map = [];

        foreach ($ids->find([], ['typeMap' => ['root' => 'array', 'document' => 'array']]) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $row['collection'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }
            $map[$name][] = $row;
        }

        return $map;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function maxCounterFromRows(array $rows): int
    {
        $max = 0;

        foreach ($rows as $row) {
            foreach (self::COUNTER_FIELDS as $field) {
                $v = $row[$field] ?? null;
                if (is_numeric($v) && (int) $v > $max) {
                    $max = (int) $v;
                }
            }
        }

        return $max;
    }

    private function maxIdentity(MongoDatabase $db, string $collection): int
    {
        try {
            $result = $db->selectCollection($collection)->aggregate([
                ['$group' => ['_id' => null, 'nid' => ['$max' => '$nid'], 'id' => ['$max' => '$id']]],
            ])->toArray();
        } catch (\Throwable) {
            return 0;
        }

        if ($result === []) {
            return 0;
        }

        $doc = $result[0];
        // Handle both array and object results
        $nid = null;
        $id = null;

        if (is_array($doc)) {
            $nid = $doc['nid'] ?? null;
            $id = $doc['id'] ?? null;
        } elseif (is_object($doc)) {
            $nid = $doc->nid ?? null;
            $id = $doc->id ?? null;
        }

        return max(
            is_numeric($nid) ? (int) $nid : 0,
            is_numeric($id) ? (int) $id : 0,
        );
    }
}
