<?php

namespace HZ\Illuminate\Mongez\Console\Commands;

use Illuminate\Console\Command;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Database;

class NidHealth extends Command
{
    protected $signature = 'mongez:nid-health';

    protected $description = 'Check MongoDB collections for nid consistency';

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
            $missing = $collection->countDocuments(['nid' => ['$exists' => false]]);
            $legacy = $collection->countDocuments(['id' => ['$exists' => true]]);
            $duplicates = $collection->aggregate([
                ['$match' => ['nid' => ['$exists' => true]]],
                ['$group' => [
                    '_id' => '$nid',
                    'count' => ['$sum' => 1],
                ]],
                ['$match' => ['count' => ['$gt' => 1]]],
                ['$count' => 'duplicates'],
            ])->toArray();
            $duplicateCount = $duplicates === [] ? 0 : (int) $duplicates[0]->duplicates;

            if ($missing || $legacy || $duplicateCount) {
                $failed = true;
                $this->error(sprintf(
                    '%s: missing=%d legacy=%d duplicate_nids=%d',
                    $name,
                    $missing,
                    $legacy,
                    $duplicateCount
                ));
            } else {
                $this->info("{$name}: healthy");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
