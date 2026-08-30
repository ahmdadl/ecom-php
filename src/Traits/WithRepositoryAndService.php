<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Traits;

use Exception;
use HZ\Illuminate\Mongez\Repository\Concerns\RepositoryTrait;

trait WithRepositoryAndService
{
    use RepositoryTrait {
        RepositoryTrait::__get as getRepository;
    }

    use WithService {
        WithService::__get as getService;
    }

    /**
     * {@inheritDoc}
     */
    public function __get(mixed $key): mixed
    {
        $return = $this->getService($key);

        if (!$return) {
            $return = $this->getRepository($key);
        }

        if ($return) return $return;

        // check if the trait in a sub-class and the parent has __get method
        $parent = get_parent_class($this);
        if ($parent !== false && method_exists($parent, '__get')) {
            return parent::__get($key); // @phpstan-ignore class.noParent
        }

        throw new Exception(sprintf('Call to undefined property %s', $key));
    }
}
