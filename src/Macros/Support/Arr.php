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
     * @param  mixed $value
     * @param  array $array
     * @param  bool $removeFirstOnly
     * @return array
     */
    public static function remove()
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
     * @param  ...$arrays
     * @return array 
     */
    public static function outer()
    {
        return fn(...$arrays) => array_diff(array_merge(...$arrays), array_intersect(...$arrays));
    }
}
