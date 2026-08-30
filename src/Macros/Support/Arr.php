<?php

namespace HZ\Illuminate\Mongez\Macros\Support;

use Illuminate\Support\Arr as IlluminateArr;

/**
 * @mixin IlluminateArr
 */
class Arr
{
    /**
     * Remove from array by the given value 
     * 
     * @param  array<mixed> $array
     * @param  mixed $value
     * @param  bool $removeFirstOnly
     * @return \Closure
     */
    public static function remove(array $array = [], $value = null, bool $removeFirstOnly = false): \Closure
    {
        return function (array $array, $value, bool $removeFirstOnly = false): array {
            foreach ($array as $key => $arrayValue) {
                if ($value == $arrayValue) {
                    unset($array[$key]);
                    if ($removeFirstOnly) break;
                }
            }

            return $array;
        };
    }

    /**
     * Get the all values that are not duplicated in the given arrays
     * 
     * @param  mixed ...$arrays
     * @return \Closure
     */
    public static function outer(...$arrays): \Closure
    {
        return fn(...$arrays) => array_diff(array_merge(...$arrays), array_intersect(...$arrays));
    }
}
