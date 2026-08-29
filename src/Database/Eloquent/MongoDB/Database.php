<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent\MongoDB;

use Illuminate\Support\Facades\DB;

class Database
{
    /**
     * Get all collections list
     */
    public static function collectionsList(): array
    {
        $collections = [];

        $collectionList = DB::connection()->getDatabase()->listCollections();

        foreach ($collectionList as $collection) {
            $collections[] = $collection->getName();
        }

        return $collections;
    }
}
