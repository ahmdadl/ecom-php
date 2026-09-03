<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent;

use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\SoftDeletes;

trait ModelTrait
{
    use CacheableModel;
    /**
     * Set table prefix
     * If set to null, then config:mongez.database.prefix will be used instead
     * 
     * @var string|null
     */
    public $prefix;

    /**
     * If set to true, it will disable updated by during timeline
     *
     * @var boolean
     */
    public static $disableUpdateTime = false;

    /**
     * Determine if the current model uses the given trait
     */
    public function uses(string $trait): bool
    {
        return in_array($trait, class_uses($this));
    }

    /**
     * Get table name
     * 
     * @return string
     */
    public static function getTableName()
    {
        /** @phpstan-ignore-next-line new.static */
        return (new static)->getTable();
    }

    /**
     * Increment a column's value on the model.
     *
     * Widened to public so repositories may call it from outside the model.
     *
     * {@inheritdoc}
     *
     * @param  string $column
     * @param  int $amount
     * @param  array<string, mixed> $extra
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        return parent::increment($column, $amount, $extra);
    }

    /**
     * Decrement a column's value on the model.
     *
     * Widened to public so repositories may call it from outside the model.
     *
     * {@inheritdoc}
     *
     * @param  string $column
     * @param  int $amount
     * @param  array<string, mixed> $extra
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        return parent::decrement($column, $amount, $extra);
    }

    /**
     * An alias method to `getAttributes` method
     */
    /**
     * An alias method to `getAttributes` method
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return $this->attributes;
    }

    /**
     * {@inheritdoc}
     */
    public function getTable()
    {
        $table = parent::getTable();
        $prefix = '';

        if ($this->prefix !== null) {
            $prefix = $this->prefix;
        } elseif (config('mongez.database.prefix')) {
            $prefix = config('mongez.database.prefix');
        }

        if ($prefix && !str_starts_with($table, $prefix)) {
            return $prefix . $table;
        }

        return $table;
    }

    /**
     * Get model nid, if no nid yet then return next id
     */
    public function getNid(): int
    {
        /** @phpstan-ignore-next-line staticMethod.notFound */
        return $this->nid ?? static::getNextId();
    }

    /**
     * @deprecated use getNid() instead
     */
    public function getId(): int
    {
        return $this->getNid();
    }

    /**
     * Pluck the given keys from the model info
     *
     * @param  array<int, string> $columns
     * @return array<string, mixed>
     */
    public function pluck(...$columns): array
    {
        if (func_num_args() === 1 && is_array($columns[0])) {
            $columns = $columns[0];
        }

        return $this->only($columns);
    }

    /**
     * Get all attributes except the given columns
     *
     * @param  array<int, string> $columns
     * @return array<string, mixed>
     */
    public function except(...$columns)
    {
        if (func_num_args() === 1 && is_array($columns[0])) {
            $columns = $columns[0];
        }

        return Arr::except($this->getAttributes(), $columns);
    }

    /**
     * {@inheritDoc}
     */
    public static function boot()
    {
        // fixing laravel 5.7 update that we MUST call the parent boot method first 
        parent::boot();
        // before creating, we will check if the created_by column has value
        // if so, then we will update the column for the current user id
        static::creating(function ($model) {
            if (static::CREATED_BY && !$model->{static::CREATED_BY}) {
                $model->{static::CREATED_BY} = $model->byUser();
            }

            if (static::UPDATED_BY && !$model->{static::UPDATED_BY}) {
                $model->{static::UPDATED_BY} = $model->byUser();
            }

            if (static::DELETED_BY && !$model->{static::DELETED_BY}) {
                $model->{static::DELETED_BY} = null;
            }
        });

        // before updating, we will check if the updated_by column has value
        // if so, then we will update the column for the current user id
        static::updating(function ($model) {
            if (static::UPDATED_BY) {
                $model->{static::UPDATED_BY} = $model->byUser();
            }

            $updatesLogModel = config('mongez.database.updatesLogModel');

            // if updates log model is set, then the data of current model 
            // will be logged before update happens.
            if ($updatesLogModel) {
                $updatesLogModel::create([
                    'table' => $model->getTableName(),
                    'nid' => $model->nid,
                    'data' => json_encode($model->getAttributes(), JSON_UNESCAPED_SLASHES),
                ]);
            }
        });

        // before deleting, we will check if the deleted_by column has value
        // if so, then we will update the column for the current user id
        static::deleting(function ($model) {
            if (static::DELETED_BY && $model->uses(SoftDeletes::class)) {
                $model->{static::DELETED_BY} = $model->byUser();
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function setUpdatedAt($value)
    {
        if (static::$disableUpdateTime) return $this;

        return parent::setUpdatedAt($value);
    }

    /**
     * Reset the model static state.
     *
     * This is used between requests when running on Laravel Octane
     * to make sure the update time state doesn't leak from one request
     * to another.
     *
     * @return void
     */
    public static function resetStaticState()
    {
        static::$disableUpdateTime = false;
    }
}
