<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Aggregate;

use Carbon\CarbonInterface;
use DateTimeInterface;
use MongoDB\BSON\UTCDateTime;
use Illuminate\Support\Facades\Date;

class Pipeline
{
    /**
     * Pipeline name without the `$` sign
     * 
     * @var string
     */
    public $name;

    /**
     * Aggregation Framework Handler
     * 
     * @var Aggregate
     */
    protected $aggregationFramework;

    /**
     * Pipeline Data
     *
     * @var array<int|string, mixed>
     */
    protected $data = [];

    /**
     * Matched operators
     * 
     * @const array
     */
    const MATCHING_OPERATOR = [
        '=' => '$eq',
        '<' => '$lt',
        '<=' => '$lte',
        '>' => '$gt',
        '>=' => '$gte',
        '!=' => '$ne',
        '<>' => '$ne',
    ];

    /**
     * Sum the given column name 
     * Please note this method MUST BE CALLED directly after the group by method 
     * 
     * @param array<int|string, mixed>|string $columns
     * @return Pipeline
     */
    public function sum($columns)
    {
        if ($this->name !== 'group') {
            // throw new Exception('Sum Method Must be called directly after the groupBy Method');
            return $this->groupBy()->sum($columns);
        }

        if (is_string($columns)) {
            $columns = [
                $columns => $columns,
            ];
        }

        foreach ($columns as $column => $alias) {
            $this->data($alias, ['$sum' => '$' . $column]);
        }

        return $this;
    }

    /**
     * Add data to it
     *
     * @param array<int|string, mixed>|int|string|null $key
     * @param mixed $value
     */
    public function data($key, $value = null): Pipeline
    {
        if ($key === null) {
            return $this;
        }

        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            if (isset($this->data[$key])) {
                if (!is_array($value)) {
                    $value = [$value => '$' . $value];
                }

                $this->data[$key] = array_merge((array) $this->data[$key], $value);
            } else {
                $this->data[$key] =  $value;
            }
        }

        return $this;
    }

    /**
     * Select columns
     * 
     * @param mixed ...$columns
     */
    public function select(...$columns): Pipeline
    {
        if (!in_array($this->name, ['group', 'project'])) {
            return $this->aggregationFramework->select(...$columns);
        }

        foreach ($columns as $column) {
            if (is_array($column)) {
                [$column, $alias] = $column;
            } else {
                $keys = explode('.', $column);
                $alias = end($keys); // i.e user.id => will return id only
            }

            if ($this->name === 'group') {
                $this->data($alias, [
                    '$last' => "$$column"
                ]);
            } elseif ($this->name === 'project') {
                $this->data($alias, [
                    $column => "$$column"
                ]);
            }
        }

        return $this;
    }

    /**
     * Unselect the given columns
     *
     * @param string ...$columns
     */
    public function unselect(...$columns): Pipeline
    {
        foreach ($columns as $column) {
            $this->data($column, 0);
        }

        return $this;
    }

    /**
     * Where clause
     *
     * @return Pipeline
     */
    public function where()
    {
        $arguments = func_get_args();
        $totalArguments = count($arguments);

        $column = $operator = $value = null;

        if ($totalArguments === 2) {
            [$column, $value] = $arguments;
            $operator = '=';
        } elseif ($totalArguments === 3) {
            [$column, $operator, $value] = $arguments;
        }

        if ($value instanceof DateTimeInterface) {
            $value = $this->praseDate($value);
        }

        $this->data($column, [
            static::MATCHING_OPERATOR[$operator] => $value,
        ]);

        return $this;
    }

    /**
     * Where clause
     *
     * @return Pipeline
     */
    public function orWhere()
    {
        $arguments = func_get_args();
        $totalArguments = count($arguments);

        $column = $operator = $value = null;

        if ($totalArguments === 2) {
            [$column, $value] = $arguments;
            $operator = '=';
        } elseif ($totalArguments === 3) {
            [$column, $operator, $value] = $arguments;
        }

        $data = [
            $column => [
                static::MATCHING_OPERATOR[$operator] => $value,
            ]
        ];

        $this->data('$or', $data);

        return $this;
    }

    /**
     * where in clause
     *
     * @param string $column
     * @param array<int|string, mixed> $array
     */
    public function whereIn($column, $array): Pipeline
    {
        $this->data($column, [
            '$in' => $array,
        ]);

        return $this;
    }

    /**
     * where in clause for array of integers
     * 
     * @param  string $column
     * @param  array $array 
     * @return Pipeline      
     */
    /**
     * @param string $column
     * @param array<int> $array
     */
    public function whereInInt($column, $array): Pipeline
    {
        return $this->whereIn($column, array_map(intval(...), $array));
    }

    /**
     * Where between clause
     *
     * @param  string $column
     * @param  mixed $minValue
     * @param  mixed $maxValue
     */
    public function whereBetween($column, $minValue, $maxValue): Pipeline
    {
        if ($minValue instanceof DateTimeInterface) {
            $minValue = $this->praseDate($minValue);
        }

        if ($maxValue instanceof DateTimeInterface) {
            $maxValue = $this->praseDate($maxValue);
        }

        $this->data($column, [
            static::MATCHING_OPERATOR['>='] => $minValue,
            static::MATCHING_OPERATOR['<='] => $maxValue,
        ]);

        return $this;
    }

    /**
     * Select columns
     *
     * @param mixed ...$columns
     */
    public function count(...$columns): Pipeline
    {
        foreach ($columns as $column) {
            if (is_array($column)) {
                [$column, $alias] = $column;
            } else {
                $alias = $column;
            }

            $this->data($alias, [
                '$sum' => 1
            ]);
        }

        return $this;
    }

    /**
     * Parse date into proper UTCDateTime format
     *
     * @return UTCDateTime
     */
    protected function praseDate(DateTimeInterface $date)
    {
        return new UTCDateTime((int) $date->format('Uv'));
    }

    /**
     * Return the final name of the pipeline
     */
    public function getName(): string
    {
        return '$' . $this->name;
    }

    /**
     * Return the final data of the pipeline
     * 
     * @return array<int|string, mixed>
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param int $number
     */
    public function limit($number): Pipeline
    {
        $this->data((int) $number);
        return $this;
    }

    /**
     * @param int $number
     */
    public function skip($number): Pipeline
    {
        $this->data((int)$number);
        return $this;
    }

    /**
     * Group by the given columns (delegates to the aggregation framework)
     *
     * @param mixed ...$columns
     */
    public function groupBy(...$columns): Pipeline
    {
        return $this->aggregationFramework->groupBy(...$columns);
    }

    /**
     * Unwind the given column
     *
     * @param string $from
     * @param string $localField
     * @param string $foreignField
     * @param string|null $as
     */
    public function join($from, $localField, $foreignField, $as = null): Pipeline
    {
        if (!$as) $as = $from;

        $this->data([
            'from' => $from,
            'localField' => $localField,
            'foreignField' => $foreignField,
            'as' => $as
        ]);
        return $this;
    }

    /**
     * @param string $column
     * @param mixed $includeArrayIndex
     * @param mixed $preserveNullAndEmptyArrays
     */
    public function unwind($column, $includeArrayIndex, $preserveNullAndEmptyArrays): Pipeline
    {
        if ($this->name !== 'unwind') {
            return $this->aggregationFramework->unwind($column);
        }

        $data = [
            'path' => '$' . $column,
            'includeArrayIndex' => $includeArrayIndex,
            'preserveNullAndEmptyArrays' => $preserveNullAndEmptyArrays
        ];

        if (!$includeArrayIndex) unset($data['includeArrayIndex']);
        $this->data($data);
        return $this;
    }

    /**
     * @param string $name
     * @param array<int, mixed> $arguments
     */
    public function __call($name, $arguments): Aggregate
    {
        /** @var callable $callback */
        $callback = [$this->aggregationFramework, $name];

        /** @var Aggregate $result */
        $result = call_user_func_array($callback, $arguments);

        return $result;
    }
}
