<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class ModelCacheManager
{
    /**
     * Resolved model → repository reverse map, built once per request.
     *
     * @var array<class-string<Model>, class-string>|null
     */
    private static ?array $repositories = null;

    /**
     * Check whether caching is enabled for the given model.
     *
     * Resolution order:
     * 1. Model USING_CACHE constant (if not null).
     * 2. Linked repository USING_CACHE constant (if a repository is registered for this model).
     * 3. Global config('mongez.cache.enabled').
     *
     * @param Model|class-string<Model> $model
     */
    public function isEnabled(Model|string $model): bool
    {
        $modelClass = $this->modelClass($model);

        if (defined("{$modelClass}::USING_CACHE") && constant("{$modelClass}::USING_CACHE") !== null) {
            return (bool) constant("{$modelClass}::USING_CACHE");
        }

        $repositoryClass = $this->resolveRepository($modelClass);

        if ($repositoryClass && defined("{$repositoryClass}::USING_CACHE")) {
            return (bool) constant("{$repositoryClass}::USING_CACHE");
        }

        return (bool) config('mongez.cache.enabled', false);
    }

    /**
     * Cache-first lookup by primary key (nid).
     *
     * @template TModel of Model
     *
     * @param TModel|class-string<TModel> $model
     * @param callable(): ?TModel $resolver
     * @return TModel|null
     */
    public function rememberById(Model|string $model, int $nid, callable $resolver): ?Model
    {
        if (!$this->isEnabled($model)) {
            return $resolver();
        }

        $modelClass = $this->modelClass($model);
        $key = $this->recordKey($modelClass, $nid);
        $version = $this->getVersion($modelClass);

        $payload = $this->driver()->get($key);

        if ($payload !== null && $this->payloadVersion($payload) === $version) {
            return $this->hydrate($modelClass, $payload);
        }

        $record = $resolver();

        if ($record) {
            $this->put($record);
        }

        return $record;
    }

    /**
     * Cache-first lookup by an alternate column.
     *
     * Stores only the nid in the alternate index; the actual record is cached by record key.
     *
     * @template TModel of Model
     *
     * @param TModel|class-string<TModel> $model
     * @param callable(): ?TModel $resolver
     * @return TModel|null
     */
    public function rememberByColumn(Model|string $model, string $column, mixed $value, callable $resolver): ?Model
    {
        if (!$this->isEnabled($model)) {
            return $resolver();
        }

        $modelClass = $this->modelClass($model);
        $indexKey = $this->columnKey($modelClass, $column, $value);
        $version = $this->getVersion($modelClass);

        $nid = $this->driver()->get($indexKey);

        if ($nid !== null && $this->payloadVersion($nid) === $version) {
            $nid = $this->payloadValue($nid);

            return $this->rememberById($modelClass, (int) $nid, $resolver);
        }

        $record = $resolver();

        if ($record) {
            $this->put($record);
        }

        return $record;
    }

    /**
     * Write the model to cache along with its registered alternate indexes.
     *
     * @param Model $model
     */
    public function put(Model $model): void
    {
        if (!$this->isEnabled($model)) {
            return;
        }

        $modelClass = $model::class;
        $version = $this->getVersion($modelClass);

        $payload = $model->toArray();
        $payload['__cacheVersion'] = $version;

        $this->driver()->put(
            $this->recordKey($modelClass, (int) $model->getKey()),
            $payload,
            $this->ttl()
        );

        foreach ($this->alternateKeys($model) as $column) {
            $value = $model->getAttribute($column);

            if ($value === null) continue;

            $this->driver()->put(
                $this->columnKey($modelClass, $column, $value),
                ['__cacheVersion' => $version, 'nid' => (int) $model->getKey()],
                $this->ttl()
            );
        }
    }

    /**
     * Forget all cache keys for the given model instance.
     *
     * @param Model $model
     */
    public function forget(Model $model): void
    {
        $this->forgetById($model, (int) $model->getKey());
    }

    /**
     * Forget cache keys for a model + nid, including alternate indexes.
     *
     * @param Model|class-string<Model> $model
     */
    public function forgetById(Model|string $model, int $nid): void
    {
        $modelClass = $this->modelClass($model);

        $this->driver()->forget($this->recordKey($modelClass, $nid));
    }

    /**
     * Forget cache keys for a list of nids.
     *
     * @param Model|class-string<Model> $model
     * @param array<int> $nids
     */
    public function forgetByIds(Model|string $model, array $nids): void
    {
        foreach ($nids as $nid) {
            $this->forgetById($model, (int) $nid);
        }
    }

    /**
     * Forget an alternate index by column and value.
     *
     * @param Model|class-string<Model> $model
     */
    public function forgetByColumn(Model|string $model, string $column, mixed $value): void
    {
        $modelClass = $this->modelClass($model);

        $this->driver()->forget($this->columnKey($modelClass, $column, $value));
    }

    /**
     * Increment the model version to invalidate all cached records at once.
     *
     * @param Model|class-string<Model> $model
     */
    public function invalidateAll(Model|string $model): void
    {
        $modelClass = $this->modelClass($model);

        $this->driver()->put($this->versionKey($modelClass), $this->newVersion(), $this->ttl());
    }

    /**
     * Try to find the registered repository class for the model.
     *
     * @param class-string<Model> $modelClass
     * @return class-string|null
     */
    public function resolveRepository(string $modelClass): ?string
    {
        if (self::$repositories === null) {
            $this->buildRepositoryMap();
        }

        return self::$repositories[$modelClass] ?? null;
    }

    /**
     * Reset the repository map (used by Octane between requests).
     *
     * @return void
     */
    public static function resetRepositoryMap(): void
    {
        self::$repositories = null;
    }

    /**
     * Build model → repository map from config.
     *
     * @return void
     */
    private function buildRepositoryMap(): void
    {
        self::$repositories = [];

        foreach (config('mongez.repositories', []) as $class) {
            if (!is_string($class) || !class_exists($class)) continue;
            if (!defined("{$class}::MODEL")) continue;

            $model = constant("{$class}::MODEL");

            if (!is_string($model) || !is_a($model, Model::class, true)) continue;

            self::$repositories[$model] = $class;
        }
    }

    /**
     * @template TModel of Model
     *
     * @param TModel|class-string<TModel> $model
     * @return class-string<TModel>
     */
    private function modelClass(Model|string $model): string
    {
        $modelClass = $model instanceof Model ? $model::class : $model;

        if (! is_a($modelClass, Model::class, true)) {
            throw new \InvalidArgumentException("Invalid model class: {$modelClass}");
        }

        return $modelClass;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function tableName(string $modelClass): string
    {
        if (method_exists($modelClass, 'getTableName')) {
            return $modelClass::getTableName();
        }

        $model = new $modelClass;

        return $model->getTable();
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function recordKey(string $modelClass, int $nid): string
    {
        return $this->prefix($modelClass) . 'id:' . $nid;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function columnKey(string $modelClass, string $column, mixed $value): string
    {
        return $this->prefix($modelClass) . 'col:' . $column . ':' . (string) $value;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function versionKey(string $modelClass): string
    {
        return $this->prefix($modelClass) . 'version';
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function prefix(string $modelClass): string
    {
        $base = config('mongez.cache.prefix', 'mongez');

        return $base . ':' . $this->tableName($modelClass) . ':';
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function getVersion(string $modelClass): int
    {
        $version = $this->driver()->get($this->versionKey($modelClass));

        return $version !== null ? (int) $version : 1;
    }

    private function newVersion(): int
    {
        return time();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadVersion(array $payload): int
    {
        return (int) ($payload['__cacheVersion'] ?? 0);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadValue(array $payload): mixed
    {
        return $payload['nid'] ?? null;
    }

    /**
     * @template TModel of Model
     *
     * @param class-string<TModel> $modelClass
     * @param array<string, mixed> $payload
     * @return TModel
     */
    private function hydrate(string $modelClass, array $payload): Model
    {
        unset($payload['__cacheVersion']);

        $model = new $modelClass($payload);

        $model->exists = true;

        return $model;
    }

    /**
     * @param Model $model
     * @return array<int, string>
     */
    private function alternateKeys(Model $model): array
    {
        $modelClass = $model::class;
        $keys = defined("{$modelClass}::CACHE_ALTERNATE_KEYS") ? (array) constant("{$modelClass}::CACHE_ALTERNATE_KEYS") : [];

        $config = config('mongez.cache.alternateKeys.' . $modelClass, []);

        return array_values(array_unique(array_merge($keys, (array) $config)));
    }

    private function ttl(): ?int
    {
        $ttl = config('mongez.cache.ttl');

        return $ttl === null ? null : (int) $ttl;
    }

    private function driver(): CacheRepository
    {
        $driver = config('mongez.cache.driver', '');

        if (! $driver) {
            $driver = config('mongez.repository.cache.driver', '');
        }

        if (! $driver) {
            return Cache::store();
        }

        return Cache::store($driver);
    }
}
