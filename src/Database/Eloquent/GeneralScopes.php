<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Database\Eloquent;

use Illuminate\Database\Eloquent\Builder;

trait GeneralScopes
{
    /**
     * Scope to find by another record
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     */
    public function scopeFindBy(Builder $query, string $column, mixed $value, string $sign = '='): void
    {
        $query->where($column, $sign, $value);
    }

    /**
     * Scope to find by user id
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     */
    public function scopeFor(Builder $query, int $id, string $key = 'user'): void
    {
        $query->where("{$key}.nid", $id);
    }
}
