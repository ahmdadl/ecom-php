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

        foreach ($database->listCollections() as $metadata) {
            $name = $metadata->getName();

            if ($name === 'ids') {
                continue;
            }

            $collection = $database->selectCollection($name);
            $hasIndex = false;

            foreach ($collection->listIndexes() as $index) {
                $keys = $index->getKey();
                if (isset($keys['nid']) && $keys['nid'] === 1) {
                    $hasIndex = true;
                    break;
                }
            }

            if ($hasIndex) {
                $this->info("{$name}: nid index exists");
                continue;
            }

            $this->warn("{$name}: nid index is missing");

            if ($this->option('execute')) {
                $collection->createIndex(['nid' => 1]);
                $this->info("{$name}: nid index created");
            }
        }

        if (! $this->option('execute')) {
            $this->comment('Dry run only. Pass --execute to create indexes.');
        }

        return self::SUCCESS;
    }
}
