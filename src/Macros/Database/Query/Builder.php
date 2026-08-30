<?php

namespace HZ\Illuminate\Mongez\Macros\Database\Query;

use Exception;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * @mixin EloquentBuilder<\Illuminate\Database\Eloquent\Model>
 */
class Builder
{
    /**
     * The model instance, available when this macro is bound to an Eloquent builder at runtime.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    /**
     * Get the next auto increment id of current table.
     *
     * @return \Closure
     */
    public function getNextId(): \Closure
    {
        return function () {
            // if the form property doesn't exist, then it means we're executing this 
            // method inside the Eloquent builder
            $table = $this->from ?? $this->model->getTable();

            $statements = DB::select("SHOW TABLE STATUS LIKE '{$table}'");

            if (!$statements) {
                throw new Exception(sprintf('Base table "%s" does not exist', $table));
            }

            return $statements[0]->Auto_increment;
        };
    }

    /**
     * A shorthand method for the `where like ` clause
     *
     * @param  string $column
     * @param  mixed $value
     * @return \Closure
     */
    public function whereLike(string $column = '', $value = null): \Closure
    {
        return fn(string $column, $value) => $this->where($column, 'LIKE', "%$value%");
    }

    /**
     * A shorthand method for the `or where like ` clause
     *
     * @param  string $column
     * @param  mixed $value
     * @return \Closure
     */
    public function orWhereLike(string $column = '', $value = null): \Closure
    {
        return fn(string $column, $value) => $this->orWhere($column, 'LIKE', "%$value%");
    }

    /**
     * Search for location near by the given coordinates for the given distance 
     *
     * @example: $this->whereLocationNear('location', [20,59221, 4], 20); // search in location column for the given [lat, lng] coordinates in 20 km radius
     * @example: $this->whereLocationNear('location', [20,59221, 4], 20, 'km'); // search in location column for the given [lat, lng] coordinates in 20 km radius
     * @example: $this->whereLocationNear('location', [20,59221, 4], 40, 'miles'); // search in location column for the given [lat, lng] coordinates in 40 miles radius
     * 
     * @param  string $column
     * @param  array<float> $coordinates
     * @param  float $distance
     * @param  string $distanceType
     * @return \Closure
     */
    public function whereLocationNear(string $column = '', array $coordinates = [], float $distance = 0.0, string $distanceType = 'km'): \Closure
    {
        return fn(string $column, array $coordinates, float $distance, string $distanceType = 'km') => $this->where($column, 'geoWithin', $this->locationNear($coordinates, $distance, $distanceType));
    }


    /**
     * Search for location near by the given coordinates for the given distance 
     *
     * @example: $this->whereLocationNear('location', [20,59221, 4], 20); // search in location column for the given [lat, lng] coordinates in 20 km radius
     * @example: $this->whereLocationNear('location', [20,59221, 4], 20, 'km'); // search in location column for the given [lat, lng] coordinates in 20 km radius
     * @example: $this->whereLocationNear('location', [20,59221, 4], 40, 'miles'); // search in location column for the given [lat, lng] coordinates in 40 miles radius
     * 
     * @param  string $column
     * @param  array<float> $coordinates
     * @param  float $distance
     * @param  string $distanceType
     * @return \Closure
     */
    public function orWhereLocationNear(string $column = '', array $coordinates = [], float $distance = 0.0, string $distanceType = 'km'): \Closure
    {
        return fn(string $column, array $coordinates, float $distance, string $distanceType = 'km') => $this->orWhere($column, 'geoWithin', $this->locationNear($coordinates, $distance, $distanceType));
    }

    /**
     * Get location near by the given options
     * It has to be private function so it can not be read when reading all class methods to inject method macros
     *
     * @param  array<float> $coordinates
     * @param  float $distance
     * @return array<string, mixed>
     */
    private function locationNear(array $coordinates, float $distance, string $distanceType): array
    {
        $distance = (float) $distance;
        $distanceInRadian = $distance;

        if ($distanceType === 'km') {
            $distanceInRadian = $distance / 6371;
        } elseif ($distanceType === 'miles') {
            $distanceInRadian = $distance / 3959;
        }

        // as coordinates are based in [lat, lng] structure
        // we need to swap the values to be [lng, lat] 
        // @see https://docs.mongodb.com/manual/reference/operator/query/centerSphere/#op._S_centerSphere
        // $lngLatCoordinates = [$coordinates[1], $coordinates[0]];    
        return [
            '$centerSphere' => [$coordinates, $distanceInRadian],
        ];
    }
}
