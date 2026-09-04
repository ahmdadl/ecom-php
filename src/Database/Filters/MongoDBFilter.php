<?php

namespace HZ\Illuminate\Mongez\Database\Filters;

class MongoDBFilter extends Filter
{
    /**
     * {@inheritDoc}
     */
    const NO_SQL_FILTER_MAP = [
        'inBool' => 'filterInBoolean',
        'inBoolean' => 'filterInBoolean',
        'notInBool' => 'filterNotInBoolean',
        'notInBoolean' => 'filterNotInBoolean',
        'inFloat' => 'filterInFloat',
        'notInFloat' => 'filterNotInFloat',
        'int' => 'filterInt',
        'float' => 'filterFloat',
        'bool' => 'filterBoolean',
        'boolean' => 'filterBoolean',
        'embeddedNid' => 'filterEmbeddedNid',
        'inEmbeddedNid' => 'filterInEmbeddedNid',
        'localizedLike' => 'filterLocalizedLike',
        'localized' => 'filterLocalized',
    ];

    /**
     * Filter integer values.
     *
     * @param array<int, string> $columns
     * @param string $value
     * @return void
     */
    public function filterInt($columns, $value)
    {
        foreach ($columns as $column) {
            $this->query->where($column, (int) $value);
        }
    }

    /**
     * Filter float values.
     *
     * @param array<int, string> $columns
     * @param string $value
     * @return void
     */
    public function filterFloat($columns, $value)
    {
        foreach ($columns as $column) {
            $this->query->where($column, (float) $value);
        }
    }

    /**
     * Filter boolean values.
     *
     * @param array<int, string> $columns
     * @param string $value
     * @return void
     */
    public function filterBoolean($columns, $value)
    {
        if ($value === 'false') {
            $value = false;
        }

        foreach ($columns as $column) {
            $this->query->where($column, (bool) $value);
        }
    }

    /**
     * Filter in boolean values.
     *
     * @param array<int, string> $columns
     * @param mixed $value
     * @return void
     */
    public function filterInBoolean($columns, $value)
    {
        foreach ($columns as $column) {
            $this->query->whereIn($column, array_map(boolval(...), (array) $value));
        }
    }

    /**
     * Filter not-in boolean values.
     *
     * @param array<int, string> $columns
     * @param mixed $value
     * @return void
     */
    public function filterNotInBoolean($columns, $value)
    {
        foreach ($columns as $column) {
            $this->query->whereNotIn($column, array_map(boolval(...), (array) $value));
        }
    }

    /**
     * Filter in float values.
     *
     * @param array<int, string> $columns
     * @param mixed $value
     * @return void
     */
    public function filterInFloat($columns, $value)
    {
        foreach ($columns as $column) {
            $this->query->whereIn($column, array_map(floatval(...), (array) $value));
        }
    }

    /**
     * Filter not-in float values.
     *
     * @param array<int, string> $columns
     * @param mixed $value
     * @return void
     */
    public function filterNotInFloat($columns, $value)
    {
        foreach ($columns as $column) {
            $this->query->whereNotIn($column, array_map(floatval(...), (array) $value));
        }
    }

    /**
     * Filter by an embedded document's numeric nid.
     *
     * Column `customer` becomes `customer.nid`. Paths that already end with
     * `.nid` are left as-is.
     *
     * FILTER_BY example:
     * `'embeddedNid' => ['customer', 'city' => 'shippingAddress.city']`
     *
     * @param array<int, string> $columns
     * @param mixed $value
     * @return void
     */
    public function filterEmbeddedNid($columns, $value)
    {
        foreach ($columns as $column) {
            $this->query->where($this->embeddedNidPath($column), (int) $value);
        }
    }

    /**
     * Filter where an embedded nid is in the given list.
     *
     * @param array<int, string> $columns
     * @param mixed $value
     * @return void
     */
    public function filterInEmbeddedNid($columns, $value)
    {
        foreach ($columns as $column) {
            $this->query->whereIn(
                $this->embeddedNidPath($column),
                array_map(intval(...), (array) $value)
            );
        }
    }

    /**
     * Filter localized text fields with LIKE on `*.text`.
     *
     * Column `name` becomes `name.text`. Paths that already end with `.text`
     * are left as-is.
     *
     * FILTER_BY example:
     * `'localizedLike' => ['name', 'productName' => 'items.product.name']`
     *
     * @param array<int, string> $columns
     * @param mixed $value
     * @return void
     */
    public function filterLocalizedLike($columns, $value)
    {
        $this->filterLike($this->localizedTextPaths($columns), $value);
    }

    /**
     * Exact match on localized `*.text` paths.
     *
     * @param array<int, string> $columns
     * @param mixed $value
     * @return void
     */
    public function filterLocalized($columns, $value)
    {
        foreach ($this->localizedTextPaths($columns) as $column) {
            $this->query->where($column, $value);
        }
    }

    /**
     * @param  string $column
     */
    protected function embeddedNidPath(string $column): string
    {
        return str_ends_with($column, '.nid') ? $column : $column . '.nid';
    }

    /**
     * @param  array<int, string> $columns
     * @return array<int, string>
     */
    protected function localizedTextPaths(array $columns): array
    {
        return array_map(
            static fn (string $column): string => str_ends_with($column, '.text')
                ? $column
                : $column . '.text',
            $columns
        );
    }

    /**
     * Get all available filters map
     *
     * @return array<string, string>
     */
    public function filterMap()
    {
        return array_merge(static::NO_SQL_FILTER_MAP, parent::filterMap());
    }
}
