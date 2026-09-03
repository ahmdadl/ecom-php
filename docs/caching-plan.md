# Unified Caching Plan — Models & Repositories

> **Status:** Planning  
> **Scope:** Mongez package (`hassanzohdy/mongez`)  
> **Goal:** Cache lives on the **model** side. Eloquent `save` / `delete` automatically revalidate. Query-builder writes (no Eloquent events) must call a manual invalidate API. Repositories expose `*Cached` read helpers that share the same model cache.

---

## 1. Current State

### What exists today

| Component | File | Behavior |
|-----------|------|----------|
| `Cacheable` trait | `src/Repository/Concerns/Cacheable.php` | `getCache`, `setCache`, `forgetCache` via Laravel `Cache` facade |
| Opt-in flag | `RepositoryManager::USING_CACHE` (default `false`) | Per-repository toggle |
| Read path | `Listable::getBy()` | Reads cache when `USING_CACHE = true` |
| Write path | `RepositoryManager::save()` | Writes cache after create/update |
| Delete path | `Deletable::delete()` | Forgets cache by primary key |
| Config | `mongez.repository.cache.driver` | Cache store name (empty = default driver) |

### What is missing

- **No model-level caching** — direct `$product->save()` or `Product::find()` bypasses cache entirely.
- **No explicit cached API** — caching is silently mixed into `getBy()` when enabled; there is no `findCached()` / `getCached()`.
- **Incomplete write-path coverage** — `publish()` uses query update; `increment()`/`decrement()` are atomic query writes with no cache sync.
- **No TTL** — entries live forever unless overwritten or deleted.
- **No manual invalidate API** — apps cannot safely clear cache after raw/query-builder updates.

### Known bug in current implementation

Cache keys are inconsistent between read and write:

```
// getBy() builds the key, then getCache() prefixes NAME again → double prefix
getBy():   cacheKey = NAME . '_' . column . '_' . value
           stored at → NAME + (NAME_column_value)

// save() stores by nid only
save():    stored at → NAME + nid
```

These keys never match, so `getBy()` always misses even when `save()` wrote data. **Fixing key generation is Phase 1.**

Also, `save()` stores the full model object while `getBy()` stores `$record->toArray()` — inconsistent serialization.

---

## 2. Design Principles

1. **Model owns caching** — automatic revalidation happens only from Eloquent model events (`saved`, `deleted`, `restored`). Repositories do **not** write cache on their own after `save()`.
2. **One source of truth** — `ModelCacheManager` owns key format, read, write, and forget. Model + repository read helpers both delegate to it.
3. **Eloquent = automatic; query builder = manual** — any write that does not go through `$model->save()` / `$model->delete()` must call `invalidateCache()` (or equivalent) explicitly.
4. **Opt-in, backward compatible** — existing `find()`, `get()`, `getBy()` stay DB-first. New `*Cached` methods are the explicit cached API.
5. **Write-through on Eloquent saves** — on create/update via Eloquent, refresh cache with fresh data. On delete, forget keys for that record.
6. **Octane-safe** — no request-leaking static state; any reverse maps can be reset via the existing Octane provider.

### Is this a good approach?

**Yes.** It is the cleanest split for Mongez:

| Write style | Coverage |
|-------------|----------|
| `repo->create/update/patch/delete` | Uses `$model->save()` / `delete()` → model events → automatic |
| `$model->save()` / `update()` / `delete()` | Model events → automatic |
| `ModelEvents` embedded sync | Calls `$record->save()` → automatic |
| Query builder / `DB::` / mass `where()->update()` | **No events** → call `invalidateCache()` manually |

Trying to auto-detect every query-builder write is fragile. Making invalidate explicit for those paths is honest and safe.

---

## 3. Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     Application Code                        │
├──────────────────────────┬──────────────────────────────────┤
│  repo('products')        │  Product::findCached(1)          │
│  ->findCached(1)         │  $product->save()                │
│  ->update($id, $data)    │  $product->refresh()             │
│  (Eloquent path)         │  $product->invalidateCache()     │
└────────────┬─────────────┴──────────────┬───────────────────┘
             │ $model->save()             │
             ▼                            ▼
┌────────────────────────────────────────────────────────────┐
│              Model CacheableModel trait                    │
│  saved / deleted / restored  →  put / forget               │
│  refresh() / fresh()         →  reload DB + put            │
│  invalidateCache()           →  forget + refresh()         │
│  invalidateCache($nid)       →  forget (static / mass)     │
└────────────────────────────┬───────────────────────────────┘
                             ▼
              ┌─────────────────────────────┐
              │   ModelCacheManager         │
              │   - rememberById/Column     │
              │   - put() / forget()        │
              │   - invalidate...()         │
              └──────────────┬──────────────┘
                             ▼
              ┌─────────────────────────────┐
              │   Laravel Cache (Redis…)    │
              └─────────────────────────────┘
```

**Important:** repository create/update/patch/delete need no cache write code. They already call Eloquent; the model trait handles revalidation.

---

## 4. Cache Key Strategy

Use a consistent, namespaced key format based on the model table (stable even without a repository):

```
{prefix}:{table}:id:{nid}              → primary record (array payload)
{prefix}:{table}:col:{column}:{value}  → alternate lookup index → stores nid only
```

| Part | Source | Example |
|------|--------|---------|
| `prefix` | `config('mongez.cache.prefix', 'mongez')` | `mongez` |
| `table` | `$model->getTableName()` | `products` |
| `nid` | `$model->nid` | `42` |

**Alternate column keys** store the `nid` as a lightweight index. On read, resolve nid → fetch primary payload. On write/delete/invalidate, forget primary + registered alternate keys.

### Stored payload

Always store **`array`** (via `$model->toArray()`), never the Eloquent object. Hydrate on read with `newInstance($data, true)` / `newModel($data)`.

---

## 5. New Core Class

**File:** `src/Cache/ModelCacheManager.php`

```php
final class ModelCacheManager
{
    public function isEnabled(Model|string $model): bool;

    // Reads
    public function rememberById(Model|string $model, int $nid, callable $resolver): ?Model;
    public function rememberByColumn(Model|string $model, string $column, mixed $value, callable $resolver): ?Model;

    // Automatic write path (Eloquent events)
    public function put(Model $model): void;
    public function forget(Model $model): void;

    // Manual invalidation (query-builder / mass / raw writes)
    public function invalidate(Model|string $model, int $nid): void;
    public function invalidateMany(Model|string $model, array $nids): void;
    public function invalidateByColumn(Model|string $model, string $column, mixed $value): void;
}
```

Responsibilities:

- Read/write/forget against Laravel Cache with TTL from config.
- Resolve whether caching is enabled (model `USING_CACHE` → optional linked repository flag → global config).
- Register alternate keys when `put()` is called (at minimum `nid`; optionally slug, sku, etc.).

Static `invalidate*` methods **forget** keys (safe after mass/query-builder mutations without a model instance).

Instance `invalidateCache()` is bound to Eloquent reload: it **forgets**, then calls `refresh()` so the in-memory model and cache both match the DB (warm write-through).

---

## 6. Model Layer (primary)

### New trait: `CacheableModel`

**File:** `src/Database/Eloquent/CacheableModel.php`

Composed into `ModelTrait` (or MongoDB/MySQL base models).

#### Constants

```php
const USING_CACHE = false; // opt-in per model
const CACHE_ALTERNATE_KEYS = []; // e.g. ['slug', 'sku']
const REPOSITORY_NAME = ''; // optional; only if inheriting USING_CACHE from repo
```

#### Eloquent hooks (automatic)

| Event | Action |
|-------|--------|
| `saved` | `ModelCacheManager::put($model)` when enabled |
| `deleted` | `ModelCacheManager::forget($model)` when enabled |
| `restored` | `put($model)` when enabled |

These cover **every Eloquent instance write**, including:

- `repo->create()` / `update()` / `patch()` / `delete()` (they call `$model->save()` / `delete()`)
- `$model->save()` / `$model->update([...])` / `$model->delete()`
- `ModelEvents` related `$record->save()` calls

Do **not** override Eloquent `save()` / `update()`. Events are enough and safer.

#### Bind `refresh()` / `fresh()` to cache

Override Eloquent reload helpers so a DB reload also revalidates cache:

| Method | Behavior when caching enabled |
|--------|-------------------------------|
| `refresh()` | `parent::refresh()` then `put($this)` — instance attrs + cache match DB |
| `fresh(...)` | `parent::fresh(...)`; if a model is returned, `put($fresh)` on it, then return it |

This is the preferred follow-up after atomic query-builder mutations when you already hold a model:

```php
$model->newQuery()->whereKey($model->getKey())->increment('views', 1);
$model->refresh(); // reload from DB + put into cache
```

#### Cached read methods

| Method | Returns | Description |
|--------|---------|-------------|
| `findCached(int $id): ?static` | Model or null | Cache-first find by `nid` |
| `getCached(int $id): ?static` | Model or null | Alias of `findCached` |
| `findByCached(string $column, mixed $value): ?static` | Model or null | Cache-first column lookup |

#### Manual invalidation API

| Method | Description |
|--------|-------------|
| `invalidateCache(int $nid): void` | **Static:** forget cache keys for that `nid` |
| `invalidateCache(): static` | **Instance:** forget keys, then `refresh()` (reload from DB + `put`) — returns `$this` |
| `invalidateCacheByIds(array $nids): void` | Static: forget many records |
| `invalidateCacheBy(string $column, mixed $value): void` | Static: forget via alternate key index |
| `refreshCache(): static` | Instance: `put($this)` with current in-memory attributes (no DB round-trip) |
| `forgetCache(): static` | Instance: forget keys only (no reload) |
| `isCachable(): bool` | Whether caching is enabled for this model |

Instance `invalidateCache()` composition:

```php
public function invalidateCache(): static
{
    // when called as instance method (no args)
    $this->forgetCache();
    $this->refresh(); // parent reload + put via overridden refresh()

    return $this;
}
```

Use static `invalidateCache($nid)` when you have no model instance (mass / raw updates).  
Use instance `invalidateCache()` or `refresh()` when you already have the model after a query-builder write.

#### Query-builder usage pattern

```php
// Prefer Eloquent when possible (publish, etc.)
$product->published = true;
$product->save(); // automatic put

// Atomic query mutation + reload/cache sync
$product->newQuery()->whereKey($product->getKey())->increment('views', 1);
$product->refresh(); // or $product->invalidateCache();

// Mass / raw update without instances — forget only
$ids = Product::query()->where('status', 'old')->pluck('nid')->all();
Product::query()->whereIn('nid', $ids)->update(['status' => 'new']);
Product::invalidateCacheByIds($ids);
```

---

## 7. Repository Layer (reads + thin helpers)

Repositories are **consumers** of model cache for reads. They do **not** own write-side revalidation for Eloquent paths.

### Refactor `Cacheable` trait

Delegate to `ModelCacheManager`. Keep thin wrappers if needed for BC, or deprecate them in favor of model APIs.

Remove cache put/forget from:

- `RepositoryManager::save()` — model `saved` already handles it
- `Deletable::delete()` — model `deleted` already handles it

### New cached read methods

| New method | Mirrors | Returns |
|------------|---------|---------|
| `findCached(int $id)` | `find()` | Raw model (`MODEL::findCached`) |
| `getCached(int $id)` | `get()` | JsonResource (wrap cached model) |
| `getModelCached($id)` | `getModel()` | Raw model |
| `getByCached($column, $value)` | `getBy()` | JsonResource |
| `getByModelCached($column, $value)` | `getByModel()` | Raw model |
| `getPublishedModelCached($id)` | `getPublishedModel()` | Raw model |
| `getPublishedCached($id)` | `getPublished()` | JsonResource |

Also expose repo convenience wrappers for manual invalidate:

```php
public function invalidateCache(int $nid): void
{
    static::MODEL::invalidateCache($nid);
}

public function invalidateCacheByIds(array $nids): void
{
    static::MODEL::invalidateCacheByIds($nids);
}
```

**Existing read methods stay unchanged** — no silent behavior change.

### Package query-builder bypasses

| Location | Current | Required change |
|----------|---------|-----------------|
| `Listable::publish()` | `query()->update(...)` | **Rewrite to Eloquent `save()`** — load model, set published column, `$model->save()` (cache via `saved`) |
| `RepositoryManager::increment/decrement()` | atomic query `increment`/`decrement` | Keep atomic query (concurrency), then `$model->refresh()` (reloads + puts cache). Do **not** switch to plain `save()` |
| App / custom code using query builder | — | With instance: `$model->refresh()` or `$model->invalidateCache()`. Without instance: `MODEL::invalidateCache($nid)` / `invalidateCacheByIds` |

#### Target implementations

```php
// publish — Eloquent path
public function publish($id, $publishState): void
{
    $model = $this->getModel($id);
    if (!$model) return;

    $model->{$this->getPublishedColumn()} = (bool) $publishState;
    $model->save();
}

// increment — atomic DB + refresh binds cache
public function increment($model, string $column, int $incrementBy = 1)
{
    if (is_numeric($model)) {
        $model = $this->getModel($model);
    }
    if (!$model) return null;

    $model->newQuery()->whereKey($model->getKey())->increment($column, $incrementBy);
    $model->refresh(); // sync attributes + cache from DB

    return $model;
}
```

---

## 8. Enabling Cache

**Primary switch is on the model:**

```php
class Product extends Model
{
    const USING_CACHE = true;
    const CACHE_ALTERNATE_KEYS = ['slug'];
}
```

Optional: repository `USING_CACHE` may remain as a legacy/secondary flag that `ModelCacheManager` can consult when the model sets `USING_CACHE = null` and `REPOSITORY_NAME` is set. Prefer model-first going forward.

---

## 9. Configuration

Extend `files/config/mongez.php`:

```php
'cache' => [
    'enabled' => env('MONGEZ_CACHE_ENABLED', false), // global kill-switch
    'driver' => env('MONGEZ_CACHE_DRIVER', ''), // empty = default store
    'prefix' => env('MONGEZ_CACHE_PREFIX', 'mongez'),
    'ttl' => env('MONGEZ_CACHE_TTL', 3600), // seconds; null = forever
],
```

Keep `mongez.repository.cache.driver` as a deprecated alias that falls back to `mongez.cache.driver`.

---

## 10. Invalidation Matrix

| Action | Entry point | Cache effect |
|--------|-------------|--------------|
| Create | `repo->create()` | Automatic via `$model->save()` → `saved` → `put` |
| Update | `repo->update()` / `patch()` | Automatic via `$model->save()` → `put` |
| Delete | `repo->delete()` | Automatic via `$model->delete()` → `forget` |
| Save / update | `$model->save()` / `$model->update()` | Automatic `put` |
| Delete | `$model->delete()` | Automatic `forget` |
| Soft restore | `$model->restore()` | Automatic `put` |
| Embedded sync | `ModelEvents` → `$record->save()` | Automatic `put` on that related model |
| Publish | `repo->publish()` | `$model->save()` → automatic `put` |
| Increment / decrement | atomic query + `$model->refresh()` | `refresh()` reloads DB + `put` |
| Instance after query write | `$model->invalidateCache()` | forget + `refresh()` (same as reload + warm cache) |
| Mass / raw query | `where()->update()`, `DB::` | Static `invalidateCache` / `invalidateCacheByIds` (forget only) |

No double-write on repo Eloquent paths: repository no longer calls `setCache` itself.

---

## 11. Methods Covered by `*Cached` Variants

### Phase 1 (single-record lookups)

- Model: `findCached`, `getCached`, `findByCached`
- Model: static + instance `invalidateCache`, `invalidateCacheByIds`, `invalidateCacheBy`, `refreshCache`, `forgetCache`
- Model: override `refresh()` / `fresh()` to `put` after DB reload
- Repository: `findCached`, `getCached`, `getModelCached`, `getByCached`, `getByModelCached`, published variants
- Repository: thin `invalidateCache*` wrappers

### Phase 2 (optional)

- `listCached` / count caching — deferred (needs tags / broad invalidation)

---

## 12. Implementation Phases

### Phase 1 — Model cache foundation

| # | Task | Files |
|---|------|-------|
| 1.1 | Create `ModelCacheManager` | `src/Cache/ModelCacheManager.php` |
| 1.2 | Create `CacheableModel` trait (events + `*Cached` + invalidate APIs) | `src/Database/Eloquent/CacheableModel.php` |
| 1.3 | Compose into `ModelTrait` / base models | `ModelTrait.php`, `MongoDB/Model.php` |
| 1.4 | Add `mongez.cache` config | `files/config/mongez.php` |
| 1.5 | Register manager singleton | `MongezServiceProvider.php` |
| 1.6 | Remove write-side cache from repo `save()` / `delete()` | `RepositoryManager.php`, `Deletable.php` |
| 1.7 | Fix / stop broken silent caching in `getBy()` | `Listable.php` |

### Phase 2 — Repository `*Cached` reads + package bypasses

| # | Task | Files |
|---|------|-------|
| 2.1 | Add repo `*Cached` read methods (delegate to model) | `Listable.php` |
| 2.2 | Add repo `invalidateCache*` wrappers | `Cacheable.php` |
| 2.3 | `publish()` → Eloquent `save()`; `increment`/`decrement` → keep atomic query + `refresh()` | `Listable.php`, `RepositoryManager.php` |
| 2.4 | Refactor old `Cacheable` trait to delegate / deprecate | `Cacheable.php` |

### Phase 3 — Tests & docs

| # | Task | Files |
|---|------|-------|
| 3.1 | Unit tests for manager keys / put / invalidate | `tests/Cache/` |
| 3.2 | Eloquent path: model + repo writes auto-revalidate | same |
| 3.3 | Query-builder path: stale until `invalidateCache`, fresh after | same |
| 3.4 | Octane reset if any static maps | `MongezOctaneServiceProvider.php` |
| 3.5 | Document query-builder rule in README / module stubs | `README.md`, `module/` |

---

## 13. Example Usage (target API)

### Model (owner)

```php
class Product extends Model
{
    const USING_CACHE = true;
    const CACHE_ALTERNATE_KEYS = ['slug'];
}

// Cached reads
$product = Product::findCached(1);
$product = Product::findByCached('slug', 'my-product');

// Eloquent write — automatic revalidation
$product->name = 'Updated';
$product->save();

// Atomic query write — refresh syncs model + cache
$product->newQuery()->whereKey($product->getKey())->increment('views', 1);
$product->refresh();
// same effect:
// $product->invalidateCache();

// Mass update without instances — static forget
Product::query()->whereIn('nid', $ids)->update(['status' => 'new']);
Product::invalidateCacheByIds($ids);
```

### Repository (read helpers)

```php
class ProductsRepository extends MongoDBRepositoryManager
{
    const NAME = 'products';
    const MODEL = Product::class;
}

$product = repo('products')->findCached(1);
$resource = repo('products')->getCached(1);

// Eloquent CRUD — automatic via model events
repo('products')->update(1, ['name' => 'Updated']);

// Custom repo method with a model instance:
$model->newQuery()->whereKey($model->getKey())->increment('views', 1);
$model->refresh(); // or $model->invalidateCache();

// Mass / no instance:
$this->getQuery()->where('nid', $id)->update(['flag' => true]);
Product::invalidateCache($id);
```

---

## 14. Testing Strategy

### Unit (`ModelCacheManager`)

- Deterministic keys
- `put` then `rememberById` hydrates correctly
- `invalidate` / `invalidateMany` remove keys
- Disabled model → no cache writes

### Integration

1. `repo->update()` then `Product::findCached()` returns fresh data (Eloquent automatic).
2. `$model->save()` then `repo->findCached()` returns fresh data.
3. Query `update` without invalidate/refresh → cached read may be stale.
4. Atomic increment + `$model->refresh()` → cache matches DB; `findCached` returns new value.
5. Instance `$model->invalidateCache()` forgets then refreshes (warm cache).
6. `publish` uses `save()`; `increment`/`decrement` use query + `refresh()`.
7. Existing `find()` / `get()` unchanged when caching disabled.

---

## 15. Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Developers forget to invalidate after query-builder writes | Document clearly; bind `refresh()`/`fresh()` to cache; package `publish`/`increment` fixed; static invalidate for mass updates |
| Stale alternate keys after changing slug/sku via query builder | `invalidate` forgets registered alternate keys; prefer Eloquent save when changing those columns |
| List cache complexity | Out of scope for v1 |
| Large payloads | Optional future `CACHE_COLUMNS` |
| Beta / multi-DB | Include connection/db name in prefix if needed |

---

## 16. File Checklist

| File | Action |
|------|--------|
| `src/Cache/ModelCacheManager.php` | **Create** |
| `src/Database/Eloquent/CacheableModel.php` | **Create** (events + reads + invalidate) |
| `src/Database/Eloquent/ModelTrait.php` | **Compose** `CacheableModel` |
| `src/Repository/Concerns/Cacheable.php` | **Refactor** → delegate / invalidate wrappers |
| `src/Repository/Concerns/Listable.php` | **Add** `*Cached` reads; fix `publish()` |
| `src/Repository/RepositoryManager.php` | **Remove** write-side cache; fix increment/decrement |
| `src/Repository/Concerns/Deletable.php` | **Remove** write-side forget (model handles it) |
| `src/Providers/MongezServiceProvider.php` | **Register** manager |
| `src/Providers/MongezOctaneServiceProvider.php` | **Reset** static maps if any |
| `files/config/mongez.php` | **Add** `cache` section |
| `tests/Cache/*.php` | **Create** |

---

## 17. Decision Log

| Decision | Rationale |
|----------|-----------|
| **Cache on model side** | Single automatic path for both repo and model Eloquent writes |
| **Manual `invalidateCache*` for query builder** | Query updates never fire Eloquent events; explicit is safer than fake magic |
| Bind `refresh()` / `fresh()` to `put` | Natural sync after atomic DB ops; increment keeps concurrency + warm cache |
| Instance `invalidateCache()` = forget + `refresh()` | One call to realign model instance and cache with DB |
| `publish` → `$model->save()` | Simple column change; Eloquent events enough |
| `increment`/`decrement` keep atomic query + `refresh()` | Avoid race conditions from naive `save()` |
| Repositories only wrap cached **reads** (+ thin invalidate helpers) | Avoids duplicate write logic and double-put |
| Remove repo `save()`/`delete()` cache writes | Model events already cover those paths |
| New `*Cached` methods; leave old methods alone | Backward compatible |
| Table-based keys | Works without repository; stable across renames of repo `NAME` |
| Array storage, not model objects | Safer across Octane / workers |
| No list caching in v1 | Invalidation cost/complexity too high |
| Write-through on Eloquent `saved` | Next cached read is warm after normal saves |
