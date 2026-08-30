<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent\MongoDB;

use Illuminate\Support\Facades\DB;

class Database
{
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
