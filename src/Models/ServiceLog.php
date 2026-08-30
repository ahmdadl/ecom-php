<?php

namespace HZ\Illuminate\Mongez\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;

class ServiceLog extends Model
{
    /**
     * Log the given service data
     *
     * @param  array<string, mixed>|object $data
     * @return \Illuminate\Database\Eloquent\Model
     */
    public static function log(array|object $data): EloquentModel
    {
        $mapData = function ($data) use (&$mapData) {
            $details = [];

            foreach ((array) $data as $key => $value) {
                $details[Str::camel(str_replace('.', '_', $key))] = is_array($value) || is_object($value) ? $mapData((array) $value) : $value;
            }

            return $details;
        };

        $details = $mapData($data);

        return static::create($details); // @phpstan-ignore method.staticCall
    }
}
