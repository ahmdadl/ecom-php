<?php

namespace HZ\Illuminate\Mongez\Repository\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use HZ\Illuminate\Mongez\Repository\Select;
use Illuminate\Http\Resources\Json\JsonResource;
use HZ\Illuminate\Mongez\Database\Filters\FilterManager;

/**
 * @phpstan-require-extends \HZ\Illuminate\Mongez\Repository\RepositoryManager
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
trait Listable
{
    /**
     * Select Helper Object
     *
     * @var \HZ\Illuminate\Mongez\Repository\Select
     */
    protected $select;

    /**
     * Options list
     *
     * @var array<string, mixed>
     */
    protected $options = [];

    /**
     * Pagination info
     *
     * @var array<string, mixed>
     */
    protected $paginationInfo = [];

    /**
     * Current used resource class, 
     * defaults to static::RESOURCE
     * 
     * @var string
     */
    protected $currentResource;

    /**
     * Current used resource
     * 
     * @var string
     */
    protected static $currentDefaultResource = '';

    /**
     * {@inheritDoc}
     */
    public function has($value, string $column = 'nid'): bool
    {
        if (is_numeric($value)) {
            $value = (float) $value;
        }

        $model = static::MODEL;

        /** @var TModel $modelInstance */
        $modelInstance = new $model;

        return $modelInstance->newQuery()->where($column, $value)->exists();
    }

    /**
     * Use the given resource class
     * 
     * @param  string $resourceClass
     * @return $this
     */
    public function useResource(string $resourceClass): self
    {
        $this->currentResource = $resourceClass;

        return $this;
    }

    /**
     * Set current used resource
     * 
     * @param  string $resourceClass
     * @return void
     */
    public static function setCurrentResource(string $resourceClass)
    {
        static::$currentDefaultResource = $resourceClass;
    }

    /**
     * Reset the current used resource
     *
     * This is used between requests when running on Laravel Octane
     * to make sure the resource doesn't leak from one request to another.
     *
     * @return void
     */
    public static function resetCurrentResource()
    {
        static::$currentDefaultResource = '';
    }

    /**
     * Get current used resource class Listable
     * 
     * @return string
     */
    public function getResourceClass(): string
    {
        if ($this->currentResource) return $this->currentResource;

        if (static::$currentDefaultResource) return static::$currentDefaultResource;

        return static::RESOURCE;
    }

    /**
     * Get a normal record by id
     * Please use the `get` method to get full details about the record
     *
     * @param  int $id
     * @return TModel|null
     */
    public function find(int $id)
    {
        return $this->getModel($id);
    }

    /**
     * Get list of models for the given options
     *
     * @param  array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listModels(array $options)
    {
        $options['as-model'] = true;

        return $this->list($options);
    }

    /**
     * Get total records based on given options
     *
     * @param array<string, mixed> $options
     * @return int
     */
    public function total(array $options)
    {
        $options['paginate'] = false;
        unset($options['page']);

        $this->initiateListing($options);

        return $this->query->count();
    }

    /**
     * Initiate listing info
     *
     * @param  array<string, mixed> $options
     * @return void
     */
    protected function initiateListing(array $options)
    {
        $this->setOptions($options);

        $this->query = $this->getQuery();

        $this->trigger("listing", $this->query);

        $this->select();

        $filterManger = new FilterManager($this->query, $options, static::FILTER_BY);
        $filterManger->filter(array_merge(static::FILTERS, config('mongez.filters', [])));

        $this->filter();

        $defaultOrderBy = [];

        if ($orderBy = $this->option('orderBy')) {
            $defaultOrderBy = $orderBy;
        } elseif (!empty(static::ORDER_BY)) {
            $defaultOrderBy = [$this->column(static::ORDER_BY[0]), static::ORDER_BY[1]];
        }

        $this->orderBy($defaultOrderBy);
    }

    /**
     * Get publish Model
     *
     * @param int $id
     * @return TModel|null
     */
    public function getPublishedModel($id)
    {
        $model = $this->getModel($id);

        if (!$model || !$model->{$this->getPublishedColumn()}) return null;

        return $model;
    }

    /**
     * Get publish item
     *
     * @param int $id
     * @return \Illuminate\Http\Resources\Json\JsonResource|null
     */
    public function getPublished($id)
    {
        $record = $this->getPublishedModel($id);

        if (!$record) return null;

        return $this->wrap($record);
    }

    /**
     * Get published items
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listPublished(array $options = [])
    {
        $options[$this->getPublishedColumn()] = true;

        return $this->list($options);
    }

    /**
     * Alias to listPublished
     *
     * @deprecated
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function published(array $options = [])
    {
        return $this->listPublished($options);
    }

    /**
     * Publish/Unpublish the model id
     *
     * @param int $id
     * @param bool $publishState
     * @return void
     */
    public function publish($id, $publishState)
    {
        $this->getQuery()->where('nid', (int) $id)->update([
            $this->getPublishedColumn() => (bool) $publishState
        ]);
    }

    /**
     * Set pagination info from pagination data
     *
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, mixed> $data
     * @return void
     */
    protected function setPaginateInfo(\Illuminate\Contracts\Pagination\LengthAwarePaginator $data): void
    {
        $this->paginationInfo = [
            'currentResults' => $data->total(),
            'totalRecords' => $data->total(),
            'numberOfPages' => $data->lastPage(),
            'itemsPerPage' => $data->perPage(),
            'currentPage' => $data->currentPage()
        ];
    }

    /**
     * Get pagination info
     *
     * @deprecated use getPaginationInfo instead
     * @return array<string, mixed> $paginationInfo
     */
    public function getPaginateInfo(): array
    {
        return $this->paginationInfo;
    }

    /**
     * Get pagination info
     *
     * @return array<string, mixed> $paginationInfo
     */
    public function getPaginationInfo(): array
    {
        return $this->paginationInfo;
    }

    /**
     * Wrap the given model to its resource
     *
     * @param \Illuminate\Database\Eloquent\Model|array<string, mixed> $model
     * @return \Illuminate\Http\Resources\Json\JsonResource
     */
    public function wrap($model): JsonResource
    {
        if (is_array($model)) {
            $model = $this->newModel($model);
        }

        $resource = $this->getResourceClass();

        /** @var JsonResource $result */
        $result = new $resource($model);

        return $result;
    }

    /**
     * Wrap the given collection into collection of resources
     *
     * @param \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>|array<string, mixed> $collection
     * @return \Illuminate\Http\Resources\Json\ResourceCollection|array<string, mixed>
     */
    public function wrapMany($collection)
    {
        $collection = collect($collection);

        if ($collection->isEmpty()) return [];

        $collection = $collection->map(function ($item) {
            if (is_array($item)) {
                $modelName = static::MODEL;
                $item = new $modelName($item);
            }

            return $item;
        });

        $resource = $this->getResourceClass();
        return $resource::collection($collection);
    }

    /**
     * This method mainly used to filtering records `the where clause`
     *
     * @return void
     */
    abstract protected function filter();

    /**
     * Manage Selected Columns
     *
     * @return void
     */
    abstract protected function select();

    /**
     * Perform records ordering
     *
     * @param   array $orderBy
     * @return  void
     */
    /**
     * @param array<string, mixed> $orderBy
     */
    protected function orderBy(array $orderBy): void
    {
        if (empty($orderBy)) return;

        if ($this->query === null) return;

        // If there is no zero index in the array
        // it means the order will be for multiple columns
        if (!isset($orderBy[0])) {
            foreach ($orderBy as $column => $columnOrder) {
                $this->query->orderBy($column, $columnOrder);
            }
        } else {
            $this->query->orderBy(...$orderBy);
        }
    }

    /**
     * Set options list
     *
     * @param array<string, mixed> $options
     * @return void
     */
    protected function setOptions(array $options): void
    {
        $this->options = $options;

        $selectColumns = (array) $this->option('select');

        $this->select = new Select($selectColumns);
    }

    /**
     * Get option value
     *
     * @param  string $key
     * @param  mixed $default
     * @return mixed
     */
    protected function option(string $key, $default = null)
    {
        $value = Arr::get($this->options, $key, $default);

        if ($value === 'false') {
            $value = false;
        } elseif ($value === 'true') {
            $value = true;
        }

        return $value;
    }

    /**
     * Get published column
     * 
     * @return string
     */
    protected function getPublishedColumn(): string
    {
        return defined('static::PUBLISHED_COLUMN') ? static::PUBLISHED_COLUMN :
            config('mongez.repository.publishedColumn', static::DEFAULT_PUBLISHED_COLUMN);
    }

    /**
     * Get only one record based on the given options
     *
     * @param array<string, mixed> $options
     * @return TModel|null
     */
    public function first(array $options)
    {
        $options['limit'] = 1;

        return $this->listModels($options)->first();
    }

    /**
     * A shorthand method for filtering data if they are available
     * 
     * @param  string $column
     * @param  string|null $option
     * @return $this
     */
    protected function where(string $column, ?string $option = null): self
    {
        if (!$option) {
            $option = $column;
        }

        if ($this->query === null) return $this;

        if ($optionValue = $this->option($option)) {
            $this->query->where($column, $optionValue);
        }

        return $this;
    }

    /**
     * A shorthand method for filtering data if they are available
     * 
     * @param  string $column
     * @param  string|null $option
     * @return $this
     */
    protected function whereIn(string $column, ?string $option = null): self
    {
        if (!$option) {
            $option = $column;
        }

        if ($this->query === null) return $this;

        if ($optionValue = $this->option($option)) {
            $this->query->whereIn($column, (array) $optionValue);
        }

        return $this;
    }

    /**
     * A shorthand method for filtering data if they are available
     * 
     * @param  string $column
     * @param  string|null $option
     * @return $this
     */
    protected function whereInInt(string $column, ?string $option = null): self
    {
        if (!$option) {
            $option = $column;
        }

        if ($this->query === null) return $this;

        if ($optionValue = $this->option($option)) {
            $this->query->whereIn($column, array_map('intval', (array) $optionValue));
        }

        return $this;
    }

    /**
     * Adjust records that were fetched from database
     *
     * @param \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $records
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    protected function records(Collection $records): Collection
    {
        $hasArrayableData = !empty(static::ARRAYBLE_DATA);
        return $records->map(function ($record) use ($hasArrayableData) {
            if ($hasArrayableData) {
                foreach (static::ARRAYBLE_DATA as $column) {
                    $record[$column] = $this->decodeArray($record[$column]);
                }
            }

            if ($this->option('as-model', false) === true) return $record;

            $resource = $this->getResourceClass();

            return new $resource((object) $record);
        });
    }

    /**
     * Decode the array, which should be a string if you're working with mysql
     * or just an array if you work with NO-SQL database
     *
     * @param  mixed $data
     * @return array<string, mixed>
     */
    protected function decodeArray($data): array
    {
        if (is_string($data)) {
            return json_decode($data, true) ?: [];
        }

        return $data ?: [];
    }

    /**
     * {@inheritDoc}
     */
    public function get(int $id)
    {
        return $this->getBy('nid', (int) $id);
    }

    /**
     * Get model for the given id
     *
     * @param  int|array<string, mixed>|\Illuminate\Database\Eloquent\Model $id
     * @return TModel|null
     */
    public function getModel($id)
    {
        if ($id instanceof Model) {
            /** @var TModel $id */
            return $id;
        }

        return $this->getByModel('nid', (int) $id);
    }

    /**
     * Get by the given column name
     *
     * @param  string $column
     * @param  mixed $value
     * @return mixed
     */
    public function getBy($column, $value)
    {
        if ($this->isCachable()) {
            $cacheKey = static::NAME . '_' . $column . '_' . (string) $value;
            $record = $this->getCache($cacheKey);

            if (!$record) {
                $record = $this->getByModel($column, $value);

                if (!$record) return null;

                $this->setCache($cacheKey, $record->toArray());
            } else {
                $record = $this->newModel($record);
            }

            return $this->wrap($record);
        }

        $record = $this->getByModel($column, $value);

        return $record ? $this->wrap($record) : null;
    }

    /**
     * Get the current model by the given column name and value
     *
     * @param  string $column
     * @param  mixed $value
     * @return TModel|null
     */
    public function getByModel($column, $value)
    {
        $model = static::MODEL;

        /** @var TModel $modelInstance */
        $modelInstance = new $model;

        $query = $modelInstance->newQuery()->where($column, $value);

        $this->trigger('fetching', $query);

        return $query->first();
    }

    /**
     * list all records
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listAll(array $options = [])
    {
        $options['paginate'] = false;
        unset($options['page']);
        unset($options['limit']);

        return $this->list($options);
    }

    /**
     * list all published records
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listAllPublished(array $options = [])
    {
        $options[$this->getPublishedColumn()] = true;

        return $this->listAll($options);
    }

    /**
     * list all models
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listAllModels(array $options)
    {
        $options['as-model'] = true;

        return $this->listAll($options);
    }

    /**
     * list all published models
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listAllPublishedModels(array $options)
    {
        $options[$this->getPublishedColumn()] = true;

        return $this->listAllModels($options);
    }

    /**
     * count all records
     * ? alias to total
     *
     * @param array<string, mixed> $options
     * @return int
     */
    public function count(array $options = [])
    {
        return $this->total($options);
    }

    /**
     * count all published records
     *
     * @param array<string, mixed> $options
     * @return int
     */
    public function countPublished(array $options = [])
    {
        $options[$this->getPublishedColumn()] = true;

        return $this->count($options);
    }
}
