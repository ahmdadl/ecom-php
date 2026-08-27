<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Providers\MongezServiceProvider;
use MongoDB\Laravel\MongoDBServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    /**
     * {@inheritDoc}
     */
    protected function getPackageProviders($app): array
    {
        $this->markMongezAsInstalled();

        return [
            MongoDBServiceProvider::class,
            MongezServiceProvider::class,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'mongodb');

        $app['config']->set('database.connections.mongodb', [
            'driver' => 'mongodb',
            'dsn' => env('DB_URI', 'mongodb://127.0.0.1:27017/mongez_test'),
            'database' => env('DB_DATABASE', 'mongez_test'),
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
    }

    /**
     * Mark mongez as installed to skip prepareForFirstTime() console boot steps
     *
     * @return void
     */
    protected function markMongezAsInstalled(): void
    {
        $mongezDirectory = storage_path('mongez');

        if (! is_dir($mongezDirectory)) {
            mkdir($mongezDirectory, 0755, true);
        }

        file_put_contents($mongezDirectory . '/mongez.json', json_encode([
            'installed' => true,
        ]));
    }

    /**
     * Drop the given collections from the test database
     *
     * @param string ...$collections
     * @return void
     */
    protected function dropCollections(string ...$collections): void
    {
        $database = $this->app['db']->connection('mongodb')->getMongoDB();

        foreach ($collections as $collection) {
            $database->dropCollection($collection);
        }
    }
}
