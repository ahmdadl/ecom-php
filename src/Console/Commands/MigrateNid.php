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

        foreach ($collections as $name) {
            if ($name === 'ids') {
                continue;
            }

            $result = $database->selectCollection($name)->aggregate([
                ['$match' => ['nid' => ['$exists' => true]]],
                ['$group' => ['_id' => null, 'max' => ['$max' => '$nid']]],
            ])->toArray();

            if ($result === []) {
                continue;
            }

            $ids->updateOne(
                ['collection' => $name],
                ['$set' => ['id' => (int) $result[0]->max]],
                ['upsert' => true]
            );
        }
    }
}
