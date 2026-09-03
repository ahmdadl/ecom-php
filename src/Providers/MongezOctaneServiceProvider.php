<?php

namespace HZ\Illuminate\Mongez\Providers;

use HZ\Illuminate\Mongez\Events\Events;
use HZ\Illuminate\Mongez\Helpers\Mongez;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelEvents;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelTrait;
use HZ\Illuminate\Mongez\Cache\ModelCacheManager;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use HZ\Illuminate\Mongez\Resources\JsonResourceManager;

class MongezOctaneServiceProvider extends ServiceProvider
{
    /**
     * Cached list of declared classes that use the ModelTrait.
     */
    /** @var array<int, class-string> */
    protected static array $modelClasses = [];

    /**
     * Number of declared classes when the model classes list was last built.
     *
     * Declared classes never decrease within a worker process, so an equal
     * count means the cached list is still complete.
     */
    protected static int $declaredClassesCount = -1;

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
            Mongez::snapshotBaseState();

            app(Events::class)->snapshotBaseState();

            JsonResourceManager::snapshotBaseState();

            $this->app['events']->listen(RequestReceived::class, function () { // @phpstan-ignore offsetAccess.nonOffsetAccessible
                $this->resetApplicationState();
            });

            $this->app['events']->listen(RequestTerminated::class, function () { // @phpstan-ignore offsetAccess.nonOffsetAccessible
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

        ModelCacheManager::resetRepositoryMap();
    }

    /**
     * Reset the shared static state of all loaded models.
     *
     * @return void
     */
    protected function resetModelsState()
    {
        $this->discoverModelClasses();

        foreach (static::$modelClasses as $class) {
            $class::resetStaticState();

            if (in_array(ModelEvents::class, class_uses_recursive($class), true)) {
                $class::resetState();
            }
        }
    }

    /**
     * Build a cached list of declared classes that use the ModelTrait.
     *
     * Scanning all declared classes with `class_uses_recursive` on every
     * request is expensive when the application loads many classes, so the
     * list is built once and only rebuilt when new classes get declared
     * (models may be autoloaded lazily after the first request).
     *
     * @return void
     */
    protected function discoverModelClasses()
    {
        $declaredClassesCount = count(get_declared_classes());

        if ($declaredClassesCount === static::$declaredClassesCount) return;

        static::$declaredClassesCount = $declaredClassesCount;
        static::$modelClasses = [];

        foreach (get_declared_classes() as $class) {
            if (in_array(ModelTrait::class, class_uses_recursive($class), true)) {
                static::$modelClasses[] = $class;
            }
        }
    }
}
