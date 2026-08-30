<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Database\Eloquent;

use Illuminate\Database\Eloquent\Builder;

/** @phpstan-ignore-next-line trait.unused */
trait HasPublishedScope
{
    /**
     * Scope a query to only include published records.
     */
    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     */
    public function scopePublished(Builder $query, string $status = 'published'): void
    {
        $query->where($status, true);
    }

    /**
     * Scope a query to exclude published records.
     */
    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     */
    public function scopeNotPublished(Builder $query, string $status = 'published'): void
    {
        $query->where($status, false)->orWhereNull($status);
    }

    /**
     * Scope a query to find a published record.
     */
    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     */
    public function findPublished(Builder $query, int $id, string $status = 'published'): void
    {
        $query->where($status, true)->where('nid', $id);
    }
}
