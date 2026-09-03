<?php

namespace HZ\Illuminate\Mongez\Resources;

use DateTime;
use DateTimeInterface;
use IntlDateFormatter;
use Illuminate\Support\Arr;
use MongoDB\BSON\UTCDateTime;
use Illuminate\Support\Fluent;
use Illuminate\Support\Facades\App;
use Illuminate\Database\Eloquent\Model;
use HZ\Illuminate\Mongez\Helpers\Mongez;
use Illuminate\Http\Resources\Json\JsonResource;
use HZ\Illuminate\Mongez\Translation\Traits\Translatable;
use HZ\Illuminate\Mongez\Traits\WithRepositoryAndService;

/**
 * @property int|null $nid
 */
abstract class JsonResourceManager extends JsonResource
{
    use WithRepositoryAndService, Translatable;

    /**
     * Data that must be returned
     *
     * @const array
     */
    public const DATA = [];

    /**
     * String Data
     *
     * @const array
     */
    public const STRING_DATA = [];

    /**
     * Boolean Data
     *
     * @const array
     */
    public const BOOLEAN_DATA = [];

    /**
     * Integer Data
     *
     * @const array
     */
    public const INTEGER_DATA = [];

    /**
     * Float Data
     *
     * @const array
     */
    public const FLOAT_DATA = [];

    /**
     * Geo Location Data
     *
     * @const array
     */
    public const LOCATION_DATA = [];

    /**
     * Object Data
     *
     * @const array
     */
    public const OBJECT_DATA = [];

    /**
     * Set that columns that will be formatted as dates
     * it could be numeric array or associated array to set the date format for certain columns
     *
     * @const array
     */
    public const DATES = [];

    /**
     * Data that has multiple values based on locale codes
     *
     * @const array
     */
    public const LOCALIZED = [];

    /**
     * Data that has multiple values based on locale codes and is transformed using resource
     *
     * @example ['banner' => UploadResource::class]
     * @example ['banner' => [UploadResource::class, 'file']]
     * If the locale is not set, then it will be sent as an array of objects, each object has
     * a localeCode and its text/file value will be sent to the resource to parse it
     *
     * @const array
     */
    public const LOCALIZED_RESOURCE_DATA = [];

    /**
     * Collection of localized data, the localized's data is a resource that needs to be transformed
     *
     * @example ['banners' => UploadResource::class]
     * @example ['banners' => [UploadResource::class, 'file']]
     * If the locale is not set, then it will be sent as an array of objects, each object has 
     * a localeCode and its text/file value will be sent to the resource to parse it
     * 
     * @const array
     */
    public const LOCALIZED_COLLECTABLE_DATA = [];

    /**
     * List of assets that will have a full url if available
     *
     * @const array
     */
    public const ASSETS = [];

    /**
     * Data that will be returned as a resources
     *
     * i.ie [city => CityResource::class]
     *
     * @const array
     */
    public const RESOURCES = [];

    /**
     * Data that will be returned as a collection of resources
     *
     * i.ie [city => CityResource::class]
     *
     * @const array
     */
    public const COLLECTABLE = [];

    /**
     * Data that should be returned if exists
     *
     * @const array
     */
    public const WHEN_AVAILABLE = [];

    /**
     * Set the float round value
     * Defaults to 2
     *
     * @const int
     */
    public const FLOAT_ROUND = 2;

    /**
     * Request object
     *
     * @var \Illuminate\Http\Request|null
     */
    protected $request;

    /**
     * The data that will be returned
     *
     * @var array<string,mixed>
     */
    protected $data = [];

    /**
     * Assets function for generating full url
     *
     * @var callable
     */
    protected $assetsUrlFunction;

    /**
     * List of keys that will be unset before sending
     *
     * @var array<int|string, mixed>
     */
    protected static $disabledKeys = [];

    /**
     * List of keys that will be taken only
     *
     * @var array<int|string, mixed>
     */
    protected static $allowedKeys = [];

    /**
     * Baseline disabled keys captured right after boot.
     *
     * It holds the keys that were disabled during the application boot.
     * When running under Laravel Octane, `reset` restores this baseline so
     * boot-time keys survive while per-request keys are discarded.
     *
     * @var array
     */
    /**
     * Baseline disabled keys captured right after boot.
     *
     * It holds the keys that were disabled during the application boot.
     * When running under Laravel Octane, `reset` restores this baseline so
     * boot-time keys survive while per-request keys are discarded.
     *
     * @var array<int|string, mixed>
     */
    protected static $baseDisabledKeys = [];

    /**
     * Baseline allowed keys captured right after boot.
     *
     * @var array<int|string, mixed>
     */
    protected static $baseAllowedKeys = [];

    /**
     * Cached sub classes list.
     *
     * @var array<int,string>|null
     */
    protected static $subClassesCache;

    /**
     * Number of declared classes when the subclass cache was last built.
     */
    protected static int $declaredClassesCount = -1;

    /**
     * Capture the current disabled and allowed keys as the baseline.
     *
     * This should be called once after the application has booted to keep
     * the boot-time `disable` and `only` calls when the state is reset
     * between requests on Laravel Octane.
     *
     * @return void
     */
    public static function snapshotBaseState()
    {
        static::$baseDisabledKeys = static::$disabledKeys;
        static::$baseAllowedKeys = static::$allowedKeys;
    }

    /**
     * Reset the shared disabled and allowed keys lists
     *
     * This is used between requests when running on Laravel Octane
     * to make sure the keys don't accumulate from one request to another.
     * The boot-time baseline keys are restored, while any keys added during
     * the current request are discarded.
     *
     * @return void
     */
    public static function reset()
    {
        foreach (static::subClasses() as $class) {
            $class::$disabledKeys = static::$baseDisabledKeys;
            $class::$allowedKeys = static::$baseAllowedKeys;
        }
    }

    /**
     * Get the resource classes that share the disabled and allowed keys state
     *
     * @return array<int, string>
     */
    protected static function subClasses(): array
    {
        $declaredClassesCount = count(get_declared_classes());

        if (
            static::$subClassesCache !== null
            && static::$declaredClassesCount === $declaredClassesCount
        ) {
            return static::$subClassesCache;
        }

        static::$declaredClassesCount = $declaredClassesCount;
        $classes = array_merge([static::class], get_declared_classes());

        $classes = array_filter($classes, fn($class) => $class === static::class || is_subclass_of($class, static::class));

        return static::$subClassesCache = array_values(array_unique($classes));
    }

    /**
     * Transform the resource into an array.
     *
     * @param   \Illuminate\Http\Request  $request
     * @return  array<string, mixed>
     */
    public function toArray(?\Illuminate\Http\Request $request = null)
    {
        try {

            $this->request = $request;

            $this->assetsUrlFunction = $this->assetsUrlFunction ?: static::assetsFunction();

            static::DATA && $this->collectData(static::DATA);

            static::STRING_DATA && $this->collectStringData(static::STRING_DATA);

            static::INTEGER_DATA && $this->collectIntegerData(static::INTEGER_DATA);

            static::FLOAT_DATA && $this->collectFloatData(static::FLOAT_DATA);

            static::LOCATION_DATA && $this->collectLocationData(static::LOCATION_DATA);

            static::BOOLEAN_DATA && $this->collectBooleanData(static::BOOLEAN_DATA);

            static::OBJECT_DATA && $this->collectObjectData(static::OBJECT_DATA);

            static::LOCALIZED && $this->collectLocalized(static::LOCALIZED);

            static::LOCALIZED_RESOURCE_DATA && $this->collectLocalizedResources(static::LOCALIZED_RESOURCE_DATA);

            static::LOCALIZED_COLLECTABLE_DATA && $this->collectLocalizedCollection(static::LOCALIZED_COLLECTABLE_DATA);

            static::ASSETS && $this->collectAssets(static::ASSETS);

            static::COLLECTABLE && $this->collectCollectables(static::COLLECTABLE);

            static::RESOURCES && $this->collectResources(static::RESOURCES);

            static::DATES && $this->collectDates(static::DATES);

            $this->extend($request);

            // unset all data from the resource
            foreach (static::$disabledKeys as $key) {
                unset($this->data[$key]);
            }

            if (static::$allowedKeys !== []) {
                foreach (array_keys($this->data) as $key) {
                    if (!in_array($key, static::$allowedKeys)) {
                        unset($this->data[$key]);
                    }
                }
            }
        } catch (\Throwable $th) {
            throw new \Exception(sprintf('Error In Resource %s, %s', static::class, $th->getMessage()), $th->getCode(), $th);
        }

        return $this->data;
    }

    /**
     * Remove value from being sent to response
     *
     * @param mixed ...$keys
     * @return void
     */
    public function remove(...$keys)
    {
        foreach ($keys as $key) {
            unset($this->data[$key]);
        }
    }

    /**
     * Get Resource info
     *
     * @return array<string, mixed>
     */
    public function info()
    {
        return $this->toArray(request());
    }

    /**
     * Set more data from outside the resource
     *
     * @param  string $key
     * @param  mixed $value
     * @return $this
     */
    public function set(int|string $key, $value)
    {
        Arr::set($this->data, $key, $value);

        return $this;
    }

    /**
     * Append the given key from the resource to the data array
     *
     * @return $this
     */
    public function append(string $key)
    {
        return $this->set($key, $this->$key);
    }

    /**
     * Disable the given list of keys
     *
     * @param mixed ...$keys
     */
    public static function disable(...$keys): void
    {
        static::$disabledKeys = array_merge(static::$disabledKeys, $keys);
    }

    /**
     * Disable the given list of keys
     *
     * @param mixed ...$keys
     */
    public static function only(...$keys): void
    {
        static::$allowedKeys = array_merge(static::$allowedKeys, $keys);
    }

    /**
     * Get assets function name
     *
     * @return callable
     */
    public static function assetsFunction()
    {
        return config('mongez.resources.assets', 'url');
    }

    /**
     * Get the full url for the given asset path
     */
    public static function assetsUrl(string $path): string
    {
        return call_user_func(static::assetsFunction(), $path);
    }

    /**
     * Collect resources from array
     *
     * @param array<int|string, mixed> $collection
     * @return mixed
     */
    public static function collectArray($collection)
    {
        return static::collection(collect($collection)->map(fn($resource) => new Fluent($resource)));
    }

    /**
     * Collect mandatory data
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectData(array $columns): JsonResourceManager
    {
        return $this->setData($columns);
    }

    /**
     * Set the given columns data
     *
     * @param array<int|string, mixed> $columns
     * @param callable $valueCallback
     */
    protected function setData(array $columns, ?callable $valueCallback = null): JsonResourceManager
    {
        foreach ($columns as $column => $outputKey) {
            $column = is_numeric($column) ? $outputKey : $column;

            if ($this->ignoreEmptyColumn($column)) continue;

            $this->set($outputKey, $valueCallback ? $valueCallback($column) : $this->value($column));
        }

        return $this;
    }

    /**
     * Collect String Data
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectStringData(array $columns): JsonResourceManager
    {
        return $this->setData($columns, fn($column) => (string) $this->value($column, ''));
    }

    /**
     * Collect Integer Data
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectIntegerData(array $columns): JsonResourceManager
    {
        return $this->setData($columns, fn($column) => (int) $this->value($column, 0));
    }

    /**
     * Collect Float Data
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectFloatData(array $columns): JsonResourceManager
    {
        return $this->setData($columns, fn($column) => round(
            (float) $this->value($column, 0),
            static::FLOAT_ROUND
        ));
    }

    /**
     * Collect location Data
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectLocationData(array $columns): JsonResourceManager
    {
        return $this->setData($columns, function ($column) {
            $location = (array) $this->value($column);

            return (object) [
                'address' => $location['address'] ?? '',
                'lat' => $location['lat'] ?? ($location['coordinates'][0] ?? 0),
                'lng' => $location['lng'] ?? ($location['coordinates'][1] ?? 0),
            ];
        });
    }

    /**
     * Collect Float Data
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectBooleanData(array $columns): JsonResourceManager
    {
        return $this->setData($columns, fn($column) => (bool) $this->value($column, false));
    }

    /**
     * Collect Object Data
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectObjectData(array $columns): JsonResourceManager
    {
        return $this->setData($columns, fn($column) => (object) $this->value($column, []));
    }


    /**
     * Collect localized data
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectLocalized(array $columns): JsonResourceManager
    {
        return $this->setData($columns, fn($column) => $this->locale($column));
    }


    /**
     * Get localized
     *
     * @return mixed
     */
    protected function locale(string $column)
    {
        $value = $this->value($column);

        if (empty($value)) return '';

        if (is_string($value) || !Mongez::requestHasLocaleCode()) return $value;

        $localeCode = Mongez::getRequestLocaleCode();

        // get the localization mode
        // it cn be an object or an array of objects
        $localizationMode = config('mongez.localizationMode', 'array');
        // the OR in the following if conditions is used as a fallback for the data that is
        // not matching the current localization mode
        // for example, if the data is stored as object and the localization mode is an array
        // in that case it will be rendered as an array
        if ($localizationMode === 'array' && isset($value[0]) || isset($value[0])) {
            $valuesList = [];
            foreach ($value as $localizedValue) {
                // check if it is an array of values
                if (isset($localizedValue[0])) {
                    foreach ($localizedValue as $subValue) {
                        if ($subValue['localeCode'] === $localeCode) {
                            $valuesList[] = (string) $subValue['text'];
                        }
                    }
                } else {
                    if ($localizedValue['localeCode'] === $localeCode) {
            return (string) ($value[$localeCode]['text'] ?? '');
                    }
                }
            }
            return $valuesList ?: $value;
        }

        if ($localizationMode === 'object' && isset($value[$localeCode]) || isset($value[$localeCode])) {
            return (string) ($value[$localeCode]['text'] ?? '');
        }

        return $value;
    }

    /**
     * Collect localized collections data as resource
     *
     * @param array<int|string, mixed> $columns
     * @return void
     */
    protected function collectLocalizedCollection(array $columns)
    {
        // 'images' => Upload::class
        foreach ($columns as $column => $resource) {
            if ($this->ignoreEmptyColumn($column)) continue;

            if (is_string($resource)) {
                $resourceData = [
                    'column' => $column,
                    'resource' => $resource,
                    'textKey' => 'text',
                ];
            } else {
                $resourceData = [
                    'column' => $column,
                    'resource' => $resource[0],
                    'textKey' => $resource[1] ?? 'text',
                ];
            }

            $collectionData = $this->value($column, []);

            $collectionResources = [];

            foreach ($collectionData as $item) {
                $resourceData['value'] = $item;
                $resource = $this->localeResource($resourceData);

                if (!$resource) continue;

                if ($resource instanceof JsonResourceManager && $resource->canBeEmbedded($this) === false) continue;

                $collectionResources[] = $resource;
            }

            $this->set($column, $collectionResources);
        }
    }

    /**
     * Collect localized data as resource
     *
     * @param array<int|string, mixed> $columns
     * @return void
     */
    protected function collectLocalizedResources(array $columns)
    {
        // 'banner' => BannerResource::class
        foreach ($columns as $column => $resource) {
            if ($this->ignoreEmptyColumn($column)) continue;

            if (is_string($resource)) {
                $resourceData = [
                    'column' => $column,
                    'resource' => $resource,
                    'textKey' => 'text',
                ];
            } else {
                $resourceData = [
                    'column' => $column,
                    'resource' => $resource[0],
                    'textKey' => $resource[1] ?? 'text',
                ];
            }

            $resourceData['value'] = $this->value($resourceData['column']);

            $resource = $this->localeResource($resourceData);

            if ($resource instanceof JsonResourceManager && $resource->canBeEmbedded($this) === false) return;

            $this->set($column, $resource);
        }
    }

    /**
     * Get localized resource
     *
     * @return mixed
     */
    /**
     * @param array<int|string, mixed> $column
     */
    protected function localeResource(array $column): mixed
    {
        $value = $column['value'];

        if (empty($value)) return null;

        if (is_string($value)) return $value;

        if (!Mongez::requestHasLocaleCode()) {
            if (is_array($value)) {
                $resourceClass = $column['resource'];
                $textKey = $column['textKey'];
                $returnAllValue = [];
                foreach ($value as $localizedValue) {
                    $resource = $this->makeResource($resourceClass, $localizedValue[$textKey]);

                    if ($resource instanceof JsonResourceManager && $resource->canBeEmbedded($this) === false) continue;

                    $returnAllValue[] = [
                        'localeCode' => $localizedValue['localeCode'],
                        $textKey => $resource,
                    ];
                }

                return $returnAllValue;
            }
            return $value;
        }

        $localeCode = Mongez::getRequestLocaleCode();

        // get the localization mode
        // it cn be an object or an array of objects
        $localizationMode = config('mongez.localizationMode', 'array');
        // the OR in the following if conditions is used as a fallback for the data that is
        // not matching the current localization mode
        // for example, if the data is stored as object and the localization mode is an array
        // in that case it will be rendered as an array
        if ($localizationMode === 'array' && isset($value[0]) || isset($value[0])) {
            $valuesList = [];
            foreach ($value as $localizedValue) {
                // check if it is an array of values
                if (isset($localizedValue[0])) {
                    foreach ($localizedValue as $subValue) {
                        if ($subValue['localeCode'] === $localeCode) {
                            return $this->makeResource($column['resource'], $subValue[$column['textKey']]);
                        }
                    }
                } else {
                    if ($localizedValue['localeCode'] === $localeCode) {
                        return $this->makeResource($column['resource'], $localizedValue[$column['textKey']]);
                    }
                }
            }
            return $value;
        }

        if ($localizationMode === 'object' && isset($value[$localeCode]) || isset($value[$localeCode])) {
            return $this->makeResource($column['resource'], $value[$localeCode]);
        }

        return $value;
    }

    /**
     * Collect assets
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectAssets(array $columns): JsonResourceManager
    {
        if ($this->resource instanceof Model) {
            if (method_exists($this->resource, 'info')) {
                $resource = $this->resource->info();
            } else {
                $resource = $this->resource->getAttributes();
            }
        } elseif ($this->resource instanceof Fluent) {
            $resource = $this->resource->toArray();
        } else {
            $resource = (array) $this->resource;
        }

        foreach ($columns as $column => $outputName) {
            if (is_numeric($column)) {
                $column = $outputName;
            }

            if ($this->ignoreEmptyColumn($column)) continue;

            $asset = Arr::get($resource, $column, '');

            if (!$asset) {
                $this->set($column, '');
                continue;
            }

            if (is_string($asset) && is_json($asset)) {
                $asset = json_decode($asset);
            }

            if (is_array($asset)) {
                $assets = [];
                // the key here is very important
                // as it might be an associated key not index
                // i.e image in two or more locales, one image for each
                // locale code
                foreach ($asset as $key => $assetPath) {
                    $assets[$key] = call_user_func($this->assetsUrlFunction, $assetPath);
                }

                $this->set($outputName, $assets);
            } else {
                $this->set($outputName, call_user_func($this->assetsUrlFunction, $asset));
            }
        }

        return $this;
    }

    /**
     * Collect Collectable data
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectCollectables(array $columns): JsonResourceManager
    {
        foreach ($columns as $column => $resource) {
            if ($this->ignoreEmptyColumn($column)) continue;

            $collection = $this->value($column);

            if ($collection !== null) {
                $this->collect($column, $resource, $collection);
            } else {
                $this->set($column, []);
            }
        }

        return $this;
    }

    /**
     * Collect resources data
     *
     * @param array<int|string, mixed> $columns
     */
    protected function collectResources(array $columns): JsonResourceManager
    {
        foreach ($columns as $column => $resource) {
            if ($this->ignoreEmptyColumn($column)) continue;

            $this->setResource($column, $resource);
        }

        return $this;
    }

    /**
     * Determine whether to ignore the empty data for the given column
     *
     * @param string $column
     */
    protected function ignoreEmptyColumn(int|string $column): bool
    {
        $value = $this->value($column);

        if (in_array($value, [0, false], true)) return false;

        if (!empty($value) || is_array($value)) return false;

        if (static::WHEN_AVAILABLE === true) return true;

        return in_array($column, static::WHEN_AVAILABLE);
    }

    /**
     * Set resource value
     *
     * @param  string $column
     */
    protected function setResource(int|string $column, string $resourceClassName): JsonResourceManager
    {
        $resourceData = $this->value($column);

        $resource = $this->makeResource($resourceClassName, $resourceData);

        if ($resource instanceof JsonResourceManager && $resource->canBeEmbedded($this) === false) {
            return $this;
        }

        return $this->set($column, $resource);
    }

    /**
     * Make and return new resource class
     *
     * @param  mixed $resourceData
     * @return mixed
     */
    protected function makeResource(string $resourceClassName, $resourceData)
    {
        return $resourceData === null ? null : new $resourceClassName(new Fluent($resourceData));
    }

    /**
     * Collect dates
     *
     * @param array<string, mixed> $columns
     */
    public function collectDates(array $columns): JsonResourceManager
    {
        if ($columns === []) return $this;

        foreach ($columns as $key => $column) {
            $dateOptions = [];
            if (is_string($key)) {
                if (is_string($column)) {
                    $dateOptions['format'] = $column;
                } else {
                    $dateOptions = $column;
                }
                $column = $key;
            }

            if ($this->ignoreEmptyColumn($column)) continue;

            $value = $this->value($column);

            if ($value === null) {
                $this->set($column, null);
                continue;
            }

            $this->setDate($column, $value, $dateOptions);
        }

        return $this;
    }

    /**
     * Get value from resource
     *
     * @param  string $column
     * @param  mixed $default
     * @return mixed
     */
    protected function value(int|string $column, $default = null)
    {
        return Arr::get($this->resource, $column, $default);
    }

    /**
     * Set date
     *
     * @param mixed $value
     * @param array<string, mixed> $options
     * @return void
     */
    protected function setDate(string $column, $value, array $options = [])
    {
        $options = array_merge([
            'format' =>  config('mongez.resources.date.format', 'd-m-Y h:i:s a'),
            'timestamp' => config('mongez.resources.date.timestamp', true),
            'humanTime' => config('mongez.resources.date.humanTime', true),
            'intl' => config('mongez.resources.date.intl', true),
        ], $options);

        $this->set($column, date_response($value, $options));
    }

    /**
     * Check if the given value is empty
     * Empty value is an empty array or a null value.
     *
     * @param  mixed $value
     */
    protected function isEmptyValue($value): bool
    {
        return  is_null($value) || is_array($value) && count($value) === 0;
    }

    /**
     * Collect the given items and set it as collection
     *
     * @param   string $column
     * @param   string $resource
     * @param   mixed $collection
     * @return  void
     */
    protected function collect(int|string $column, $resource, $collection)
    {
        if (is_array($collection)) {
            $collection = collect($collection)->map(function ($item) {
                if (!is_array($item) && !is_string($item)) {
                    return [];
                }

                return new Fluent((array) $item);
            });
        }

        $resources = $resource::collection($collection);

        // the ->values() is needed to make sure it is a valid array syntax not an object
        $resources->collection = $resources->collection->filter(fn(JsonResourceManager $resource) => $resource->canBeEmbedded($this))->values();

        $this->set($column, $resources);
    }

    /**
     * Extend data with more complex returned values
     *
     * @param  \Illuminate\Http\Request $request
     * @return void
     */
    protected function extend(?\Illuminate\Http\Request $request = null) {}

    /**
     * Determine whether the current resource can be embedded in the givenparent resource
     */
    public function canBeEmbedded(JsonResourceManager $parentResource): bool
    {
        return true;
    }

    /**
     * access the nid column as $this->id returns _id now
     */
    protected function id(): int
    {
        return $this->data['nid'];
    }

    /**
     * access the nid column
     */
    protected function nid(): int
    {
        return $this->data['nid'];
    }
}
