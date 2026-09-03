<?php

namespace HZ\Illuminate\Mongez\Repository\Concerns;

use HZ\Illuminate\Mongez\Cache\ModelCacheManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Repository cache helpers.
 *
 * Write-side revalidation is handled by model events (CacheableModel trait).
 * This trait provides repository read helpers and thin wrappers for the
 * manual invalidation API.
 *
 * @phpstan-require-extends \HZ\Illuminate\Mongez\Repository\RepositoryManager
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
trait Cacheable
{
    /**
     * Get the model class for this repository.
     *
     * @return class-string<TModel>
     */
    protected function getModelClass(): string
    {
        /** @var class-string<TModel> $model */
        $model = static::MODEL;

        return $model;
    }

    /**
     * Get a model from cache by nid, or resolve it with the given callback.
     *
     * @param int $nid
     * @param callable(): ?TModel $resolver
     * @return TModel|null
     */
    public function getCachedModel(int $nid, callable $resolver): ?Model
    {
        return $this->cacheManager()->rememberById($this->getModelClass(), $nid, $resolver);
    }

    /**
     * Get a model from cache by alternate column, or resolve it with the given callback.
     *
     * @param string $column
     * @param mixed $value
     * @param callable(): ?TModel $resolver
     * @return TModel|null
     */
    public function getCachedModelBy(string $column, mixed $value, callable $resolver): ?Model
    {
        return $this->cacheManager()->rememberByColumn($this->getModelClass(), $column, $value, $resolver);
    }

    /**
     * Forget cache for a single nid.
     *
     * @param int $nid
     * @return void
     */
    public function invalidateCache(int $nid): void
    {
        $this->cacheManager()->forgetById($this->getModelClass(), $nid);
    }

    /**
     * Forget cache for a list of nids.
     *
     * @param array<int> $nids
     * @return void
     */
    public function invalidateCacheByIds(array $nids): void
    {
        $this->cacheManager()->forgetByIds($this->getModelClass(), $nids);
    }

    /**
     * Forget cache for an alternate column value.
     *
     * @param string $column
     * @param mixed $value
     * @return void
     */
    public function invalidateCacheBy(string $column, mixed $value): void
    {
        $this->cacheManager()->forgetByColumn($this->getModelClass(), $column, $value);
    }

    /**
     * Invalidate all cached records for this repository's model.
     *
     * @return void
     */
    public function invalidateAll(): void
    {
        $this->cacheManager()->invalidateAll($this->getModelClass());
    }

    /**
     * Get the cache manager instance.
     *
     * @return ModelCacheManager
     */
    protected function cacheManager(): ModelCacheManager
    {
        return app(ModelCacheManager::class);
    }

    /**
     * Determine if caching is enabled for this repository's model.
     *
     * @return bool
     */
    protected function isCachable(): bool
    {
        return $this->cacheManager()->isEnabled($this->getModelClass());
    }
}
