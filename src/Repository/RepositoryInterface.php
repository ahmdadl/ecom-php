<?php

declare(strict_types=1);

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
     * @param  \Illuminate\Http\Request|array<string, mixed> $data
     * @return TModel
     */
    public function create($data);

    /**
     * Update a the given record id or model
     *
     * @param  int|\Illuminate\Database\Eloquent\Model $id
     * @param  \Illuminate\Http\Request|array<string, mixed> $data
     * @return TModel|null
     */
    public function update($id, $data);

    /**
     * Delete a specific record
     *
     * @param  int|\Illuminate\Database\Eloquent\Model $id
     */
    public function delete($id): bool;

    /**
     * Return List of records
     *
     * @param  array<string, mixed> $option
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function list(array $option): Collection;

    /**
     * Get a specific record with full details
     *
     * @return TModel|null
     */
    public function get(int $id);

    /**
     * Determine whether the given value exists
     *
     * @param  mixed   $value
     */
    public function has($value, string $column): bool;

    /**
     * Get the query handler
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function getQuery();
}
