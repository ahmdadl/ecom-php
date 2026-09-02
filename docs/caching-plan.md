# Unified Caching Plan — Models & Repositories

> **Status:** Planning  
> **Scope:** Mongez package (`hassanzohdy/mongez`)  
> **Goal:** A single caching layer that works whether data is read/written through repositories **or** Eloquent models directly. Both `repo->update()` and `Model::save()` / `Model::update()` must revalidate cache. Add explicit `*Cached` method variants alongside existing methods.

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
- **Incomplete write-path coverage** — `publish()`, `increment()`, `decrement()`, and `updateModel()` can mutate data without touching cache.
- **No TTL** — entries live forever unless overwritten or deleted.
- **No model → repository resolution** — models cannot invalidate the same keys repositories use.

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

1. **Single source of truth** — one `ModelCacheManager` class owns key format, read, write, and forget logic. Both repository and model layers delegate to it.
2. **Opt-in, backward compatible** — existing `find()`, `get()`, `getBy()` keep current behavior (DB-only unless repo has `USING_CACHE`). New `*Cached` methods are the explicit cached API.
3. **Write-through revalidation** — on create/update, refresh cache with fresh data. On delete, forget all keys for that record.
4. **Works from both entry points** — repository save and model Eloquent events both call the same revalidation logic (idempotent, safe to call twice in one request).
5. **Octane-safe** — no request-leaking static state; in-memory model→repo map is built lazily and can be reset via existing Octane provider.

---

## 3. Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     Application Code                        │
├──────────────────────────┬──────────────────────────────────┤
│  repo('products')        │  Product::findCached(1)          │
│  ->findCached(1)         │  $product->refreshCache()        │
│  ->update($id, $data)    │  $product->save()                │
└────────────┬─────────────┴──────────────┬───────────────────┘
             │                            │
             ▼                            ▼
┌────────────────────────┐   ┌───────────────────────────────┐
│ Repository Cacheable   │   │ Model CacheableModel trait    │
│ (refactored)           │   │ (saved / deleted hooks)       │
└────────────┬───────────┘   └──────────────┬────────────────┘
             │                              │
             └──────────────┬───────────────┘
                            ▼
              ┌─────────────────────────────┐
              │   ModelCacheManager         │
              │   - buildKey()              │
              │   - remember()              │
              │   - put() / forget()        │
              │   - forgetRecord()          │
              │   - resolveRepository()     │
              └──────────────┬──────────────┘
                             ▼
              ┌─────────────────────────────┐
              │   Laravel Cache (Redis…)    │
              └─────────────────────────────┘
```

---

## 4. Cache Key Strategy

Use a consistent, namespaced key format independent of repository `NAME` alone (table name is more stable):

```
{prefix}:{table}:id:{nid}              → primary record (array payload)
{prefix}:{table}:col:{column}:{value}  → alternate lookup index → stores nid only
```

| Part | Source | Example |
|------|--------|---------|
| `prefix` | `config('mongez.cache.prefix', 'mongez')` | `mongez` |
| `table` | `$model->getTableName()` | `products` |
| `nid` | `$model->nid` | `42` |

**Alternate column keys** store the `nid` (integer) as a lightweight index. On read, resolve nid → fetch primary key. On write/delete, forget both primary and any registered alternate keys.

Repository `NAME` can still be used as an optional alias prefix for backward compatibility during migration, but new code should use table-based keys.

### Stored payload

Always store **`array`** (via `$model->toArray()`), never the Eloquent object. Hydrate on read with `$model->newInstance($data, true)` or `newModel($data)`.

---

## 5. New Core Class

**File:** `src/Cache/ModelCacheManager.php`

```php
final class ModelCacheManager
{
    public function isEnabled(Model|string $model): bool;
    public function rememberById(Model|string $model, int $nid, callable $resolver): ?Model;
    public function rememberByColumn(Model|string $model, string $column, mixed $value, callable $resolver): ?Model;
    public function put(Model $model): void;
    public function forget(Model $model): void;
    public function forgetById(Model|string $model, int $nid): void;
    public function resolveRepository(Model|string $model): ?RepositoryManager;
}
```

Responsibilities:

- Read/write/forget against Laravel Cache with TTL from config.
- Resolve whether caching is enabled (model constant → repository constant → global config).
- Lazy-build reverse map `ModelClass → RepositoryClass` from `config('mongez.repositories')`.
- Register alternate keys when `put()` is called (at minimum: `nid`; optionally email, slug, etc. via config).

---

## 6. Model Layer

### New trait: `CacheableModel`

**File:** `src/Database/Eloquent/CacheableModel.php`

Applied inside `ModelTrait` (or composed by MongoDB/MySQL `Model` base classes).

#### Constants

```php
const USING_CACHE = null; // null = inherit from linked repository; true/false = override
const REPOSITORY_NAME = ''; // optional shortcut, e.g. 'products'
```

#### Eloquent hooks (in `boot()`)

| Event | Action |
|-------|--------|
| `saved` | `ModelCacheManager::put($model)` when enabled |
| `deleted` | `ModelCacheManager::forget($model)` when enabled |
| `restored` (soft delete) | `put($model)` |

These fire for **every** save path: repository, direct model, mass assignment, `ModelEvents` embedded updates.

#### New static methods

| Method | Returns | Description |
|--------|---------|-------------|
| `findCached(int $id): ?static` | Model or null | Cache-first `find()` by `nid` |
| `getCached(int $id): ?static` | Model or null | Alias of `findCached` (matches repo naming) |
| `findByCached(string $column, mixed $value): ?static` | Model or null | Cache-first column lookup |

#### New instance methods

| Method | Description |
|--------|-------------|
| `refreshCache(): void` | Force write-through after manual attribute changes |
| `forgetCache(): void` | Manual invalidation |
| `isCachable(): bool` | Delegates to manager |

#### Overriding `update()` — not recommended

Do **not** override Eloquent `update()` / `save()`. Use `saved` / `deleted` events instead — they cover all persistence paths without breaking Eloquent internals or mass-update behavior.

---

## 7. Repository Layer

### Refactor `Cacheable` trait

Delegate all operations to `ModelCacheManager`. Remove duplicate key logic. Keep thin wrappers for backward compatibility:

```php
public function getCache(string $key) { /* delegate */ }
public function setCache(string $key, $value) { /* delegate */ }
public function forgetCache(string $key) { /* delegate */ }
```

### New cached methods (in `Listable` or a new `CachedListable` concern)

| New method | Mirrors | Returns |
|------------|---------|---------|
| `findCached(int $id)` | `find()` | Raw model |
| `getCached(int $id)` | `get()` | JsonResource (wrapped) |
| `getModelCached($id)` | `getModel()` | Raw model |
| `getByCached($column, $value)` | `getBy()` | JsonResource |
| `getByModelCached($column, $value)` | `getByModel()` | Raw model |
| `getPublishedModelCached($id)` | `getPublishedModel()` | Raw model |
| `getPublishedCached($id)` | `getPublished()` | JsonResource |

**Existing methods stay unchanged** — no silent behavior change for consumers who haven't opted in.

### Write-path revalidation (repository)

Update these to call `ModelCacheManager`:

| Location | Current | Change |
|----------|---------|--------|
| `RepositoryManager::save()` | Broken key, stores object | `manager->put($model)` |
| `Deletable::delete()` | `forgetCache($key)` | `manager->forget($model)` |
| `Listable::publish()` | Direct query update, no cache | `forgetById` or `put` after update |
| `RepositoryManager::increment/decrement()` | DB-only | `put($model)` after mutation |
| `RepositoryManager::updateModel()` | Calls `$model->save()` | Covered by model `saved` hook (no extra code) |

---

## 8. Model → Repository Resolution

Models need to know which repository's `USING_CACHE` flag applies.

**Resolution order:**

1. Model `REPOSITORY_NAME` constant → `config('mongez.repositories.{name}')`
2. Reverse map built once per request: scan all registered repositories, map `MODEL` constant → repository class
3. Optional explicit config: `mongez.cache.modelRepositories[Product::class] = ProductsRepository::class`

**Helper function:**

```php
function model_repo(Model|string $model): ?RepositoryManager
```

Add to `src/Helpers/functions.php`.

---

## 9. Configuration

Extend `files/config/mongez.php`:

```php
'cache' => [
    'enabled' => env('MONGEZ_CACHE_ENABLED', false),
    'driver' => env('MONGEZ_CACHE_DRIVER', ''), // empty = default store
    'prefix' => env('MONGEZ_CACHE_PREFIX', 'mongez'),
    'ttl' => env('MONGEZ_CACHE_TTL', 3600), // seconds; null = forever
    'alternateKeys' => [
        // Product::class => ['slug', 'sku'],
    ],
],
```

Keep `mongez.repository.cache.driver` as a deprecated alias that falls back to `mongez.cache.driver`.

Per-entity override remains:

```php
// In ProductsRepository
const USING_CACHE = true;

// In Product model (optional)
const USING_CACHE = true;
const REPOSITORY_NAME = 'products';
```

---

## 10. Invalidation Matrix

| Action | Entry point | Cache effect |
|--------|-------------|--------------|
| Create | `repo->create()` | `put(model)` |
| Update | `repo->update()` | `put(model)` via `save()` + model `saved` |
| Patch | `repo->patch()` | same as update |
| Delete | `repo->delete()` | `forget(model)` |
| Save | `$model->save()` | `put(model)` via `saved` event |
| Update attrs + save | `$model->update([...])` | `put(model)` via `saved` event |
| Delete | `$model->delete()` | `forget(model)` via `deleted` event |
| Publish toggle | `repo->publish()` | `forgetById` or re-fetch + `put` |
| Increment/decrement | `repo->increment()` | `put(model)` after DB update |
| Embedded doc sync | `ModelEvents` → `$record->save()` | Each saved related model revalidates its own cache |
| Soft restore | `$model->restore()` | `put(model)` |

**Double-call safety:** `repo->update()` triggers both `RepositoryManager::save()` and model `saved`. Both call `put()` with the same data — idempotent, no special guard needed.

**Mass updates:** `$model->where(...)->update([...])` bypasses Eloquent events. Document this limitation; optionally add `ModelCacheManager::forgetByTable($table)` for manual flush. Out of scope for v1 unless needed.

---

## 11. Methods Covered by `*Cached` Variants

### Phase 1 (single-record lookups)

- [x] `find` / `findCached`
- [x] `get` / `getCached`
- [x] `getModel` / `getModelCached`
- [x] `getBy` / `getByCached`
- [x] `getByModel` / `getByModelCached`
- [x] `getPublished` / `getPublishedCached`
- [x] `getPublishedModel` / `getPublishedModelCached`

### Phase 2 (optional, higher complexity)

- [ ] `listCached($options)` — requires serializing query options into cache key; risk of stale list caches on any write. Recommend **tag-based invalidation** or skip list caching in v1.
- [ ] `listAllCached`, `countCached`, `hasCached`
- [ ] `firstCached`

**Recommendation:** Ship Phase 1 only. List/count caching needs tag support (`Cache::tags()`) and broad invalidation on any record change — significantly more complex.

---

## 12. Implementation Phases

### Phase 1 — Foundation (fix + centralize)

| # | Task | Files |
|---|------|-------|
| 1.1 | Create `ModelCacheManager` | `src/Cache/ModelCacheManager.php` |
| 1.2 | Add `mongez.cache` config section | `files/config/mongez.php` |
| 1.3 | Refactor `Cacheable` trait to delegate | `src/Repository/Concerns/Cacheable.php` |
| 1.4 | Fix key generation bug in `getBy()` | `src/Repository/Concerns/Listable.php` |
| 1.5 | Fix `save()` to store array, use manager | `src/Repository/RepositoryManager.php` |
| 1.6 | Add `model_repo()` helper | `src/Helpers/functions.php` |
| 1.7 | Register manager in service provider | `src/Providers/MongezServiceProvider.php` |

### Phase 2 — Repository `*Cached` methods

| # | Task | Files |
|---|------|-------|
| 2.1 | Add `findCached`, `getCached`, `getModelCached` | `src/Repository/Concerns/Listable.php` |
| 2.2 | Add `getByCached`, `getByModelCached` | same |
| 2.3 | Add published cached variants | same |
| 2.4 | Update `publish()`, `increment()`, `decrement()` invalidation | `Listable.php`, `RepositoryManager.php` |

### Phase 3 — Model integration

| # | Task | Files |
|---|------|-------|
| 3.1 | Create `CacheableModel` trait | `src/Database/Eloquent/CacheableModel.php` |
| 3.2 | Compose into `ModelTrait` or base models | `ModelTrait.php`, `MongoDB/Model.php`, `MYSQL/Model.php` |
| 3.3 | Add `findCached`, `getCached`, `findByCached` on model | `CacheableModel.php` |

### Phase 4 — Tests & docs

| # | Task | Files |
|---|------|-------|
| 4.1 | Unit tests for key generation and manager | `tests/Cache/ModelCacheManagerTest.php` |
| 4.2 | Integration: repo update revalidates cache | `tests/Cache/RepositoryCacheTest.php` |
| 4.3 | Integration: model save revalidates cache | `tests/Cache/ModelCacheTest.php` |
| 4.4 | Test cross-path: repo write → model read cached | same |
| 4.5 | Octane state reset if any static maps added | `MongezOctaneServiceProvider.php` |
| 4.6 | Update module stubs / README | `module/`, `README.md` |

---

## 13. Example Usage (target API)

### Repository

```php
class ProductsRepository extends MongoDBRepositoryManager
{
    const NAME = 'products';
    const MODEL = Product::class;
    const USING_CACHE = true;
}

// Explicit cached reads
$product = repo('products')->findCached(1);
$resource = repo('products')->getCached(1);
$bySlug = repo('products')->getByModelCached('slug', 'my-product');

// Writes revalidate automatically
repo('products')->update(1, ['name' => 'Updated']); // cache refreshed
```

### Model

```php
class Product extends Model
{
    const USING_CACHE = true;
    const REPOSITORY_NAME = 'products';
}

// Explicit cached reads
$product = Product::findCached(1);
$product = Product::getCached(1);

// Direct model writes also revalidate
$product = Product::find(1);
$product->name = 'Direct update';
$product->save(); // saved event → cache refreshed
```

---

## 14. Testing Strategy

### Unit tests (`ModelCacheManager`)

- Key format is deterministic
- `put` then `rememberById` returns hydrated model with same attributes
- `forget` removes primary and alternate keys
- Disabled caching (`USING_CACHE = false`) → resolver called every time, no cache writes
- TTL is passed to `Cache::put()` when configured

### Integration tests

1. **Repo write → repo cached read:** `create()` then `findCached()` returns record without DB query (mock/spy Cache facade).
2. **Repo write → model cached read:** `repo->update()` then `Product::findCached()` returns fresh data.
3. **Model write → repo cached read:** `$model->save()` then `repo->findCached()` returns fresh data.
4. **Delete invalidates both paths:** after `delete()`, both `findCached()` and `Product::findCached()` miss and return null.
5. **Regression:** existing `find()` / `get()` behavior unchanged when `USING_CACHE = false`.

---

## 15. Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Stale cache on `$query->update()` mass updates | Document limitation; provide `forgetByTable()` escape hatch |
| List cache invalidation complexity | Defer list caching to Phase 2 |
| `ModelEvents` saves many related models | Each model revalidates only its own cache — acceptable overhead |
| Large payload size in cache | Store only needed columns via future `CACHE_COLUMNS` constant (optional enhancement) |
| Multi-tenant / beta DB switching | Include DB connection name in cache prefix if beta headers switch databases |
| Backward compat of old cache keys | One-time key migration not needed if no production consumers rely on broken keys |

---

## 16. File Checklist

| File | Action |
|------|--------|
| `src/Cache/ModelCacheManager.php` | **Create** |
| `src/Database/Eloquent/CacheableModel.php` | **Create** |
| `src/Repository/Concerns/Cacheable.php` | **Refactor** |
| `src/Repository/Concerns/Listable.php` | **Add** `*Cached` methods, fix `getBy()` |
| `src/Repository/RepositoryManager.php` | **Update** `save()`, increment/decrement |
| `src/Repository/Concerns/Deletable.php` | **Update** forget logic |
| `src/Database/Eloquent/ModelTrait.php` | **Compose** `CacheableModel` |
| `src/Helpers/functions.php` | **Add** `model_repo()` |
| `src/Providers/MongezServiceProvider.php` | **Register** manager singleton |
| `src/Providers/MongezOctaneServiceProvider.php` | **Reset** reverse map static if added |
| `files/config/mongez.php` | **Add** `cache` section |
| `tests/Cache/*.php` | **Create** test suite |

---

## 17. Decision Log

| Decision | Rationale |
|----------|-----------|
| New `*Cached` methods instead of changing existing ones | Backward compatibility; explicit opt-in at call site |
| Central `ModelCacheManager` instead of duplicated trait logic | Single place for keys, TTL, resolution; models and repos stay thin |
| Eloquent events over overriding `save()`/`update()` | Covers all save paths; avoids breaking Eloquent batch/mass behavior |
| Table-based keys over repository NAME | Stable across refactors; works when model is used without repository |
| Array storage, not serialized models | Avoids unserialization issues across Octane workers; smaller payload |
| Phase 1 excludes list caching | List invalidation requires tags or full-table flush — separate feature |
| Write-through over cache-aside invalidation | Simpler mental model; next read is always warm after write |
