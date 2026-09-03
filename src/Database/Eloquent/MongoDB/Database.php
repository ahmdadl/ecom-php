<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent\MongoDB;

use Illuminate\Support\Facades\DB;
use MongoDB\Database as MongoDatabase;

class Database
{
    public static function getDatabase(?string $connection = null): MongoDatabase
    {
        /** @phpstan-ignore-next-line method.notFound */
        return DB::connection($connection ?: 'mongodb')->getDatabase();
    }

    /**
     * Get all collections list
     *
     * @return array<int, string>
     */
    public static function collectionsList(): array
    {
        $collections = [];

        /** @phpstan-ignore-next-line method.notFound */
        $collectionList = DB::connection()->getDatabase()->listCollections();

        foreach ($collectionList as $collection) {
            $collections[] = $collection->getName();
        }

        return $collections;
    }
}
