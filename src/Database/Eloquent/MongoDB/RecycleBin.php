<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent\MongoDB;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait RecycleBin
{
    /**
     * {@inheritDoc}
     */
    public function delete()
    {
        $recordInfo = $this->info();

        $trashTable = static::trashTable();

        $primaryId = $this->nid;

        DB::table($trashTable)->insert([
            'primaryId' => $primaryId,
            'record' => $recordInfo,
            'deletedBy' => ($user = user()) ? $user->sharedInfo() : null,
            'deletedAt' => now(),
        ]);

        parent::delete();
    }

    /**
     * Get the deleted records
     * 
     * @return Collection
     */
    public static function getDeleted()
    {
        $records = DB::table(static::trashTable())->pluck('record');

        return $records->map(fn($record) => new static((array) $record));
    }

    /**
     * Find the deleted record for the given id
     *
     * @param  int $id
     * @return static
     */
    public static function findDeleted($id)
    {
        $record = DB::table(static::trashTable())->where('primaryId', (int) $id)->first();

        if (!$record) return null;

        // mongodb/laravel-mongodb v5 returns documents as stdClass objects
        $recordData = is_array($record) ? $record['record'] : $record->record;

        return new static((array) $recordData);
    }

    /**
     * Restore all deleted records
     * 
     * @return Collection
     */
    public static function restoreAll()
    {
        $records = static::getDeleted();

        $restoredIds = [];

        foreach ($records as $record) {
            // re-insert the record again
            $record->save();
            // remove it from the trashed table
            $restoredIds[] = $record->nid;
        }

        DB::table(static::trashTable())->whereIn('primaryId', $restoredIds)->delete();

        return $records;
    }

    /**
     * Get trash table name
     * 
     * @return string
     */
    public static function trashTable()
    {
        return defined('static::TRASH_TABLE') ? static::TRASH_TABLE : static::getTableName() . 'Trash';
    }
}
