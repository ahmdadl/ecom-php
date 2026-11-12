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

        if ($this->trigger("deleting", $model, $model->nid ?? $model->getKey()) === false) return false;

        // delete uploaded files
        foreach (static::UPLOADS as $upload) {
            $column = is_array($upload) ? ($upload['column'] ?? $upload['input'] ?? null) : $upload;

            if (!is_string($column) || $column === '') continue;

            $stored = $model->$column ?? null;

            if (!$stored) continue;

            $this->unlinkStoredUpload($stored);
        }

        $model->delete();

        // Cache invalidation is handled by the model's deleted event (CacheableModel).

        $this->trigger("delete", $model, $model->getKey());

        return true;
    }

    /**
     * Remove stored upload value(s), tolerating multi-file and metadata shapes.
     *
     * @param mixed $stored
     */
    protected function unlinkStoredUpload(mixed $stored): void
    {
        if (is_string($stored)) {
            $this->unlink($stored);

            return;
        }

        if (is_array($stored)) {
            $entries = array_is_list($stored) ? $stored : [$stored];

            foreach ($entries as $entry) {
                if (is_string($entry)) {
                    $this->unlink($entry);
                } elseif (is_array($entry)) {
                    $path = $entry['path'] ?? $entry['file'] ?? $entry['relativePath'] ?? null;

                    if (is_string($path) && $path !== '') {
                        $this->unlink($path);
                    }
                }
            }
        }
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
