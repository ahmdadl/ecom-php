<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use Illuminate\Console\Command;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Database;

class MigrateNid extends Command
{
    protected $signature = 'mongez:migrate-nid
        {--execute : Apply the migration instead of performing a dry run}
        {--collection=* : Limit the migration to the given collection names}
        {--rebuild-counters : Rebuild ids counters from migrated documents}';

    protected $description = 'Migrate top-level MongoDB id fields to nid';

    public function handle(): int
    {
        $database = Database::getDatabase();
        $selected = array_filter((array) $this->option('collection'));
        $collections = $selected ?: array_map(
            static fn ($collection): string => $collection->getName(),
            iterator_to_array($database->listCollections())
        );

        foreach ($collections as $name) {
            if ($name === 'ids') {
                continue;
            }

            $collection = $database->selectCollection($name);
            $legacyCount = $collection->countDocuments(['id' => ['$exists' => true]]);
            $nidCount = $collection->countDocuments(['nid' => ['$exists' => true]]);

            $this->line(sprintf('%s: %d legacy id(s), %d nid(s)', $name, $legacyCount, $nidCount));

            if ($this->option('execute') && $legacyCount > 0) {
                $result = $collection->updateMany(
                    ['id' => ['$exists' => true], 'nid' => ['$exists' => false]],
                    [['$set' => ['nid' => '$id']], ['$unset' => 'id']]
                );

                $this->info(sprintf('Migrated %d document(s) in %s', $result->getModifiedCount(), $name));
            }
        }

        if ($this->option('rebuild-counters')) {
            if (! $this->option('execute')) {
                $this->warn('Counter rebuild skipped: pass --execute to allow mutations.');
            } else {
                $this->rebuildCounters($database, $collections);
            }
        }

        if (! $this->option('execute')) {
            $this->comment('Dry run only. Pass --execute to apply changes.');
        }

        return self::SUCCESS;
    }

    /** @param array<int, string> $collections */
    private function rebuildCounters(\MongoDB\Database $database, array $collections): void
    {
        $ids = $database->selectCollection('ids');

        // ensure corrupted empty-key counters do not shadow real collections
        $deletedEmpty = $ids->deleteMany(['collection' => '']);
        if ($deletedEmpty->getDeletedCount() > 0) {
            $this->warn(sprintf('Removed %d corrupted ids counter(s) with empty collection key', $deletedEmpty->getDeletedCount()));
        }

        // dedupe any duplicate collection keys (keep highest id)
        $duplicates = $ids->aggregate([
            ['$group' => ['_id' => '$collection', 'count' => ['$sum' => 1], 'maxId' => ['$max' => '$id']]],
            ['$match' => ['count' => ['$gt' => 1]]],
        ])->toArray();
        foreach ($duplicates as $dup) {
            $ids->deleteMany(['collection' => $dup->_id]);
            $ids->insertOne(['collection' => $dup->_id, 'id' => (int) $dup->maxId]);
            $this->warn(sprintf('Deduped ids counter for %s -> kept max id %d', $dup->_id, $dup->maxId));
        }

        $ids->createIndex(['collection' => 1], ['unique' => true]);

        foreach ($collections as $name) {
            if ($name === 'ids') {
                continue;
            }

            // max of nid if exists, else fallback to legacy id (pre-migration runs)
            $result = $database->selectCollection($name)->aggregate([
                ['$match' => ['nid' => ['$exists' => true]]],
                ['$group' => ['_id' => null, 'max' => ['$max' => '$nid']]],
            ])->toArray();

            if ($result === []) {
                $result = $database->selectCollection($name)->aggregate([
                    ['$match' => ['id' => ['$exists' => true]]],
                    ['$group' => ['_id' => null, 'max' => ['$max' => '$id']]],
                ])->toArray();
            } else {
                // also consider legacy docs not yet migrated – take overall max across both fields
                $legacyMax = $database->selectCollection($name)->aggregate([
                    ['$match' => ['id' => ['$exists' => true]]],
                    ['$group' => ['_id' => null, 'max' => ['$max' => '$id']]],
                ])->toArray();
                if ($legacyMax !== [] && (int) $legacyMax[0]->max > (int) $result[0]->max) {
                    $result[0]->max = $legacyMax[0]->max;
                }
            }

            if ($result === []) {
                $this->line(sprintf('%s: no nid/id found, skipping counter', $name));
                continue;
            }

            $max = (int) $result[0]->max;
            $existing = $ids->findOne(['collection' => $name]);

            // only bump forward – never regress counter if max < stored
            if ($existing && isset($existing['id']) && (int) $existing['id'] >= $max) {
                $this->line(sprintf('%s: existing ids=%d >= max=%d, keeping existing', $name, $existing['id'], $max));
                continue;
            }

            $ids->updateOne(
                ['collection' => $name],
                ['$set' => ['id' => $max]],
                ['upsert' => true]
            );
            $this->info(sprintf('%s: ids counter set to %d%s', $name, $max, $existing ? ' (updated)' : ' (created)'));
        }
    }
}
