<?php
namespace HZ\Illuminate\Mongez\Macros\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection as IlluminateCollection;

/**
 * @mixin IlluminateCollection<int|string, mixed>
 */
class Collection
{
    /**
     * The collection items, available when this macro is bound to a collection at runtime.
     *
     * @var array<int, mixed>
     */
    protected $items;

    /**
     * Execute the given callback on the collection items without returning new collection
     * 
     * @param callable $callback
     * @return \Closure
     */
    public function walk(?callable $callback = null): \Closure
    {
        return function ($callback) {
            array_walk($this->items, $callback);
        };
    }

    /**
     * Add one ore element to the beginning of the collection
     * 
     * @param  mixed ...$value
     * @return \Closure
     */
    public function unshift(...$value): \Closure
    {
        return function (...$value) {
            array_unshift($this->items, ...$value);
        };
    }

    /**
     * Remove from the collection the given value
     * 
     * @param  mixed $value
     * @param  bool $removeFirstOnly
     * @return \Closure
     */
    public function remove($value = null, bool $removeFirstOnly = false): \Closure
    {
        return function ($value, bool $removeFirstOnly = false) {
            $this->items = Arr::remove($value, $this->items, $removeFirstOnly); // @phpstan-ignore staticMethod.notFound
        };
    }
}