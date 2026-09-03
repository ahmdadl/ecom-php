<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use Illuminate\Console\Command;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Database;

class EnsureNidIndexes extends Command
{
    protected $signature = 'mongez:ensure-nid-indexes
        {--execute : Create missing indexes}';

    protected $description = 'Check or create MongoDB nid indexes';

    public function handle(): int
    {
        $database = Database::getDatabase();
        $failed = false;

        foreach ($database->listCollections() as $metadata) {
            $name = $metadata->getName();

            if ($name === 'ids') {
                continue;
            }

            $collection = $database->selectCollection($name);
            $nidIndex = null;

            foreach ($collection->listIndexes() as $index) {
                $keys = $index->getKey();
                if (isset($keys['nid']) && $keys['nid'] === 1) {
                    $nidIndex = $index;
                    break;
                }
            }

            if ($nidIndex !== null && $nidIndex->isUnique()) {
                $this->info("{$name}: unique nid index exists");
                continue;
            }

            $duplicates = $collection->aggregate([
                ['$match' => ['nid' => ['$exists' => true]]],
                ['$group' => [
                    '_id' => '$nid',
                    'count' => ['$sum' => 1],
                ]],
                ['$match' => ['count' => ['$gt' => 1]]],
                ['$limit' => 1],
            ])->toArray();

            if ($duplicates !== []) {
                $failed = true;
                $this->error("{$name}: duplicate nid values found; resolve them before creating a unique index");
                continue;
            }

            if ($nidIndex !== null) {
                $this->warn("{$name}: non-unique nid index must be replaced");
            } else {
                $this->warn("{$name}: unique nid index is missing");
            }

            if ($this->option('execute')) {
                try {
                    if ($nidIndex !== null) {
                        $collection->dropIndex($nidIndex->getName());
                    }

                    $collection->createIndex(['nid' => 1], ['unique' => true]);
                    $this->info("{$name}: unique nid index created");
                } catch (\Throwable $exception) {
                    $failed = true;
                    $this->error("{$name}: unable to create unique nid index: {$exception->getMessage()}");
                }
            }
        }

        if (! $this->option('execute')) {
            $this->comment('Dry run only. Pass --execute to create indexes.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
