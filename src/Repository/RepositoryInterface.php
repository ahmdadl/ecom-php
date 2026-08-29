<?php

namespace HZ\Illuminate\Mongez\Repository;

use Illuminate\Support\Collection;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
interface RepositoryInterface
{
    /**
     * Create new record
     *
     * @param  \Illuminate\Http\Request|array $data
     * @return TModel
     */
    public function create($data);

    /**
     * Update a the given record id or model
     *
     * @param  int|\Illuminate\Database\Eloquent\Model $id
     * @param  \Illuminate\Http\Request|array $data
     * @return TModel|null
     */
    public function update($id, $data);

    /**
     * Delete a specific record
     *
     * @param  int|\Illuminate\Database\Eloquent\Model $id
     * @return bool
     */
    public function delete($id): bool;

    /**
     * Return List of records
     *
     * @param  array $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function list(array $option): Collection;

    /**
     * Get a specific record with full details
     *
     * @param  int $id
     * @return TModel|null
     */
    public function get(int $id);

    /**
     * Determine whether the given value exists
     *
     * @param  mixed   $value
     * @param  string  $column
     * @return bool
     */
    public function has($value, string $column): bool;

    /**
     * Get the query handler
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getQuery();
}
