<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Repository;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Model;
use HZ\Illuminate\Mongez\Events\Events;
use Illuminate\Support\Traits\Macroable;
use HZ\Illuminate\Mongez\Repository\Concerns\Fillers;
use HZ\Illuminate\Mongez\Repository\Concerns\Listable;
use HZ\Illuminate\Mongez\Repository\Concerns\Cacheable;
use HZ\Illuminate\Mongez\Repository\Concerns\Deletable;
use HZ\Illuminate\Mongez\Repository\RepositoryInterface;
use HZ\Illuminate\Mongez\Traits\WithRepositoryAndService;
use HZ\Illuminate\Mongez\Translation\Traits\Translatable;

/**
 * @template TModel of \HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model
 * @implements RepositoryInterface<TModel>
 */
abstract class RepositoryManager implements RepositoryInterface
{
    /**
     * We're injecting the repository and service caller traits as it will be used
     * for quick access to other repositories and services
     */
    use WithRepositoryAndService;

    /**
     * Data Saving Fillers
     */
    /** @use Fillers<TModel> */
    use Fillers;

    /**
     * Deleting
     */
    /** @use Deletable<TModel> */
    use Deletable;

    /**
     * Caching
     */
    /** @use Cacheable<TModel> */
    use Cacheable;

    /**
     * Listable
     */
    /** @use Listable<TModel> */
    use Listable;

    /**
     * Allow repository to be extended
     */
    use Macroable {
        Macroable::__call as marcoableMethods;
    }

    /**
     * Allow translation from the repository
     */
    use Translatable {
        Translatable::__call as translate;
    }

    /**
     * Repository name
     *
     * @const string
     */
    const NAME = '';

    /**
     * Model name
     *
     * @var class-string<TModel>|string
     */
    const MODEL = '';

    /**
     * Event name to be triggered
     * If empty, the repository name will be used instead
     *
     * @const string
     */
    const EVENT = '';

    /**
     * List of events 
     *
     * @const array
     */
    const EVENTS_LIST = [
        'listing' => 'onListing',
        'list' => 'onList',
        'creating' => 'onCreating',
        'create' => 'onCreate',
        'updating' => 'onUpdating',
        'update' => 'onUpdate',
        'saving' => 'onSaving',
        'save' => 'onSave',
        'deleting' => 'onDeleting',
        'delete' => 'onDelete',
    ];

    /**
     * Uploads directory name
     *
     * @const string
     */
    const UPLOADS_DIRECTORY = '';

    /**
     * If set to true, then the file will be stored as its uploaded name
     *
     * @const bool|null
     */
    const UPLOADS_KEEP_FILE_NAME = null;

    /**
     * If set to true, the multiple uploads column paths will be json encoded while storing it in database.
     *
     * @const bool
     */
    const SERIALIZE_MULTIPLE_UPLOADS = true;

    /**
     * Set if the current repository uses a soft delete method or not
     * This is mainly used in the where clause
     *
     * @var bool
     */
    const USING_SOFT_DELETE = true;

    /**
     * Using redis cache
     *
     * @var bool
     */
    const USING_CACHE = false;

    /**
     * Table name
     *
     * @const string
     */
    const TABLE = '';

    /**
     * Auto fill the following columns directly from the request
     *
     * @const array
     */
    const DATA = [];

    /**
     * Auto fill the following columns as localized data
     *
     * @const array
     */
    const LOCALIZED_DATA = [];

    /**
     * Set columns list of string values.
     *
     * @cont array
     */
    const STRING_DATA = [];

    /**
     * Auto fill the following columns as arrays directly from the request
     * It will encoded and stored as `JSON` format,
     * it will be also auto decoded on any database retrieval either from `list` or `get` methods
     *
     * @const array
     */
    const ARRAYBLE_DATA = [];

    /**
     * Auto save uploads in this list
     *
     * If it's an indexed array, in that case the request key will be as database column name
     * If it's associated array, the key will be request key and the value will be the database column name 
     *
     * It can be passed as well as an array of options, current options schema:
     * [
     *    'input' => 'string', // the input that will be read from the request files
     *    'column' => 'string', // if not present, it will be same as $key value
     *    'clearable' => 'bool', // if set to true, the column value will be set to empty if there is no file to be uploaded
     *    'arrayable' => 'bool', // if set to true, it will be stored as an array, if set to null it auto determined
     * ]
     *
     * @const array
     */
    const UPLOADS = [];

    /**
     * Set columns list of integers values.
     *
     * @cont array
     */
    const INTEGER_DATA = [];

    /**
     * Set columns list of float values.
     *
     * @cont array
     */
    const FLOAT_DATA = [];

    /**
     * Set columns list of date values.
     *
     * @cont array
     */
    const DATE_DATA = [];

    /**
     * Set columns of booleans data type.
     *
     * @cont array
     */
    const BOOLEAN_DATA = [];

    /**
     * Update the column if and only if its value is passed in the request, if set to true, 
     * then all columns that is not in the request data will be not updated in the model and kept untouched.
     *
     * @const array
     */
    const WHEN_AVAILABLE_DATA = [];

    /**
     * Only the columns added in this array will be affected by PATCH request if sent.
     * Note : patch handler should be activated in config/mongez.php admin.patchable
     *
     * @const array  
     */
    const PATCHABLE_DATA = [];

    /**
     * Filter class.
     *
     * @const string
     */
    const FILTERS = [];

    /**
     * Resource class
     *
     * @const string
     */
    const RESOURCE = '';

    /**
     * Set the default order by for the repository
     * i.e ['nid', 'DESC']
     *
     * @const array
     */
    const ORDER_BY = ['nid', 'DESC'];

    /**
     * Filter by columns used with `list` method only
     *
     * @const array
     */
    const FILTER_BY = [];

    /**
     * Determine wether to use pagination in the `list` method
     * if set null, it will depend on pagination configurations
     *
     * @const bool
     */
    const PAGINATE = null;

    /**
     * Number of items per page in pagination
     * If set to null, then it will taken from pagination configurations
     *
     * @const int|null
     */
    const ITEMS_PER_PAGE = null;

    /**
     * Published column
     * 
     * @const string
     */
    public const DEFAULT_PUBLISHED_COLUMN = 'published';

    /**
     * This property will has the final table name that will be used
     * i.e if the TABLE_ALIAS is not empty, then this property will be the value of the TABLE_ALIAS
     * otherwise it will be the value of the TABLE constant
     *
     * @var string|null
     */
    protected $table;

    /**
     * The base event name that will be used
     *
     * @var string
     */
    protected $eventName;

    /**
     * User data
     *
     * @var mixed
     */
    protected $user;


    /**
     * Save action type
     * Can be used with the `setData` method to determine the action type
     */
    public string $saveActionType = '';

    /**
     * Save action types
     * 
     * @const string
     */
    const CREATE_ACTION = 'create';
    const UPDATE_ACTION = 'update';
    const PATCH_ACTION = 'patch';

    /**
     * Query Builder Object
     *
     * @var \Illuminate\Database\Eloquent\Builder<TModel>
     */
    protected $query;

    /**
     * Request Object
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * Events Object
     *
     * @var Events
     */
    protected $events;

    /**
     * Old model object
     * Works with update method only
     *
     * @var TModel|null
     */
    protected $oldModel;



    /**
     * Constructor
     */
    public function __construct(Request $request, Events $events)
    {
        $this->request = $request;

        $this->events = $events;

        $this->user = user();

        $this->eventName = static::EVENT ?: static::NAME;

        $this->boot();

        // register events
        $this->registerEvents();
    }

    /**
     * Register repository events
     *
     * @return void
     */
    protected function registerEvents()
    {
        if (!$this->eventName) return;

        foreach (static::EVENTS_LIST as $eventName => $methodCallback) {
            if (method_exists($this, $methodCallback)) {
                $this->events->subscribe("{$this->eventName}.$eventName", static::class . '@' . $methodCallback);
            }
        }
    }

    /**
     * Trigger the given event related to current repository
     *
     * @param  mixed ...$values
     * @return mixed
     */
    public function trigger(string $events, ...$values)
    {
        $repositoryEvents = array_map(fn($event) => "repository.{$event}", explode(' ', $events));

        $returningValueFromRepositoryEvent = $this->events->trigger(implode(' ', $repositoryEvents), $this, ...$values);

        if ($returningValueFromRepositoryEvent) {
            return $returningValueFromRepositoryEvent;
        }

        $events = array_map(fn($event) => "{$this->eventName}.{$event}", explode(' ', $events));

        return $this->events->trigger(implode(' ', $events), ...$values);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string,mixed> $options
     * @return Collection<int, TModel>
     */
    public function list(array $options): Collection
    {
        $this->initiateListing($options);

        $this->trigger("listing", $this->query);

        $paginate = $this->option('paginate', static::PAGINATE);

        if ($this->request->input('paginate') === 'false') {
            $paginate = false;
        }

        if ($paginate === true || $paginate === null && config('mongez.repository.pagination.paginate') === true) {
            $pageNumber = $this->option('page', 1);

            $itemPerPage = (int) $this->option(
                'itemsPerPage',
                $this->option('limit', static::ITEMS_PER_PAGE ?? config('mongez.repository.pagination.itemsPerPage'))
            );

            $selectedColumns = !empty($this->select->list()) ? $this->select->list() : ['*'];

            $data = $this->query->paginate($itemPerPage, $selectedColumns, 'page', $pageNumber);

            $this->setPaginateInfo($data);

            $records = collect($data->items());
        } else {
            if ($this->select->isNotEmpty()) {
                $this->query->select(...$this->select->list());
            }

            if ($limit = $this->option('limit')) {
                $this->query->limit((int) $limit);
            }

            $records = $this->query->get();
        }

        $records = $this->records($records);

        $results = $this->trigger("list", $records);

        if ($results instanceof Collection) {
            return $results;
        }

        return $records;
    }

    /**
     * Get table name of the primary model of the repo
     */
    public function getTableName(): string
    {
        if (static::TABLE) {
            return static::TABLE;
        }

        /** @var \HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model $model */
        $model = $this->newModel();

        return $model->getTableName();
    }

    /**
     * Get the query handler
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function getQuery()
    {
        $model = static::MODEL;
        return $model::query();
    }

    /**
     * Get new model object
     *
     * @param  array<string,mixed> $data
     * @return TModel
     */
    public function newModel($data = []): \HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model
    {
        $modelName = static::MODEL;

        /** @var TModel $model */
        $model = new $modelName($data);

        return $model;
    }

    /**
     * create get an instance for the given class RepositoryManager
     *
     * @return mixed
     */
    protected function make(string $className)
    {
        return App::make($className);
    }

    /**
     * {@inheritDoc}
     *
     * @param  \Illuminate\Http\Request|array<string, mixed> $data
     * @return TModel
     */
    public function create($data)
    {
        /** @var TModel $model */
        $model = $this->newModel();

        $this->saveActionType = static::CREATE_ACTION;

        $this->request = $this->getRequestWithData($data);

        $this->setAutoData($model);

        $this->setData($model, $this->request);

        $this->save($model);

        return $model;
    }

    /**
     * Create new record and wrap it into resource
     *
     * @param  \Illuminate\Http\Request|array<string, mixed> $data
     * @return \Illuminate\Http\Resources\Json\JsonResource|array<string, mixed>
     */
    public function createWrap($data)
    {
        return $this->wrap(
            $this->create($data)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param  int|\Illuminate\Database\Eloquent\Model $id
     * @param  \Illuminate\Http\Request|array<string, mixed> $data
     * @return TModel|null
     */
    public function update($id, $data)
    {
        $model = $this->getModel($id);

        if (!$model) return null;

        $this->saveActionType = static::UPDATE_ACTION;

        $oldModel = clone $model;

        $this->oldModel = $oldModel;

        $this->request = $this->getRequestWithData($data);

        $this->setAutoData($model);

        $this->setData($model, $this->request);

        $this->save($model, $oldModel);

        return $model;
    }

    /**
     * PATCH request handler
     *
     * @param int|\Illuminate\Database\Eloquent\Model $id
     * @param \Illuminate\Http\Request|array<string, mixed> $data
     * @return TModel|null
     */
    public function patch($id, $data)
    {
        $model = $this->getModel($id);

        if (!$model) return null;

        $this->saveActionType = static::PATCH_ACTION;

        $oldModel = clone $model;

        $this->oldModel = $oldModel;

        $this->request = $this->getRequestWithData($data);

        $this->forceIgnore = true;

        $this->setAutoData($model);

        $this->setData($model, $this->request);

        $this->forceIgnore = false;

        $this->save($model, $oldModel);

        return $model;
    }

    /**
     * Update the record and wrap it into resource
     *
     * @param  \Illuminate\Http\Request|array<string, mixed> $data
     * @return \Illuminate\Http\Resources\Json\JsonResource|array<string, mixed>|null
     */
    public function updateWrap(int $id, $data)
    {
        $model = $this->update($id, $data);

        return $model ? $this->wrap($model) : null;
    }

    /**
     * If the given id exists then we will retrieve an existing record
     * otherwise, create new model
     *
     * @template TFindModel of \Illuminate\Database\Eloquent\Model
     * @param  class-string<TFindModel> $model
     * @return TFindModel
     */
    protected function findOrCreate(string $model, int $id): Model
    {
        return $model::query()->find($id) ?: new $model;
    }

    /**
     * Set data to the model
     * This method is triggered on create and update as it will be a useful
     * method to set model data once instead of adding it on create and adding it again on update
     *
     * In simple words, add common fields between create and update using this method
     *
     * @param  TModel $model
     * @return void
     */
    abstract protected function setData($model, Request $request);

    /**
     * Get the actual column name for the given column in the underlying store.
     */
    abstract protected function column(string $column): string;

    /**
     * Handle the given arrayable value before storing
     *
     * @param  array $value
     * @return mixed
     */
    /**
     * Handle the given arrayable value before storing
     *
     * @param  array<int|string, mixed> $value
     */
    abstract protected function handleArrayableValue(array $value): mixed;

    /**
     * Update record for the given model
     *
     * @param  TModel $model
     * @param  array<int|string, mixed> $columns
     */
    protected function updateModel(Model $model, array $columns): void
    {
        // available syntax for the columns
        // 1- Associative array: ['name' => 'My Name', 'email' => 'MY@email.com']
        // 2- Indexed array: it means we will get the value from the request object
        // i.e ['name', 'email'] then it will get it like:
        // $model->name = $request->name and so on

        foreach ($columns as $key => $value) {
            if (is_string($key)) {
                $model->$key = $value;
            } else {
                $model->$value = $this->input($value);
            }
        }

        $model->save();
    }

    /**
     * Set the given data to the given model `without` saving it
     *
     * @param  TModel $model
     * @param  array<string, mixed> $columns
     */
    protected function setModelData(Model $model, array $columns): void
    {
        $model->fill($columns);
    }

    /**
     * Remove the given file path from storage
     *
     * @return mixed
     */
    public function unlink(string $path)
    {
        return Storage::delete($path);
    }

    /**
     * Saving triggers
     *
     * @param TModel $model
     * @param TModel|null $oldModel
     * @return void
     */
    protected function save($model, $oldModel = null)
    {
        if ($oldModel) {
            $this->trigger("saving updating", $model, $this->request, $oldModel);
            $model->save();
            $this->trigger("save update", $model, $this->request, $oldModel);
        } else {
            $this->trigger("saving creating", $model, $this->request);
            $model->save();
            $this->trigger("save create", $model, $this->request);
        }

        $this->saveActionType = '';

        // Cache revalidation is handled by the model's saved event (CacheableModel).
    }

    /**
     * Make basic operations on any entered request
     *
     * @return void
     */
    protected function boot()
    {
    }

    /**
     * Call query builder methods dynamically
     *
     * @param  string $method
     * @param  array<int, mixed> $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        if ($this->query && method_exists($this->query, $method)) {
            return $this->query->$method(...$args);
        }

        $translation = $this->translate($method, $args);

        if ($translation) return $translation;

        return $this->marcoableMethods($method, $args);
    }

    /**
     * Increment the given mode|id by the given value
     *
     * @param  int|TModel $model
     * @return TModel|null
     */
    public function increment($model, string $column, int $incrementBy = 1)
    {
        if (is_numeric($model)) {
            $model = $this->getModel($model);
        }

        if (!$model) return null;

        $model->{$column} = ($model->{$column} ?? 0) + $incrementBy;

        $model->newQuery()->whereKey($model->getKey())->increment($column, $incrementBy);

        $model->refresh();

        return $model;
    }

    /**
     * Decrement the given mode|id by the given value
     *
     * @param  int|TModel $model
     * @return TModel|null
     */
    public function decrement($model, string $column, int $decrementBy = 1)
    {
        if (is_numeric($model)) {
            $model = $this->getModel($model);
        }

        if (!$model) return null;

        $model->{$column} = ($model->{$column} ?? 0) - $decrementBy;

        $model->newQuery()->whereKey($model->getKey())->decrement($column, $decrementBy);

        $model->refresh();

        return $model;
    }

    /**
     * chunk query after applying filters
     *
     * @param  array<string, mixed> $options
     * @return bool
     */
    public function chunkModels(array $options, int $count, callable $callback)
    {
        $this->initiateListing($options);

        $this->trigger("listing", $this->query);

        if ($this->select->isNotEmpty()) {
            $this->query->select(...$this->select->list());
        }

        return $this->query->chunk($count,  $callback);
    }
}
