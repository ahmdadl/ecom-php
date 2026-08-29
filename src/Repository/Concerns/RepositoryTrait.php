<?php

namespace HZ\Illuminate\Mongez\Repository\Concerns;

use Illuminate\Support\Str;

trait RepositoryTrait
{
    /**
     * Get repositories dynamically
     *
     * @param string $key
     * @return \HZ\Illuminate\Mongez\Repository\RepositoryInterface|mixed|null
     */
    public function __get($key): mixed
    {
        $repository = null;

        if (Str::endsWith($key, 'Repository')) {
            $repository = Str::replaceLast('Repository', '', $key);
        } else if (Str::endsWith($key, 'Repo')) {
            $repository = Str::replaceLast('Repo', '', $key);
        }

        if ($repository) {
            return repo($repository);
        }

        // check if the trait in a sub-class and the parent has __get method
        if (class_parents($this) && method_exists(get_parent_class($this), '__get')) {
            return parent::__get($key);
        }

        return null;
    }
}
