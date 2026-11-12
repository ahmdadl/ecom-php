<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Translation\Traits;

use Illuminate\Support\Str;

trait Translatable
{
    /**
     * Translate message from modules dynamically
     *
     * @param  mixed $method
     * @param  array<int, mixed> $args
     */
    public function __call($method, $args): mixed
    {
        if (!Str::startsWith($method, 'trans')) {
            $parent = get_parent_class($this);

            if ($parent !== false && method_exists($parent, '__call')) {
                return parent::__call($method, $args); // @phpstan-ignore class.noParent
            }

            throw new \BadMethodCallException("Method {$method} does not exist");
        }

        $moduleName = Str::replaceFirst('trans', '', $method);

        $fileName = array_shift($args);

        return trans($moduleName . '::' . $fileName, ...$args);
    }
}
