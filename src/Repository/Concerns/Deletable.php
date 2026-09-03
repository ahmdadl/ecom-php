<?php

namespace HZ\Illuminate\Mongez\Repository\Concerns;

use HZ\Illuminate\Mongez\Cache\ModelCacheManager;

/**
 * @phpstan-require-extends \HZ\Illuminate\Mongez\Repository\RepositoryManager
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
trait Deletable
{
    /**
     * Dependency tables of deleting
     *
     * @var array<int|string, mixed>
     */
    protected $deleteDependenceTables = [];

    /**
     * {@inheritDoc}
     */
    public function delete($model): bool
    {
        $model = $this->getModel($model);

        if (!$model) return false;

        if ($this->trigger("deleting", $model, $model->getKey()) === false) return false;

        // delete uploaded files
        foreach (static::UPLOADS as $file) {
            if (!$model->$file) continue;

            if (is_array($model->$file)) {
                foreach ($model->$file as $singleFile) {
                    $this->unlink($singleFile);
                }
            } else {
                $this->unlink($model->$file);
            }
        }

        $model->delete();

        // Cache invalidation is handled by the model's deleted event (CacheableModel).

        $this->trigger("delete", $model, $model->getKey());

        return true;
    }

    /**
     * Check if model has deleting depended tables.
     */
    public function deleteHasDependence(): bool
    {
        return !empty($this->deleteDependenceTables);
    }

    /**
     * Get model deleting depended tables
     *
     * @return array<int|string, mixed>
     */
    public function getDeleteDependencies(): array
    {
        return $this->deleteDependenceTables;
    }

    /**
     * Check if soft delete used or not
     */
    public function isUsingSoftDelete(): bool
    {
        return static::USING_SOFT_DELETE;
    }

    /**
     * Check if cache is used or not
     */
    public function isCacheable(): bool
    {
        /** @var class-string<TModel> $modelClass */
        $modelClass = static::MODEL;

        return app(ModelCacheManager::class)->isEnabled($modelClass);
    }
}
