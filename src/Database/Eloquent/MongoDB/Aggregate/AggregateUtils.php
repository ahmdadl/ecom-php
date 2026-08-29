<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Aggregate;

class AggregateUtils
{
    /**
     * Aggregate framework
     * 
     * @var Aggregate
     */
    protected $aggregate;

    /**
     * Constructor
     */
    public function __construct(Aggregate $aggregate)
    {
        $this->aggregate = $aggregate;
    }

    /**
     * Map the data into proper mongodb aggregate framework format
     */
    public function map(): array
    {
        $expressions = func_get_args();

        if (count($expressions) === 1 && is_array($expressions[0])) {
            $expressions = $expressions[0];
        }

        $data = [];

        foreach ($expressions as $expression) {
            [$returnAs, $expressionColumns] = $expression->parse();
            $data[$returnAs] = $expressionColumns;
        }

        return $data;
    }

    /**
     * returnAs to map method
     */
    public function toArray(): array
    {
        return $this->map(func_get_args());
    }

    /**
     * Get sum expression
     */
    public function sum(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$sum', $column, $returnAs);
    }

    /**
     * Get count expression
     */
    public function count(string $returnAs = ''): Expression
    {
        return $this->expression('$sum', 1, $returnAs);
    }

    /**
     * Get avg expression
     */
    public function avg(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$avg', $column, $returnAs);
    }

    /**
     * Get week expression
     */
    public function week(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$week', $column, $returnAs);
    }

    /**
     * Get month expression
     */
    public function month(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$month', $column, $returnAs);
    }

    /**
     * Get year expression
     */
    public function year(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$year ', $column, $returnAs);
    }

    /**
     * Get multiple expression
     */
    public function multiple(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$multiply', $column, $returnAs);
    }

    /**
     * Get divide expression
     */
    public function divide(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$divide', $column, $returnAs);
    }

    /**
     * Get max expression
     */
    public function max(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$max', $column, $returnAs);
    }

    /**
     * Get min expression
     */
    public function min(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$min', $column, $returnAs);
    }

    /**
     * Get first of value
     */
    public function first(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$first', $column, $returnAs);
    }

    /**
     * Get last of value
     */
    public function last(string $column = '', string $returnAs = ''): Expression
    {
        return $this->expression('$last', $column, $returnAs);
    }

    /**
     * Create new Expression
     *
     * @param  mixed $column
     */
    public function expression(string $operator, $column, string $returnAs = ''): Expression
    {
        return new Expression($operator, $column, $returnAs);
    }
}
