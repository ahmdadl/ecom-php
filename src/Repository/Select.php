<?php

namespace HZ\Illuminate\Mongez\Repository;

use Illuminate\Support\Collection;

class Select
{
    /**
     * list
     *
     * @var \Illuminate\Support\Collection<int, mixed>
     */
    protected Collection $list;

    /**
     * Constructor
     *
     * @param array<int|string, mixed> $selectList
     */
    public function __construct(array $selectList = [])
    {
        // Avoid `new` in property defaults — unsupported on some PHP 8.4/8.5 builds
        // used by FrankenPHP workers ("New expressions are not supported in this context").
        /** @phpstan-ignore assign.propertyType */
        $this->list = new Collection($selectList);
    }

    /**
     * Add one or more column to list
     *
     * @param  mixed ...$columns
     */
    public function add(mixed ...$columns): self
    {
        $this->list = $this->list->merge($columns);

        return $this;
    }

    /**
     * Remove the given select column
     *
     * @param  mixed $column
     */
    public function remove($column): self
    {
        $this->list = $this->list->reject(fn ($item) => $item === $column)->values();

        return $this;
    }

    /**
     * Replace column
     *
     * @param  string $oldColumn
     * @param  mixed ...$newColumns
     */
    public function replace($oldColumn, ...$newColumns): self
    {
        if (!$this->has($oldColumn)) return $this;

        $this->remove($oldColumn);

        $this->add(...$newColumns);

        return $this;
    }

    /**
     * Determine if the given column exists or not
     */
    public function has(string $column): bool
    {
        return $this->list->contains($column);
    }

    /**
     * Return select list array
     *
     * @return array<int|string, mixed> $list
     */
    public function list(): array
    {
        return $this->list->toArray();
    }

    /**
     * Determine if the select list is empty
     */
    public function isEmpty(): bool
    {
        return $this->list->isEmpty();
    }

    /**
     * Determine if the select list is not empty
     */
    public function isNotEmpty(): bool
    {
        return $this->list->isNotEmpty();
    }
}
