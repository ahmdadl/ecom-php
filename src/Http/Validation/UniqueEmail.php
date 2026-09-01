<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Http\Validation;

use Illuminate\Contracts\Validation\Rule;
use HZ\Illuminate\Mongez\Repository\RepositoryManager;

class UniqueEmail implements Rule
{
    /**
     * Repository object
     * 
     * @var \HZ\Illuminate\Mongez\Repository\MongoDBRepositoryManager<\HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model>
     */
    protected $repository;

    /**
     * Attribute name
     * 
     * @var string
     */
    protected $attribute;

    /**
     * Column name
     * 
     * @var string
     */
    protected $column;

    /**
     * Except column
     * 
     * @var string|null
     */
    protected ?string $exceptColumn = null;

    /**
     * Except column value
     * 
     * @var string
     */
    protected $exceptColumnValue;

    /**
     * Constructor
     * 
     * @param  \HZ\Illuminate\Mongez\Repository\MongoDBRepositoryManager<\HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model> $repository
     * @param  string $column
     * @param mixed $exceptColumnValue
     * @param string|null $exceptColumn
     */
    public function __construct($repository, $column, $exceptColumnValue = null, $exceptColumn = null)
    {
        $this->repository = $repository;
        $this->column = $column;
        $this->exceptColumnValue = $exceptColumnValue;
        $this->exceptColumn = $exceptColumn;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $this->attribute = $attribute;

        $query = $this->repository->getQuery();

        if ($this->exceptColumn) {
            $query->where($this->exceptColumn, '!=', $this->exceptColumnValue);
        }

        return $query->where($this->column, strtolower($value))->exists() === false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        $message = trans('mongez::validation.uniqueEmail', ['attribute' => $this->attribute]);

        return is_string($message) ? $message : '';
    }
}
