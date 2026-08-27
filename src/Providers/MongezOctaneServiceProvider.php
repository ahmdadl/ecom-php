<?php

namespace HZ\Illuminate\Mongez\Providers;

use HZ\Illuminate\Mongez\Events\Events;
use HZ\Illuminate\Mongez\Helpers\Mongez;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelEvents;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelTrait;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use HZ\Illuminate\Mongez\Resources\JsonResourceManager;

class MongezOctaneServiceProvider extends ServiceProvider
{
    /**
     * Register the Octane listeners that keep the package state
     * isolated between HTTP requests.
     *
     * @return void
     */
    public function register()
    {
        $this->app->booted(function () {
            // capture the boot-time registered listeners and resource keys
            // so they survive the per-request state reset
            app(Events::class)->snapshotBaseState();

            JsonResourceManager::snapshotBaseState();

            $this->app['events']->listen(RequestReceived::class, function () {
                $this->resetApplicationState();
            });

            $this->app['events']->listen(RequestTerminated::class, function () {
                $this->cleanupApplicationState();
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

        $this->resetModelsState();

        ModelEvents::resetState();
    }

    /**
     * Defensive cleanup after the request has been handled.
     *
     * @return void
     */
    protected function cleanupApplicationState()
    {
        // roll back any transaction that was left open during the request
        if (DB::transactionLevel() > 0) {
            DB::rollBack(DB::transactionLevel());
        }
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

    /**
     * Reset the shared static state of all loaded models.
     *
     * @return void
     */
    protected function resetModelsState()
    {
        foreach (get_declared_classes() as $class) {
            if (in_array(ModelTrait::class, class_uses_recursive($class), true)) {
                $class::resetStaticState();
            }
        }
    }
}
