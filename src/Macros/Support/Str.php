<?php
namespace HZ\Illuminate\Mongez\Macros\Support;

use Illuminate\Support\Str as IlluminateStr;

/**
 * @mixin IlluminateStr
 */
class Str
{
    /**
     * Remove the first occurrence of the given needle from the object 
     * 
     * @param string $needle
     * @param string $object
     * @return \Closure
     */
    public static function removeFirst(string $needle = '', string $object = ''): \Closure
    {
        return fn(string $needle, string $object): string => static::replaceFirst($needle, '', $object);
    }
}
