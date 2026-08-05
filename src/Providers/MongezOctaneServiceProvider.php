<?php

namespace HZ\Illuminate\Mongez\Providers;

use HZ\Illuminate\Mongez\Events\Events;
use HZ\Illuminate\Mongez\Helpers\Mongez;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use HZ\Illuminate\Mongez\Resources\JsonResourceManager;

class MongezOctaneServiceProvider extends ServiceProvider
{
    /**
     * Register the Octane listeners that keep the package state
     * isolated between requests, tasks and ticks.
     *
     * @return void
     */
    public function register()
    {
        $this->app->booted(function () {
            $this->app['events']->listen([
                RequestReceived::class,
                TaskReceived::class,
                TickReceived::class,
            ], function () {
                $this->resetApplicationState();
            });
        });
    }

    /**
     * Reset the package state before handling a new request.
     *
     * @return void
     */
    protected function resetApplicationState()
    {
        Mongez::reset();

        JsonResourceManager::reset();

        app(Events::class)->reset();

        $this->resetRepositoriesState();

        MongezServiceProvider::registerConfigEventsListeners();
    }

    /**
     * Reset the shared static state of all registered repositories.
     *
     * @return void
     */
    protected function resetRepositoriesState()
    {
        foreach (config('mongez.repositories', []) as $repositoryClass) {
            if (method_exists($repositoryClass, 'resetCurrentResource')) {
                $repositoryClass::resetCurrentResource();
            }
        }
    }
}
