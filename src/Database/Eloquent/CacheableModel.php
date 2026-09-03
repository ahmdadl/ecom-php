<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent;

use HZ\Illuminate\Mongez\Cache\ModelCacheManager;
use Illuminate\Database\Eloquent\Model;

trait CacheableModel
{
    /**
     * Boot cache hooks for the model.
     *
     * @return void
     */
    public static function bootCacheableModel(): void
    {
        static::saved(function (Model $model) {
            if (static::isCachableModel()) {
                app(ModelCacheManager::class)->put($model);
            }
        });

        static::deleted(function (Model $model) {
            if (static::isCachableModel()) {
                app(ModelCacheManager::class)->forget($model);
            }
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                if (static::isCachableModel()) {
                    app(ModelCacheManager::class)->put($model);
                }
            });
        }
    }

    /**
     * Determine whether caching is enabled for this model.
     *
     * @return bool
     */
    public static function isCachableModel(): bool
    {
        return app(ModelCacheManager::class)->isEnabled(static::class);
    }

    /**
     * Cache-first find by nid.
     *
     * @param int $id
     * @return static|null
     */
    public static function findCached(int $id): ?static
    {
        $model = app(ModelCacheManager::class)->rememberById(static::class, $id, function () use ($id): ?Model {
            /** @var Model|null $model */
            $model = static::query()->where('nid', $id)->first();

            return $model;
        });

        return $model instanceof static ? $model : null;
    }

    /**
     * Alias for findCached.
     *
     * @param int $id
     * @return static|null
     */
    public static function getCached(int $id): ?static
    {
        return static::findCached($id);
    }

    /**
     * Cache-first lookup by an alternate column.
     *
     * @param string $column
     * @param mixed $value
     * @return static|null
     */
    public static function findByCached(string $column, mixed $value): ?static
    {
        $model = app(ModelCacheManager::class)->rememberByColumn(static::class, $column, $value, function () use ($column, $value): ?Model {
            /** @var Model|null $model */
            $model = static::query()->where($column, $value)->first();

            return $model;
        });

        return $model instanceof static ? $model : null;
    }

    /**
     * Static: forget cache keys for a single nid.
     *
     * @param int $nid
     * @return void
     */
    public static function invalidateCache(int $nid): void
    {
        app(ModelCacheManager::class)->forgetById(static::class, $nid);
    }

    /**
     * Static: forget cache keys for a list of nids.
     *
     * @param array<int> $nids
     * @return void
     */
    public static function invalidateCacheByIds(array $nids): void
    {
        app(ModelCacheManager::class)->forgetByIds(static::class, $nids);
    }

    /**
     * Static: forget cache by alternate column value.
     *
     * @param string $column
     * @param mixed $value
     * @return void
     */
    public static function invalidateCacheBy(string $column, mixed $value): void
    {
        app(ModelCacheManager::class)->forgetByColumn(static::class, $column, $value);
    }

    /**
     * Static: invalidate all cached records for this model.
     *
     * @return void
     */
    public static function invalidateAll(): void
    {
        app(ModelCacheManager::class)->invalidateAll(static::class);
    }

    /**
     * Instance: forget this model's cache and reload it from DB.
     *
     * @return $this
     */
    public function invalidateModelCache(): static
    {
        $this->forgetModelCache();

        $this->refresh();

        return $this;
    }

    /**
     * Write the current in-memory attributes to cache.
     *
     * @return $this
     */
    public function refreshModelCache(): static
    {
        app(ModelCacheManager::class)->put($this);

        return $this;
    }

    /**
     * Forget this model's cache keys without reloading.
     *
     * @return $this
     */
    public function forgetModelCache(): static
    {
        app(ModelCacheManager::class)->forget($this);

        return $this;
    }

    /**
     * Override Eloquent refresh to also update cache after reload.
     *
     * @param string ...$attributes
     * @return $this
     */
    public function refresh(string ...$attributes): static
    {
        parent::refresh(...$attributes);

        if (static::isCachableModel()) {
            app(ModelCacheManager::class)->put($this);
        }

        return $this;
    }

    /**
     * Override Eloquent fresh to also cache the freshly loaded model.
     *
     * @param mixed $with
     * @return static|null
     */
    public function fresh($with = []): ?static
    {
        /** @var static|null $model */
        $model = parent::fresh($with);

        if ($model && static::isCachableModel()) {
            app(ModelCacheManager::class)->put($model);
        }

        return $model;
    }
}
