<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Aggregate;

class Expression
{
    /**
     * Operator
     */
    protected string $operator = '';

    /**
     * Column | Columns
     * 
     * @var int|float|string|string[]|Expression
     */
    protected $column;

    /**
     * return as
     */
    protected string $returnAs = '';

    /**
     * Constructor
     *
     * @param int|float|string|string[]|Expression $column
     */
    public function __construct(string $operator, $column, string $returnAs = '')
    {
        $this->operator($operator);
        $this->column($column);
        $this->returnAs($returnAs);
    }

    /**
     * Set operator
     */
    public function operator(string $operator): Expression
    {
        $this->operator = $operator;
        return $this;
    }

    /**
     * Set column
     *
     * @param string|string[] $column
     */
    public function column($column): Expression
    {
        $this->column = $column;
        return $this;
    }

    /**
     * Set returnAs
     */
    public function returnAs(string $returnAs): Expression
    {
        $this->returnAs = $returnAs;
        return $this;
    }

    /**
     * Prase expression
     */
    public function parse(): array
    {
        $returnAs = $this->returnAs ?: $this->column;

        $column = $this->column;

        if (is_string($column)) {
            $column = '$' . $column;
        } elseif (is_array($column)) {
            $column = array_map(fn($column) => '$' . $column, $column);
        } elseif ($column instanceof Expression) {
            $column = $column->parse();
        }

        return [$returnAs, [
            $this->operator => $column,
        ]];
    }
}
