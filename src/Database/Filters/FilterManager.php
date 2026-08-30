<?php

namespace HZ\Illuminate\Mongez\Database\Filters;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;

class FilterManager
{
    /**
     * Query Builder Object
     *
     * @var \Illuminate\Database\Query\Builder
     */
    public $query;

    /**
     * Sended options to filtered
     *
     * @var array<string, mixed>
     */
    public $options = [];

    /**
     * All options that enable to filter with
     *
     * @var array<string, mixed>
     */
    public $filterBy = [];

    /**
     * Set required data for filters
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array<string, mixed> $options
     * @param array<string, mixed> $filterBy
     */
    public function __construct($query, $options, $filterBy)
    {
        $this->query = $query;
        $this->options = $options;
        $this->filterBy = $filterBy;
    }

    /**
     * Filter by the given classes
     *
     * @param array<int, string> $filterClasses
     * @return void
     */
    public function filter(array $filterClasses)
    {
        $collectOptions = $this->getRequestedOptions();

        foreach ($filterClasses as $filterClass) {
            $filterObject = App::make($filterClass);

            $filterObject->setQuery($this->query);

            $filtersList = $filterObject->filterMap();

            foreach ($collectOptions as $option) {
                $filterFunction = $option['operator'];

                if (!array_key_exists($option['operator'], $filtersList)) continue;

                $filterFunction = $filtersList[$option['operator']];

                    foreach ($option['columns'] as $column) {
                        if (empty($column['filteredColumns'])) continue;

                        /** @var callable $callback */
                        $callback = [$filterObject, $filterFunction];

                        call_user_func_array($callback, [$column['filteredColumns'], $column['value'], $option['operator']]);
                    }
            }
        }
    }

    /**
     * Remove un sended options
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getRequestedOptions()
    {
        $requestedOptions = [];

        foreach ($this->filterBy as $operator => $columns) {
            $options = [];

            $requestedColumns = [];

            foreach ((array) $columns as $key => $column) {
                if (!is_string($key)) {
                    $key = $column;
                }

                if (($value = Arr::get($this->options, $key, null)) !== null) {
                    $options['operator'] = $operator;

                    $requestedColumns[] = [
                        'filteredColumns' => (array) $column,
                        'value' => $value
                    ];

                    if (!empty($columns)) {
                        $options['columns'] = $requestedColumns;
                    }
                }
            }

            if ($requestedColumns !== []) {
                $options['columns'] = $requestedColumns;
            }

            if (!empty($options)) {
                $requestedOptions[] = $options;
            }
        }

        return $requestedOptions;
    }
}
