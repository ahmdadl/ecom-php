<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Translation\Traits;

use Illuminate\Support\Str;

trait Translatable
{
    /**
     * Translate message from modules dynamically
     *
     * @param  array<int, mixed> $args
     * @return mixed
     */
    public function __call($method, $args): mixed
    {
        if (!Str::startsWith($method, 'trans')) {
            $parent = get_parent_class($this);

            if ($parent !== false && method_exists($parent, '__call')) {
                /** @var callable $callback */
                $callback = [$parent, '__call'];

                return call_user_func_array($callback, $args);
            }

            return null;
        }

        $moduleName = Str::replaceFirst('trans', '', $method);

        $fileName = array_shift($args);

        return trans($moduleName . '::' . $fileName, ...$args);
    }
}
