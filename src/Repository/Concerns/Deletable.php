<?php

namespace HZ\Illuminate\Mongez\Repository\Concerns;

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

        if ($this->isCacheable()) $this->forgetCache($model->getKey());

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
     * @return array
     */
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
        return static::USING_CACHE;
    }
}
