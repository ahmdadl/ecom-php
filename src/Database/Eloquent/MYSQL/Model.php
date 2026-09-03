<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Database\Eloquent\MYSQL;

use Illuminate\Database\Eloquent\Model as BaseModel;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelTrait;

class Model extends BaseModel
{
    use ModelTrait;

    /**
     * Created By column
     * Set it to false if this column doesn't exist in the table
     *
     * @const string|bool
     */
    const CREATED_BY = 'created_by';

    /**
     * Updated By column
     * Set it to false if this column doesn't exist in the table
     *
     * @const string|bool
     */
    const UPDATED_BY = 'updated_by';

    /**
     * Deleted By column
     * Set it to false if this column doesn't exist in the table
     *
     * @const string|bool
     */
    const DELETED_BY = 'deleted_by';

    /**
     * Enable or disable model caching for this model class.
     * null = fall back to linked repository or global config.
     *
     * @var bool|null
     */
    const USING_CACHE = null;

    /**
     * Additional columns that should be indexed as cache lookup keys.
     *
     * @var array<int, string>
     */
    const CACHE_ALTERNATE_KEYS = [];

    /**
     * Disable guarded fields
     *
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * Boot the model and its cache hooks.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::bootCacheableModel();
    }

    /**
     * Get user id that will be used with created by, updated by and deleted by
     *
     * @return int
     */
    protected function byUser()
    {
        return user()->id ?? 0;
    }
}
