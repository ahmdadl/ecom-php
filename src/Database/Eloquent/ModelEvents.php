<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent;

use Illuminate\Support\Str;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Model;

trait ModelEvents
{
    public static string $modelClass;

    /** @var array<int, array<string, mixed>> */
    public static array $modelOptions = [];

    public static string $sharedInfoMethod = 'sharedInfo';

    /**
     * Reset the temporary static state used during model events.
     *
     * The model class, options and shared info method are mutated while
     * handling create/update/delete events. If not cleared they would leak
     * into subsequent requests on persistent workers (e.g. Laravel Octane).
     */
    public static function resetState(): void
    {
        static::$modelClass = '';
        static::$modelOptions = [];
        static::$sharedInfoMethod = 'sharedInfo';
    }

    /**
     * Determine whether related-model propagation should run on a queue by default.
     *
     * @return bool
     */
    public static function shouldRunRelatedModelsOnQueue(): bool
    {
        $mode = app()->bound('config')
            ? config('mongez.queue.relatedModels', false)
            : false;

        if ($mode === 'false' || $mode === '0' || $mode === 'sync') {
            $mode = false;
        }

        if ($mode === false && defined('static::RELATED_MODELS_QUEUE_MODE')) {
            $mode = static::RELATED_MODELS_QUEUE_MODE;
        }

        return $mode === true || $mode === 'queue';
    }

    /**
     * Determine whether propagation to the given related model should run on a queue.
     *
     * @param class-string $targetModelClass
     * @return bool
     */
    public static function shouldRunRelatedModelOnQueue(string $targetModelClass): bool
    {
        if (defined('static::RELATED_MODELS_MODES') && isset(static::RELATED_MODELS_MODES[$targetModelClass])) {
            $mode = static::RELATED_MODELS_MODES[$targetModelClass];

            return $mode === true || $mode === 'queue';
        }

        return static::shouldRunRelatedModelsOnQueue();
    }

    /**
     * Handle model create events.
     *
     * @param Model $model
     * @return void
     */
    public static function handleCreated(Model $model)
    {
        static::handleCreateSingleModel($model);

        static::handleCreateArrayModel($model);
    }

    /**
     * Handle model update events.
     *
     * @param Model $model
     * @return void
     */
    public static function handleUpdated(Model $model)
    {
        static::handleUpdateSingleModel($model);

        static::handleUpdateArrayModel($model);
    }

    /**
     * Handle model delete events.
     *
     * @param Model $model
     * @return void
     */
    public static function handleDeleted(Model $model)
    {
        static::handleUnsetSingleModel($model);

        static::handlePullArrayModel($model);

        static::handleDeleteSingleModel($model);
    }

    /**
     * Handle create model record as single document in related models.
     *
     * @return void
     */
    public static function handleCreateSingleModel(Model $model, ?string $targetModelClass = null)
    {
        $singleModelsList = array_merge(
            config('mongez.database.onModel.create.' . static::class, []),
            !empty(static::ON_MODEL_CREATE) ? static::ON_MODEL_CREATE : [],
            !empty(static::MODEL_LINKS) ? static::MODEL_LINKS : [],
        );

        collect($singleModelsList)->each(function ($modelOptions, $modelClass) use ($model, $targetModelClass) {
            if ($targetModelClass !== null && $modelClass !== $targetModelClass) return;

            static::$modelClass = $modelClass;

            static::setCreateModelOptions($model, $modelOptions);

            collect(static::$modelOptions)->each(function ($options) use ($model) {
                $records = static::getCreateRelatedModels($model, $options);

                foreach ($records as $record) {
                    $record->{$options['foreignColumn']} = $model->{$options['sharedInfoMethod']}();

                    $record->save();
                }
            });
        });
    }

    /**
     * Handle create model record as array of documents in related models.
     *
     * @param Model $model
     * @return void
     */
    public static function handleCreateArrayModel(Model $model, ?string $targetModelClass = null)
    {
        $arrayModelsList = array_merge(
            config('mongez.database.onModel.createArray.' . static::class, []),
            !empty(static::ON_MODEL_CREATE_PUSH) ? static::ON_MODEL_CREATE_PUSH : [],
            !empty(static::MODEL_LINKS_ARRAY) ? static::MODEL_LINKS_ARRAY : [],
        );

        collect($arrayModelsList)->each(function ($modelOptions, $modelClass) use ($model, $targetModelClass) {
            if ($targetModelClass !== null && $modelClass !== $targetModelClass) return;

            static::$modelClass = $modelClass;

            static::setCreateModelOptions($model, $modelOptions);

            collect(static::$modelOptions)->each(function ($options) use ($model) {
                $records = static::getCreateRelatedModels($model, $options);

                foreach ($records as $record) {
                    $record->reassociate($model->{$options['sharedInfoMethod']}(), $options['foreignColumn'])->save();
                }
            });
        });
    }

    /**
     * Handle update model record as single document in related models.
     *
     * @return void
     */
    public static function handleUpdateSingleModel(Model $model, ?string $targetModelClass = null)
    {
        $singleModelsList = array_merge(
            config('mongez.database.onModel.update.' . static::class, []),
            !empty(static::ON_MODEL_UPDATE) ? static::ON_MODEL_UPDATE : [],
            !empty(static::MODEL_LINKS) ? static::MODEL_LINKS : [],
        );

        // the model options is can be an string or array
        // the array can have up to 3 elements: search-column, updating field and shared info method
        // if the model options is set to string, then it will be converted to
        // $modelOptions.id, $modelOptions, sharedInfo

        collect($singleModelsList)->each(function ($modelOptions, $modelClass) use ($model, $targetModelClass) {
            if ($targetModelClass !== null && $modelClass !== $targetModelClass) return;

            static::$modelClass = $modelClass;

            static::setModelOptions($modelOptions);

            collect(static::$modelOptions)->each(function ($options) use ($model) {
                $records = static::getRelatedModels($model, $options);

                foreach ($records as $record) {
                    $record->{$options['foreignColumn']} = $model->{$options['sharedInfoMethod']}();

                    $record->save();
                }
            });
        });
    }

    /**
     * Handle update model record as array of documents in related models.
     *
     * @return void
     */
    public static function handleUpdateArrayModel(Model $model, ?string $targetModelClass = null)
    {
        $arrayModelsList = array_merge(
            config('mongez.database.onModel.updateArray.' . static::class, []),
            !empty(static::ON_MODEL_UPDATE_ARRAY) ? static::ON_MODEL_UPDATE_ARRAY : [],
            !empty(static::MODEL_LINKS_ARRAY) ? static::MODEL_LINKS_ARRAY : [],
        );

        // the model options is can be an string or array
        // the array can have up to 3 elements: search-column, updating field and shared info method
        // if the model options is set to string, then it will be converted to
        // $modelOptions.id, $modelOptions, sharedInfo

        collect($arrayModelsList)->each(function ($modelOptions, $modelClass) use ($model, $targetModelClass) {
            if ($targetModelClass !== null && $modelClass !== $targetModelClass) return;

            static::$modelClass = $modelClass;

            static::setModelOptions($modelOptions);

            collect(static::$modelOptions)->each(function ($options) use ($model) {
                $records = static::getRelatedModels($model, $options);

                foreach ($records as $record) {
                    $record->reassociate($model->{$options['sharedInfoMethod']}(), $options['foreignColumn'])->save();
                }
            });
        });
    }

    /**
     * Handle unset model record as documents in related models.
     *
     * @param Model $model
     * @return void
     */
    public static function handleUnsetSingleModel(Model $model, ?string $targetModelClass = null)
    {
        $singleModelsList = array_merge(
            config('mongez.database.onModel.deleteUnset.' . static::class, []),
            !empty(static::ON_MODEL_DELETE_UNSET) ? static::ON_MODEL_DELETE_UNSET : [],
            !empty(static::MODEL_LINKS) ? static::MODEL_LINKS : [],
        );

        collect($singleModelsList)->each(function ($searchingOptions, $modelClass) use ($model, $targetModelClass) {
            if ($targetModelClass !== null && $modelClass !== $targetModelClass) return;

            static::$modelClass = $modelClass;

            static::setModelOptions($searchingOptions);

            collect(static::$modelOptions)->each(function ($options) use ($model) {
                $records = static::getRelatedModels($model, $options);

                foreach ($records as $record) {
                    $record->unset($options['foreignColumn']);
                    // Force saving again as the model in some is not triggering the update event
                    // so we will force the update by updating the updatedAt column;
                    $record->updatedAt = now();
                    $record->save();
                }
            });
        });
    }

    /**
     * Handle pull model record as array of documents in related models.
     *
     * @param Model $model
     * @return void
     */
    public static function handlePullArrayModel(Model $model, ?string $targetModelClass = null)
    {
        $arrayModelsList = array_merge(
            config('mongez.database.onModel.deletePull.' . static::class, []),
            !empty(static::ON_MODEL_DELETE_PULL) ? static::ON_MODEL_DELETE_PULL : [],
            !empty(static::MODEL_LINKS_ARRAY) ? static::MODEL_LINKS_ARRAY : [],
        );

        collect($arrayModelsList)->each(function ($searchingOptions, $modelClass) use ($model, $targetModelClass) {
            if ($targetModelClass !== null && $modelClass !== $targetModelClass) return;

            static::$modelClass = $modelClass;

            static::setModelOptions($searchingOptions);

            collect(static::$modelOptions)->each(function ($options) use ($model) {
                $records = static::getRelatedModels($model, $options);

                foreach ($records as $record) {
                    $record->disassociate($model, $options['foreignColumn'])->save();
                }
            });
        });
    }

    /**
     * Handle delete related models of the model record.
     *
     * @param Model $model
     * @return void
     */
    public static function handleDeleteSingleModel(Model $model, ?string $targetModelClass = null)
    {
        $singleModelsList = array_merge(
            config('mongez.database.onModel.delete.' . static::class, []),
            !empty(static::ON_MODEL_DELETE) ? static::ON_MODEL_DELETE : [],
            !empty(static::MODEL_LINKS_DELETE) ? static::MODEL_LINKS_DELETE : [],
        );

        collect($singleModelsList)->each(function ($searchingOptions, $modelClass) use ($model, $targetModelClass) {
            if ($targetModelClass !== null && $modelClass !== $targetModelClass) return;

            static::$modelClass = $modelClass;

            static::setModelOptions($searchingOptions);

            collect(static::$modelOptions)->each(function ($options) use ($model) {
                $records = static::getRelatedModels($model, $options);

                foreach ($records as $record) {
                    $record->delete();
                }
            });
        });
    }

    /**
     * Set model options on create events.
     *
     * @param Model $model
     * @param string|array<int, mixed> $options
     * @return void
     */
    public static function setCreateModelOptions(Model $model, string|array $options)
    {
        $options = static::getOptionsArray($options);

        collect($options)->each(function ($option) use ($model) {
            $modelOptions['searchingColumn'] = $option[0];

            switch (count($option)) {
                case 1:
                    // resolves related (Model::class) namespace to camelCase model name (model)
                    $relationalModel = Str::camel(str_replace('Models\\', '', (string) strstr(static::$modelClass, 'Models')));

                    // searching in the model attributes for key asymptotic to resolved (Model::class) name to get the searching key
                    $foreignColumn = array_key_exists($relationalModel, $model->toArray()) ? $relationalModel :
                        array_key_first(array_filter($model->toArray(), fn($key) => str_contains($key, $relationalModel), ARRAY_FILTER_USE_KEY));

                    $modelOptions['foreignColumn'] = $foreignColumn;
                    $modelOptions['sharedInfoMethod'] = static::$sharedInfoMethod;

                    break;
                case 2:
                    $modelOptions['foreignColumn'] = $option[1];
                    $modelOptions['sharedInfoMethod'] = static::$sharedInfoMethod;

                    break;
                case 3:
                    $modelOptions['foreignColumn'] = $option[1];
                    $modelOptions['sharedInfoMethod'] = $option[2];
            }

            static::$modelOptions[] = $modelOptions;
        });
    }

    /**
     * Set model options on update and delete events.
     *
     * @param string|array<int, mixed> $options
     * @return void
     */
    public static function setModelOptions(string|array $options)
    {
        $options = static::getOptionsArray($options);

        collect($options)->each(function ($option) {
            $modelOptions['searchingColumn'] = Str::contains($option[0], '.nid') ? $option[0] : "{$option[0]}.nid";

            switch (count($option)) {
                case 1:
                    $modelOptions['foreignColumn'] = $option[0];
                    $modelOptions['sharedInfoMethod'] = static::$sharedInfoMethod;

                    break;
                case 2:
                    $modelOptions['foreignColumn'] = $option[1];
                    $modelOptions['sharedInfoMethod'] = static::$sharedInfoMethod;

                    break;
                case 3:
                    $modelOptions['foreignColumn'] = $option[1];
                    $modelOptions['sharedInfoMethod'] = $option[2];
            }

            static::$modelOptions[] = $modelOptions;
        });
    }

    /**
     * Get model options as array of arrays.
     *
     * @param string|array<int, mixed> $options
     * @return array<int, array<int, string>>
     */
    public static function getOptionsArray(string|array $options): array
    {
        static::$modelOptions = [];

        if (is_string($options)) {
            $normalized = [(array) $options];
        } elseif (count($options) === count($options, COUNT_RECURSIVE)) {
            $normalized = [$options];
        } else {
            $normalized = $options;
        }

        /** @var array<int, array<int, string>> $normalized */
        return $normalized;
    }

    /**
     * Get related models records on create events.
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Database\Eloquent\Collection<int, Model>
     */
    public static function getCreateRelatedModels(Model $model, array $options)
    {
        $value = $model->{$options['searchingColumn']};

        if (empty($value)) {
            return static::$modelClass::query()->whereIn('nid', [])->get();
        }

        $items = is_array($value) ? $value : [$value];

        $nids = [];
        foreach ($items as $item) {
            $item = (array) $item;
            if (isset($item['nid'])) {
                $nids[] = (int) $item['nid'];
            }
        }

        return static::$modelClass::query()->whereIn('nid', $nids)->get();
    }

    /**
     * Get related models records on update and delete events.
     *
     * @param array<string, mixed> $options
     * @return \Illuminate\Database\Eloquent\Collection<int, Model>
     */
    public static function getRelatedModels(Model $model, array $options)
    {
        return static::$modelClass::query()->where($options['searchingColumn'], $model->nid)->get();
    }
}
