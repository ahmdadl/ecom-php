<?php

namespace HZ\Illuminate\Mongez\Repository;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Aggregate\Aggregate;

/**
 * @template TModel of Model
 * @extends RepositoryManager<TModel>
 * @implements RepositoryInterface<TModel>
 */
abstract class MongoDBRepositoryManager extends RepositoryManager implements RepositoryInterface
{
    /**
     * If set to true, the multiple uploads column paths will be json encoded while storing it in database.
     *
     * @const bool
     */
    const SERIALIZE_MULTIPLE_UPLOADS = false;

    /**
     * Set the columns will be filled with single record of collection data
     * i.e [country => CountryModel::class]
     * 
     * @const array
     */
    const DOCUMENT_DATA = [];

    /**
     * Set the columns will be filled with array of records.
     * i.e [tags => TagModel::class]
     * 
     * @const array
     */
    const MULTI_DOCUMENTS_DATA = [];

    /**
     * Geo Location data 
     * 
     * @const array
     */
    const LOCATION_DATA = [];

    /**
     * Get the table name that will be used in the query
     *
     */
    protected function tableName(): string
    {
        return $this->getTableName();
    }

    /**
     * Pare the given arrayed value
     */
    protected function handleArrayableValue(array $value): mixed
    {
        return $value;
    }

    /**
     * Get Aggregation framework
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>|null $query
     * @return Aggregate
     */
    public function aggregate(?\Illuminate\Database\Eloquent\Builder $query = null)
    {
        return new Aggregate($query ?: $this->getQuery());
    }

    /**
     * Get shared info data for the given options
     *
     * @param array<string, mixed> $options
     * @return array<int, mixed>
     */
    public function listSharedInfo(array $options, string $sharedInfoMethod = 'sharedInfo'): array
    {
        return $this->listModels($options)->map(fn($model) => $model->$sharedInfoMethod())->toArray();
    }

    /**
     * Get shared info for the given id
     *
     * @param  int $id
     * @return mixed
     */
    public function sharedInfo($id, string $sharedInfoMethod = 'sharedInfo')
    {
        $model = $this->getModel($id);

        return $model ? $model->$sharedInfoMethod() : null;
    }

    /**
     * {@inheritDoc}
     */
    protected function setData($model, Request $request): void {}

    /**
     * {@inheritDoc}
     */
    protected function select() {}

    /**
     * {@inheritDoc}
     */
    protected function filter() {}

    /**
     * {@inheritDoc}
     */
    protected function setAutoData($model)
    {
        parent::setAutoData($model);
        // add the extra methods
        $this->setDocumentData($model);
        $this->setMultiDocumentData($model);
        $this->setLocationData($model);
    }

    /**
     * Set location data
     *
     * @param  TModel $model
     * @return void
     */
    protected function setLocationData($model)
    {
        foreach (static::LOCATION_DATA as $locationKey) {
            $location = $this->input($locationKey);

            if ($location) {
                $model->$locationKey = [
                    'type' => 'Point',
                    'coordinates' => [(float) $location['lat'], (float) $location['lng']],
                    'address' => $location['address'] ?? null,
                ];
            }
        }
    }

    /**
     * Set document data to column
     *
     * @param  TModel $model
     * @return void
     */
    protected function setDocumentData($model)
    {
        foreach (static::DOCUMENT_DATA as $column => $documentModelClass) {
            if ($this->isIgnorable($column)) continue;

            if (is_array($documentModelClass)) {
                [$class, $sharedInfoMethod] = $documentModelClass;
                $documentModelClass = $class;
            } else {
                $sharedInfoMethod = 'sharedInfo';
            }

            $value = $this->input($column);

            $documentModel = $value instanceof Model ? $value : $documentModelClass::find((int) $value);

            $model->$column = $documentModel ? $documentModel->{$sharedInfoMethod}() : null;
        }
    }

    /**
     * Filter by geo locations.
     *
     * @param string $column
     * @param float $distance
     * @return void
     */
    public function whereNearBy($column, $distance)
    {
        $location = $this->option($column);

        if (!$location) return;

            $this->query->whereLocationNear($column, [(float) $location['lat'], (float) $location['lng']], $distance); // @phpstan-ignore method.notFound
    }

    /**
     * A shorthand method for filtering data if they are available
     */
    protected function whereBool(string $column, ?string $option = null): static
    {
        if (!$option) {
            $option = $column;
        }

        if (($optionValue = $this->option($option)) !== null) {
            $this->query->where($column, (bool) $optionValue);
        }

        return $this;
    }

    /**
     * Set Multi documents data to column value.
     *
     * @param  TModel $model
     * @return void
     */
    protected function setMultiDocumentData($model)
    {
        foreach (static::MULTI_DOCUMENTS_DATA as $column => $documentModelClass) {
            if ($this->isIgnorable($column)) continue;

            $value = $this->input($column);

            if (!$value) {
                $model->$column = [];
                continue;
            }

            $ids = array_map(intVal(...), $value);

            if (is_array($documentModelClass)) {
                [$class, $method] = $documentModelClass;
                $documentModelClass = $class;
            } else {
                $method = 'sharedInfo';
            }

            $records = $documentModelClass::whereIn('nid', $ids)->get();

            $records = $records->map(fn($record) => $record->$method())->toArray();

            // make sure it is stored in same order as sent from request
            if (count($ids) > 1) {
                $recordsValues = array_flip($ids);
                usort($records, function ($recordA, $recordB) use ($recordsValues) {
                    if ($recordsValues[$recordA['nid']] === $recordsValues[$recordB['nid']]) return 0;
                    if ($recordsValues[$recordA['nid']] < $recordsValues[$recordB['nid']]) return -1;

                    return 1;
                });
            }

            $model->$column = $records;
        }
    }

    /**
     * Remove an embedded related document from the parent record.
     *
     * @param  int $id Parent model nid
     * @param  Model $related Related model (or sharedInfo-shaped source) to match by nid
     * @param  string $key Embedded attribute name on the parent
     */
    public function disassociate(int $id, Model $related, string $key): void
    {
        $parent = $this->getModel($id);

        if (!$parent) {
            return;
        }

        $parent->disassociate($related, $key)->save();
    }

    /**
     * Replace or append an embedded related document on the parent record.
     *
     * @param  int $id Parent model nid
     * @param  Model $related Related model whose sharedInfo will be stored
     * @param  string $key Embedded attribute name on the parent
     */
    public function reassociate(int $id, Model $related, string $key): void
    {
        $parent = $this->getModel($id);

        if (!$parent) {
            return;
        }

        $parent->reassociate($related, $key)->save();
    }

    /**
     * Partially update one embedded array element without rewriting siblings.
     *
     * @param  int $id Parent model nid
     * @param  string $path Embedded array attribute (e.g. `items`)
     * @param  int|array<string, mixed> $matchNidOrCriteria Match by nid or attribute criteria
     * @param  array<string, mixed> $data Fields merged into the matched element
     * @param  bool $createIfMissing Append when no element matches
     * @return TModel|null
     */
    public function patchEmbedded(
        int $id,
        string $path,
        int|array $matchNidOrCriteria,
        array $data,
        bool $createIfMissing = false
    ): ?Model {
        $parent = $this->getModel($id);

        if (!$parent) {
            return null;
        }

        $parent->patchEmbedded($path, $matchNidOrCriteria, $data, $createIfMissing)->save();

        return $parent;
    }

    /**
     * Refresh one embedded sharedInfo snapshot from a related model.
     *
     * @param  int $id Parent model nid
     * @param  string $path Singular or list embed attribute
     * @param  Model $related Related model
     * @param  string $searchingColumn Match key inside list embeds (default `nid`)
     * @param  string $sharedInfoMethod Method used to build the snapshot
     * @return TModel|null
     */
    public function refreshEmbeddedSharedInfo(
        int $id,
        string $path,
        Model $related,
        string $searchingColumn = 'nid',
        string $sharedInfoMethod = 'sharedInfo'
    ): ?Model {
        $parent = $this->getModel($id);

        if (!$parent) {
            return null;
        }

        $parent->refreshEmbeddedSharedInfo($path, $related, $searchingColumn, $sharedInfoMethod)->save();

        return $parent;
    }

    /**
     * {@inheritDoc}
     */
    protected function boot() {}

    /**
     * Get column name appended by table|table alias
     */
    protected function column(string $column): string
    {
        return $column;
    }

    /**
     * {@inheritDoc}
     *
     * @param  int|array<string, mixed>|\Illuminate\Database\Eloquent\Model $id
     * @return TModel|null
     */
    public function getModel($id)
    {
        return parent::getModel($id);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $id
     * @return TModel|null
     */
    public function find(int $id)
    {
        return parent::find($id);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $id
     * @return TModel|null
     */
    public function getPublishedModel($id)
    {
        return parent::getPublishedModel($id);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options
     * @return TModel|null
     */
    public function first(array $options)
    {
        return parent::first($options);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $column
     * @param mixed $value
     * @return TModel|null
     */
    public function getByModel($column, $value)
    {
        return parent::getByModel($column, $value);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listModels(array $options)
    {
        return parent::listModels($options);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listPublished(array $options = [])
    {
        return parent::listPublished($options);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function published(array $options = [])
    {
        return parent::published($options);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listAll(array $options = [])
    {
        return parent::listAll($options);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listAllPublished(array $options = [])
    {
        return parent::listAllPublished($options);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listAllModels(array $options)
    {
        return parent::listAllModels($options);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    public function listAllPublishedModels(array $options)
    {
        return parent::listAllPublishedModels($options);
    }
}
