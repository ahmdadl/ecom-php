<?php

namespace HZ\Illuminate\Mongez\Console\Traits;

trait DatabaseConcerns
{
    /**
     * Current database name
     */
    protected string $databaseName;

    /**
     * Prepare database
     * 
     * @return void
     */
    protected function prepareDatabase()
    {
        $this->databaseName = config('database.default');
    }

    /**
     * Determine if current database is MongoDB
     */
    protected function isMongoDB(): bool
    {
        return $this->databaseName === 'mongodb';
    }

    /**
     * Determine if current database is MySQL
     */
    protected function isMySQL(): bool
    {
        return $this->databaseName === 'mysql';
    }

    /**
     * Get database name in all lower case
     */
    protected function databaseName(): string
    {
        return strtolower($this->databaseName);
    }

    /**
     * Get database name in well formatted string
     * i.e MongoDB | MYSQL...etc
     */
    protected function getDatabaseName(): string
    {
        return $this->isMongoDB() ? 'MongoDB' : 'MYSQL';
    }
}
