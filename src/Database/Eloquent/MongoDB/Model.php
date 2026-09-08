<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent\MongoDB;

use MongoDB\Operation\FindOneAndUpdate;

use HZ\Illuminate\Mongez\Database\Eloquent\Associatable;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelEvents;
use DateTimeInterface;
use HZ\Illuminate\Mongez\Database\Eloquent\GeneralScopes;
use HZ\Illuminate\Mongez\Jobs\UpdateRelatedModelJob;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Eloquent\Model as BaseModel;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelTrait;

/**
 * @property int|null $nid
 * @property mixed $id Compat alias for `nid` when `mongez.mongodb.id_aliases_nid` is true; otherwise ObjectId string
 * @property mixed $createdAt
 * @property mixed $updatedAt
 * @property mixed $deletedAt
 */
abstract class Model extends BaseModel
{
    use RecycleBin, ModelEvents, Associatable, GeneralScopes;

    use ModelTrait {
        boot as traitBoot;
    }

    protected $primaryKey = "nid";
    protected $keyType = "int";

    /**
     * The name of the "created at" column.
     *
     * @var string
     */
    const CREATED_AT = 'createdAt';

    /**
     * The name of the "updated at" column.
     *
     * @var string
     */
    const UPDATED_AT = 'updatedAt';

    /**
     * The name of the "deleted at" column.
     *
     * @var string
     */
    const DELETED_AT = 'deletedAt';

    /**
     * Created By column
     * Set it to false if this column doesn't exist in the table
     *
     * @const string|bool
     */
    const CREATED_BY = 'createdBy';

    /**
     * Updated By column
     * Set it to false if this column doesn't exist in the table
     *
     * @const string|bool
     */
    const UPDATED_BY = 'updatedBy';

    /**
     * Deleted By column
     * Set it to false if this column doesn't exist in the table
     *
     * @const string|bool
     */
    const DELETED_BY = 'deletedBy';

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
     * Shared info of the model
     * This is used for getting simple info
     *
     * @const array
     */
    const SHARED_INFO = [];

    /**
     * This is a combination of ON_MODEL_CREATE & ON_MODEL_UPDATE & ON_MODEL_DELETE_UNSET
     * Define list of other models that will be affected on creating|updating|deleting
     *
     * @example ModelClass::class => searchingColumn will be converted to ['searchingColumn['nid']', 'columnName', 'sharedInfo']
     * @example ModelClass::class => [searchingColumn, creatingColumn]
     * @example ModelClass::class => [searchingColumn, creatingColumn, sharedInfoMethod]
     *
     * @const array
     */
    const MODEL_LINKS = [];

    /**
     * This is a combination of ON_MODEL_CREATE & ON_MODEL_UPDATE & ON_MODEL_DELETE
     * The main difference between this constant and MODEL_LINKS is that this constant will delete the entire record
     * unlike MODEL_LINKS it will just unset the embedded document.
     * Define list of other models that will be affected on creating|updating|deleting
     *
     * @example ModelClass::class => searchingColumn will be converted to ['searchingColumn['nid']', 'columnName', 'sharedInfo']
     * @example ModelClass::class => [searchingColumn, creatingColumn]
     * @example ModelClass::class => [searchingColumn, creatingColumn, sharedInfoMethod]
     *
     * @const array
     */
    const MODEL_LINKS_DELETE = [];

    /**
     * This is a combination of ON_MODEL_CREATE_PUSH & ON_MODEL_UPDATE_ARRAY & ON_MODEL_DELETE_PULL
     * Define list of other models that will be affected on creating|updating|deleting
     *
     * i.e [Country::class => 'cities'] current model is city, city is in Country model in `cities` key
     * Once the city model is created it will be pushed to Country model in `cities`
     *
     * @example ModelClass::class => searchingColumn will be converted to ['searchingColumn['nid']', 'columnName', 'sharedInfo']
     * @example ModelClass::class => [searchingColumn, creatingColumn]
     * @example ModelClass::class => [searchingColumn, creatingColumn, sharedInfoMethod]
     *
     * @const array
     */
    const MODEL_LINKS_ARRAY = [];

    /**
     * Define list of other models that will be affected
     * as the current model is sub-document to it when it gets created
     *
     * @example ModelClass::class => searchingColumn will be converted to ['searchingColumn['nid']', 'columnName', 'sharedInfo']
     * @example ModelClass::class => [searchingColumn, creatingColumn]
     * @example ModelClass::class => [searchingColumn, creatingColumn, sharedInfoMethod]
     *
     * @const array
     */
    const ON_MODEL_CREATE = [];

    /**
     * Define list of other models that will be affected as the current object is part of array
     * as the current model is sub-document to it when it gets created
     *
     * @example ModelClass::class => searchingColumn will be converted to ['searchingColumn['nid']', 'columnName', 'sharedInfo']
     * @example ModelClass::class => [searchingColumn, creatingColumn]
     * @example ModelClass::class => [searchingColumn, creatingColumn, sharedInfoMethod]
     *
     * @const array
     */
    const ON_MODEL_CREATE_PUSH = [];

    /**
     * Define list of other models that will be affected
     * as the current model is sub-document to it when it gets updated
     *
     * @example ModelClass::class => columnName will be converted to ['columnName.id', 'columnName', 'sharedInfo']
     * @example ModelClass::class => [searchingColumn, updatingColumn]
     * @example ModelClass::class => [searchingColumn, updatingColumn, sharedInfoMethod]
     *
     * @const array
     */
    const ON_MODEL_UPDATE = [];

    /**
     * Define list of other models that will be affected as the current object is part of array
     * as the current model is sub-document to it when it gets updated
     *
     * @example ModelClass::class => columnName will be converted to ['columnName.id', 'columnName', 'sharedInfo']
     * @example ModelClass::class => [searchingColumn, updatingColumn]
     * @example ModelClass::class => [searchingColumn, updatingColumn, sharedInfoMethod]
     *
     * @const array
     */
    const ON_MODEL_UPDATE_ARRAY = [];

    /**
     * Define list of other models that will clear the column from its records
     * A 1-1 relation
     *
     * Do not add the id, it will be appended automatically
     *
     * @example ModelClass::class => searchingColumn: string
     *
     * @const array
     */
    const ON_MODEL_DELETE_UNSET = [];

    /**
     * Define list of the models that have the current model as embedded document and pull it from the array
     *  A 1-n relation
     * Do not add the id, it will be appended automatically
     *
     * @example ModelClass::class => searchingColumn: string
     *
     * @const array
     */
    const ON_MODEL_DELETE_PULL = [];

    /**
     * Define list of other models that will be deleted
     * when this model is deleted
     * For example when a city is deleted, all related regions shall be deleted as well
     *
     * Do not add the id, it will be appended automatically
     *
     * @example Region::class => 'city'
     * @example ModelClass::class => searchingColumn: string
     *
     * @const array
     */
    const ON_MODEL_DELETE = [];

    /**
     * Run related-model propagation on a queue instead of synchronously.
     *
     * Set to `true` or `'queue'` to dispatch a job for create/update/delete
     * events. Defaults to `false` (sync). Use RELATED_MODELS_MODES to
     * override this per related model.
     *
     * @const bool|string
     */
    const RELATED_MODELS_QUEUE_MODE = false;

    /**
     * Per-related-model queue/sync overrides.
     *
     * Maps the related model class to `true`/`'queue'` or `false`/`'sync'`.
     * Targets not listed here fall back to RELATED_MODELS_QUEUE_MODE.
     *
     * @example Category::class => 'queue', Tag::class => 'sync'
     *
     * @const array<class-string, bool|string>
     */
    const RELATED_MODELS_MODES = [];

    /**
     * Disable guarded fields
     *
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * Set the auto increment value for generating ids
     *
     * @var int|null
     */
    protected static $autoIncrementIdBy;

    /**
     * Set the initial id value when collection is being created for first
     *
     * @var int|null
     */
    protected static $initialId;

    /**
     * Determine whether to trigger events or not on model create|update|delete
     *
     * @var true|false|'create'|'update'|'delete'
     */
    protected $triggerEvents = true;

    /**
     * Cached table names keyed by model class.
     *
     * A single inherited static string would be shared by every model
     * subclass and could route one model to another model's collection.
     *
     * @var array<class-string, string>
     */
    protected static array $tableNames = [];

    /**
     * Get table name and cache it
     * 
     * @return string
     */
    public static function tableName()
    {
        $modelClass = static::class;

        if (!isset(self::$tableNames[$modelClass])) {
            /** @phpstan-ignore-next-line new.static */
            $model = new static;
            self::$tableNames[$modelClass] = $model->getTable();
        }

        return self::$tableNames[$modelClass];
    }

    /**
     * Update Event State
     *
     * @param  mixed $state
     * @return Self
     */
    public function triggerEvents($state): Self
    {
        $this->triggerEvents = $state;

        return $this;
    }

    /**
     * Determine if current model can trigger the given event type
     *
     * @return boolean
     */
    public function canTrigger(string $eventType)
    {
        return $this->triggerEvents === true || $this->triggerEvents === $eventType;
    }

    /**
     * {@inheritDoc}
     */
    public static function boot()
    {
        static::traitBoot();

        static::bootCacheableModel();

        if (!static::$autoIncrementIdBy) {
            static::$autoIncrementIdBy = mt_rand(100, 999);
        }

        // Create an auto increment id on creating new document

        // before creating, we will check if the created_by column has value
        // if so, then we will update the column for the current user id
        static::creating(function ($model) {
            if (!$model->nid) {
                $model->nid = static::nextId();
            }
        });

        // When model create, detect whether there are any other models that
        // shall be created with it
        static::created(function ($model) {
            if (!$model->canTrigger('create')) return;

            static::propagateRelatedModels($model, 'created', [
                'handleCreateSingleModel' => array_merge(
                    config('mongez.database.onModel.create.' . static::class, []),
                    !empty(static::ON_MODEL_CREATE) ? static::ON_MODEL_CREATE : [],
                    !empty(static::MODEL_LINKS) ? static::MODEL_LINKS : [],
                ),
                'handleCreateArrayModel' => array_merge(
                    config('mongez.database.onModel.createArray.' . static::class, []),
                    !empty(static::ON_MODEL_CREATE_PUSH) ? static::ON_MODEL_CREATE_PUSH : [],
                    !empty(static::MODEL_LINKS_ARRAY) ? static::MODEL_LINKS_ARRAY : [],
                ),
            ]);
        });

        static::updating(function ($model) {
            if (static::UPDATED_BY && ($user = user())) {
                $model->updatedBy = $user->sharedInfo();
            }
        });

        // When model update, detect whether there are any other models that
        // shall be updated with it
        static::updated(function ($model) {
            if (!$model->canTrigger('update')) return;

            static::propagateRelatedModels($model, 'updated', [
                'handleUpdateSingleModel' => array_merge(
                    config('mongez.database.onModel.update.' . static::class, []),
                    !empty(static::ON_MODEL_UPDATE) ? static::ON_MODEL_UPDATE : [],
                    !empty(static::MODEL_LINKS) ? static::MODEL_LINKS : [],
                ),
                'handleUpdateArrayModel' => array_merge(
                    config('mongez.database.onModel.updateArray.' . static::class, []),
                    !empty(static::ON_MODEL_UPDATE_ARRAY) ? static::ON_MODEL_UPDATE_ARRAY : [],
                    !empty(static::MODEL_LINKS_ARRAY) ? static::MODEL_LINKS_ARRAY : [],
                ),
            ]);
        });

        // triggered when a model record is deleted from database
        static::deleted(function ($model) {
            if (!$model->canTrigger('delete')) return;

            static::propagateRelatedModels($model, 'deleted', [
                'handleUnsetSingleModel' => array_merge(
                    config('mongez.database.onModel.deleteUnset.' . static::class, []),
                    !empty(static::ON_MODEL_DELETE_UNSET) ? static::ON_MODEL_DELETE_UNSET : [],
                    !empty(static::MODEL_LINKS) ? static::MODEL_LINKS : [],
                ),
                'handlePullArrayModel' => array_merge(
                    config('mongez.database.onModel.deletePull.' . static::class, []),
                    !empty(static::ON_MODEL_DELETE_PULL) ? static::ON_MODEL_DELETE_PULL : [],
                    !empty(static::MODEL_LINKS_ARRAY) ? static::MODEL_LINKS_ARRAY : [],
                ),
                'handleDeleteSingleModel' => array_merge(
                    config('mongez.database.onModel.delete.' . static::class, []),
                    !empty(static::ON_MODEL_DELETE) ? static::ON_MODEL_DELETE : [],
                    !empty(static::MODEL_LINKS_DELETE) ? static::MODEL_LINKS_DELETE : [],
                ),
            ]);
        });
    }

    /**
     * Propagate the given event to each related model, either inline or via a queue job.
     *
     * @param Model $model
     * @param string $event
     * @param array<string, array<class-string, mixed>> $handlers
     * @return void
     */
    protected static function propagateRelatedModels(Model $model, string $event, array $handlers): void
    {
        foreach ($handlers as $handlerMethod => $targetModels) {
            foreach ($targetModels as $targetModelClass => $options) {
                if (static::shouldRunRelatedModelOnQueue($targetModelClass)) {
                    $job = UpdateRelatedModelJob::dispatch(
                        static::class,
                        (int) $model->nid,
                        $event,
                        $handlerMethod,
                        $targetModelClass
                    );

                    if ($connection = config('mongez.queue.connection')) {
                        $job->onConnection($connection);
                    }

                    $job->onQueue(config('mongez.queue.name', 'default'));
                } else {
                    static::$handlerMethod($model, $targetModelClass);
                }
            }
        }
    }

    /**
     * Create and return new id for the current model
     */
    public static function nextId(): int
    {
        $collection = static::tableName();

        // The ids collection documents hold their counter value in an `id` field.
        // Use one atomic update so concurrent Octane requests and queue workers
        // cannot read and write the same counter value.
        /** @phpstan-ignore-next-line method.notFound, new.static */
        $idsCollection = (new static)->getConnection()->getDatabase()->selectCollection('ids');

        $idsCollection->createIndex(['collection' => 1], ['unique' => true]);

        $counter = $idsCollection->findOneAndUpdate(
            ['collection' => $collection],
            ['$inc' => ['id' => static::$autoIncrementIdBy]],
            [
                'upsert' => true,
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ]
        );

        if (!is_array($counter) || !isset($counter['id'])) {
            throw new \RuntimeException("Unable to allocate nid for collection [{$collection}]");
        }

        return (int) $counter['id'];
    }

    /**
     * Get next id
     */
    public static function getNextId(): int
    {
        return static::lastInsertId() + static::$autoIncrementIdBy;
    }

    /**
     * Get last insert id of the given collection name
     */
    public static function lastInsertId(): int
    {
        $ids = DB::table('ids');

        $info = $ids->where('collection', static::tableName())->first();

        if (empty($info->id)) return 0;

        return $info->id;
    }

    /**
     * Reset auto increment
     *
     * @return void
     */
    public static function resetAutoIncrement()
    {
        DB::table('ids')->where('collection', static::tableName())->delete();
    }

    /**
     * Truncate the entire records and reset the auto increment
     *
     * @return void
     */
    public static function truncate()
    {
        /** @phpstan-ignore-next-line method.staticCall */
        static::delete();
        static::resetAutoIncrement();
    }

    /**
     * Whether `$model->id` should return integer `nid` instead of the ObjectId string.
     *
     * Opt-in migration shim — prefer `$model->nid` in new code.
     */
    public static function idAliasesNid(): bool
    {
        return (bool) config('mongez.mongodb.id_aliases_nid', false);
    }

    /**
     * Compat accessor: when `mongez.mongodb.id_aliases_nid` is enabled, `$model->id`
     * returns integer `nid`. Otherwise delegates to mongodb/laravel-mongodb (ObjectId string).
     *
     * This is a temporary alias, not the driver document key. Use `$model->nid` or
     * `$model->_id` explicitly once migration is complete.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function getIdAttribute($value = null)
    {
        if (! static::idAliasesNid()) {
            return parent::getIdAttribute($value);
        }

        if (! array_key_exists('nid', $this->attributes) || $this->attributes['nid'] === null) {
            return null;
        }

        return (int) $this->attributes['nid'];
    }

    /**
     * This method should return the info of the document that will be stored in another document, default to full info
     *
     * @return array<string, mixed>
     */
    public function sharedInfo(): array
    {
        $info = !empty(static::SHARED_INFO) ? $this->pluck(static::SHARED_INFO)
            : $this->getAttributes();

        unset($info['_id']);

        // Default: never embed the driver ObjectId as `id`.
        // With the migration shim, keep emitting integer `id` (= nid) when SHARED_INFO
        // still lists `'id'` so denormalized embeds stay usable during cutover.
        if (static::idAliasesNid() && in_array('id', static::SHARED_INFO, true)) {
            $info['id'] = isset($this->attributes['nid'])
                ? (int) $this->attributes['nid']
                : (int) $this->nid;
        } else {
            unset($info['id']);
        }

        $this->adjustDateInSharedInfo($info);

        return $info;
    }

    /**
     * Check if the given info data has date, then adjust it recursively
     *
     * @param array<string, mixed> $info
     * @return void
     */
    public function adjustDateInSharedInfo(&$info)
    {
        foreach ($info as &$value) {
            if ($value instanceof DateTimeInterface) {
                $value = $value->getTimestamp();
            } elseif (is_array($value)) {
                $this->adjustDateInSharedInfo($value);
            }
        }
    }

    /**
     * Get shared info plus the given columns
     *
     * @param string ...$columns
     * @return array<string, mixed>
     */
    public function sharedInfoWith(...$columns): array
    {
        return array_merge($this->sharedInfo(), $this->pluck(...$columns));
    }

    /**
     * Get shared info except the given columns
     *
     * @param string ...$columns
     * @return array<string, mixed>
     */
    public function sharedInfoExcept(...$columns): array
    {
        return array_diff_key($this->sharedInfo(), $this->pluck(...$columns));
    }

    /**
     * {@inheritDoc}
     *
     * @param int|string $id
     * @return static|null
     */
    public static function find($id): ?static
    {
        return static::query()->where('nid', (int) $id)->first();
    }

    /**
     * Find multiple documents by their numeric Mongez IDs.
     *
     * @param mixed $ids
     * @param array<int, string> $columns
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    public static function findMany($ids, $columns = ['*'])
    {
        $ids = array_values(array_filter(
            array_map(static fn ($id): int => (int) $id, (array) $ids),
            static fn (int $id): bool => $id > 0
        ));

        return static::query()->whereIn('nid', $ids)->get($columns);
    }

    /**
     * Resolve numeric route model bindings through `nid`.
     *
     * MongoDB v5 reserves the root `id`/`_id` identity, while Mongez's
     * application identity is the numeric `nid`.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return $this->resolveRouteBindingQuery($this, $value, $field)->first();
        }

        return static::query()->where('nid', (int) $value)->first();
    }

    /**
     * Get user by id that will be used with created by, updated by and deleted by
     *
     * @return mixed
     */
    protected function byUser()
    {
        $user = user();
        return $user ? $user->sharedInfo() : null;
    }
}
