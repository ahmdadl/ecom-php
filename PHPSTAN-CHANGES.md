# PHPStan Aggressive Changes

This file tracks every **aggressive** change made to drive the project to PHPStan
level 8 with zero baseline errors. "Aggressive" = changes that alter types,
return types, add casts/assertions, or could affect runtime behavior. Review these
when updating application logic that depends on this package.

Each entry lists the file, the change, and why. Safe-only changes (adding PHPDoc
that cannot affect runtime, `@mixin`, `@property` on package classes) are noted as
such and can be skipped during review.

## Conventions
- `[SAFE]` — cannot change runtime behavior (PHPDoc/types only).
- `[RISK]` — may change runtime behavior; review carefully.

---

## Batch 1 — `RepositoryManager` generics + trait magic methods (total saved: 66, 1232 → 1166)

### Root cause
Controllers resolve their repository via `repo()` / `@var RepositoryInterface`, but they call
concrete `RepositoryManager` methods (`wrap()`, `getModel()`, `getTableName()`, `patch()`,
`getPaginationInfo()`) that are **not** declared on `RepositoryInterface`. Also `RepositoryManager`
is generic (`@template TModel`), so a bare `@var RepositoryManager` triggers
"does not specify its types: TModel". Fix: widen the docblock type to the concrete class with a
generic argument.

### Changes
- **`src/Helpers/functions.php`** — `repo()` docblock `@return RepositoryInterface`
  → `@return \HZ\Illuminate\Mongez\Repository\RepositoryManager<\Illuminate\Database\Eloquent\Model>`.
  `[RISK]` swap of the imported alias `RepositoryInterface` → `RepositoryManager`.
- **`src/Http/ApiController.php`** — import swap + `@var RepositoryManager<\Illuminate\Database\Eloquent\Model>`.
  `[RISK]`
- **`src/Http/ViewController.php`** — same import + `@var` swap (property is `= null`). `[RISK]`
- **`src/Http/UIController.php`** — `@var \HZ\Illuminate\Mongez\Repository\RepositoryManager<\Illuminate\Database\Eloquent\Model>`
  (FQN, no import present). `[RISK]`
- **`src/Http/Validation/UniqueEmail.php`** — import swap + `@var` swap. `[RISK]`
- **`src/Repository/Concerns/RepositoryTrait.php`** — added `__get($key): mixed`; replaced
  `parent::class` with `get_parent_class($this)` (PHPStan flags `parent::class` in a trait whose
  using class has no parent). `[RISK]` (logic unchanged; both resolve the parent class at runtime).
- **`src/Traits/WithRepositoryAndService.php`** — `__get($key): mixed`; `parent::class` →
  `get_parent_class($this)`. `[RISK]` (same reasoning).
- **`src/Traits/WithService.php`** — `__get($key): mixed`. `[RISK]`
- **`src/Translation/Traits/Translatable.php`** —
  - added `__call($method, $args): mixed`;
  - `Str::removeFirst('trans', $method)` → `Str::replaceFirst('trans', $method)` — `Str::removeFirst`
    is **not** a real helper (it was a runtime macro = `replaceFirst($needle, '', $object)`), so behavior
    is identical. `[RISK]` (no behavioral change).
  - `method_exists(get_parent_class($this), '__call')` → guard `$parent = get_parent_class($this);
    $parent !== false && method_exists($parent, '__call')` (get_parent_class returns `string|false`).
  - `return;` → `return null;` (no-op return is now a typed `mixed` return). `[RISK]`
- **`src/Http/ApiResponse.php`** — `array` → `array<string, mixed>` for `$data`/`$headers` params and
  `mapResponseError()` return; native `array $data`/`array $headers` added. `[SAFE]` docblock alignment.
- **`src/Http/RestfulApiController.php`** (also from prior batch) — controller method return types
  changed from `\Illuminate\Http\Response` to `Symfony\Component\HttpFoundation\Response` (the helpers
  return the parent); `destroy()` param `int|string` → `string`; `sortBy`/`sortDirection` dynamic
  property access → `$request->input(...)`; validation/before* methods typed `@return array<string, mixed>`
  / `|null`. `[SAFE]` (input() is behavior-equivalent).

### Review notes for app authors
- Any application code relying on `repo()` / controller `$repository` being typed as
  `RepositoryInterface` will now see it typed as `RepositoryManager`. The concrete class is what was
  returned at runtime all along, so this is strictly more correct.
- `Translatable::__call` now returns `mixed`; previously the magic method had no declared return type.

---

## Batch 2 — `ApiRequest.php` non-baselinable source bugs + `RepositoryTrait` return (total saved: ~80, 1232 → 1146)

### Root cause
`phpstan analyse --generate-baseline` refused to baseline 11 errors in `src/ApiDocs/ApiRequest.php`
because multiple errors share an identifier+line (typos, docblock/return mismatches). Rather than leave
`composer phpstan` RED, these were fixed at the source (ApiRequest is file #10 in our size-order list
anyway). Plus one `return.missing` in `RepositoryTrait::__get()`.

### Changes
- **`src/ApiDocs/ApiRequest.php`** —
  - L81 `@var array` → `@var array<string, mixed>` on `$jsonContent`. `[SAFE]`
  - L106-109 `@param mixed $ocntent` typo → `@param mixed $content`; native `append($content)`. `[SAFE]`
  - L121-124 `appendLine` docblock `@return string` → `@return ApiRequest` (native return type). `[SAFE]`
  - L168 `File::getJson($this->filePath)` → `json_decode(File::get($this->filePath), true)`.
    `[RISK]` `Illuminate\Support\Facades\File` has **no** `getJson()` method — this would fatal at runtime.
    Fixed to the documented intent (read file + json_decode).
  - L202-205 / 228-231 / 239-242 / 450-453 / 461-464 / 659-662 — six `void` methods
    (`setBeforeDocumentHeading`, `setAfterDocumentHeading`, `setBeforeRequestInformation`,
    `setAfterRequestInformation`, `setBeforeResponseInformation`, `setAfterResponseInformation`) had
    `return $this->append...()`; removed the `return` (void must not return a value). `[RISK]` (callers
    ignore the return value, so behavior is unchanged).
  - L342 & L374 `@param arrray $columns` typo (created a fake class `arrray`) → `@param array<string, mixed> $columns`
    in `tableHead()` / `tableRow()`. `[SAFE]`
- **`src/Repository/Concerns/RepositoryTrait.php`** — added `return null;` at end of `__get()`; the
  method fell through with no return on the "no matching repository and no parent __get" path. `[RISK]`
  (previously returned `null` implicitly; now explicit — behavior identical).

### Review notes for app authors
- `ApiRequest::parse()` now reads the spec file via `File::get` + `json_decode` instead of the
  non-existent `File::getJson()`. If any app overrode this, align with the new implementation.
- The six `set*Information`/`set*Heading` methods are now truly `void`.

---

## Batch 3 — `Listable.php` (54 → 0) + `RepositoryManager` generic `list()` + abstract `column()` (total saved: ~49, 1146 → 1097)

### Root cause
`Listable` is the trait that powers all repository read/listing. Its errors were mostly untyped
collections/arrays (PHPStan level 8 requires `array<string, mixed>` and `Collection<int, TModel>`
value types) plus two real issues:
- `$model::where(...)` where `$model` is `class-string<TModel>` — PHPStan can't resolve a static call
  on a generic class-string. Fixed by `(new $model)->where(...)`.
- `column()` is called in the trait but only defined on the concrete subclasses
  (`MongoDBRepositoryManager`, `MYSQLRepositoryManager`). Added an abstract declaration on the base.

### Changes (all in `src/Repository/Concerns/Listable.php` unless noted)
- `@var array<string, mixed>` on the `$options` and `$paginationInfo` properties (was untyped `@param`). `[SAFE]`
- `has()` and `getByModel()`: `$model::where(...)` → `(new $model)->where(...)`. `[RISK]` (behaviorally
  identical — instantiates then queries — but creates one extra object per call).
- All `@param array` / `@return array` → `array<string, mixed>`. `[SAFE]`
- `listPublished`, `published`, `listAll`, `listAllPublished`, `listAllModels`, `listAllPublishedModels`,
  `count`, `countPublished`: return type `Collection` → `Collection<int, TModel>` (PHPDoc only —
  **native** `Collection<int, TModel>` is a syntax error in PHP; `php -l` confirmed). `[SAFE]`
- `setPaginateInfo()`: `@param object $data` → `@param \Illuminate\Contracts\Pagination\LengthAwarePaginator $data`. `[SAFE]`
- `getPaginateInfo()` / `getPaginationInfo()` / `decodeArray()`: `@return array` → `array<string, mixed>`. `[SAFE]`
- `wrap()`: param `Model|array` → `Model|array<string, mixed>`; added `/** @var JsonResource $result */`
  before `return new $resource($model)` (returns an object, not a JsonResource). `[SAFE]`
- `wrapMany()`: param `Collection|array` → `Collection<int, Model>|array<string, mixed>`; return
  `ResourceCollection|array` → `ResourceCollection|array<string, mixed>`. `[SAFE]`
- `orderBy()`, `where()`, `whereIn()`, `whereInInt()`: added `if ($this->query === null) return;`
  / `return $this;` guards (`$query` is `Builder|null`). `[RISK]` (these methods were previously
  silently no-op on a null query; now they return early — behaviorally identical for the normal path,
  but a null-query call no longer attempts a method on null).
- `getModel()`: param `int|array|Model` → `int|array<string, mixed>|Model`; added `/** @var TModel $id */`
  before returning the `Model` on the `instanceof` branch. `[SAFE]`
- `getBy()` / `getByModel()`: `@param mixed value` typo → `@param mixed $value`. `[SAFE]`

### Changes in `src/Repository/RepositoryManager.php`
- `list()`: added `@return Collection<int, TModel>` docblock (kept native `: Collection`). `[SAFE]`
- Added `abstract protected function column(string $column): string;` (right after `abstract protected
  function setData(...)`). Both subclasses already implement it. `[SAFE]`

### Review notes for app authors
- `RepositoryManager::column()` is now part of the abstract contract — any custom repository subclass
  that does not implement `column(string $column): string` will now fail to instantiate. The shipped
  `MongoDBRepositoryManager` and `MYSQLRepositoryManager` already implement it.
- `Listable::where()/whereIn()/whereInInt()/orderBy()` now early-return when `$this->query` is null
  (previously they would have errored at runtime too, so this is strictly safer).

---

## Batch 4 — `src/Database/Eloquent/MongoDB/Aggregate/Pipeline.php` (47 → 0) (total saved: ~46, 1097 → 1051)

### Root cause
`Pipeline` is the MongoDB aggregation pipeline builder. Errors were a mix of untyped collections/arrays,
`__call` without types, a real type mismatch on `sum()` (docblock said `float`, body returns `$this` for
fluent chaining), an invalid `UTCDateTime` argument (passed a string instead of int), an undefined
`groupBy()` (delegated via `__call` to the `Aggregate` object but never explicitly exposed), and several
docblock params that didn't match the real signatures (`where()`/`orWhere()` read args via `func_get_args`).

### Changes (all in `src/Database/Eloquent/MongoDB/Aggregate/Pipeline.php`)
- `$data` property `@var array<string, mixed>` → `array<int|string, mixed>`. `[SAFE]`
- `sum()`: `@param string|array` → `array<int|string, mixed>|string`; `@return float` → `@return Pipeline`
  (body returns `$this`; was a docblock/impl mismatch). `[RISK]` (return type is now the fluent builder,
  not the numeric sum — callers that expected a float from `sum()` must now read the result differently;
  verify any `->sum(...)` usage that consumes the returned number).
- `select()` / `unselect()` / `count()`: invalid `@param ...mixed` / `...string` → `@param mixed ...$columns`;
  added native `: Pipeline` return (the methods delegate to `Aggregate` and return the builder). `[SAFE]`
- `where()` / `orWhere()`: removed the formal `@param` (they use `func_get_args`) and added
  `$column = $operator = $value = null;` initialization to fix "variable might not be defined". `[RISK]`
  (initializing to null changes the implicit undefined-var behavior; logic is otherwise identical).
- `whereIn()`: `@param array $array` → `array<int|string, mixed> $array`; added docblock to `whereInInt()`. `[SAFE]`
- `praseDate()`: `new UTCDateTime($date->format('Uv'))` → `new UTCDateTime((int) $date->format('Uv'))`
  (`UTCDateTime` constructor needs an int/timestamp, not a string). `[RISK]` (was passing a string before —
  this is a latent runtime bug fix).
- `getData()`: `@return array<string, mixed>` → `array<int|string, mixed>`. `[SAFE]`
- `limit()` / `skip()`: added `@param int $number` + `: Pipeline`. `[SAFE]`
- `join()` / `unwind()`: added `@param` types + `: Pipeline`. `[SAFE]`
- `data()`: `$key` param widened to `array<int|string, mixed>|int|string|null`; added early
  `if ($key === null) return $this;` guard (prevents passing a null array key through the `where()` path). `[RISK]`
- Added explicit `groupBy(...$columns): Pipeline` that delegates to
  `$this->aggregationFramework->groupBy(...)` (fixes the `$this->groupBy()->sum()` chain). `[SAFE]`
- `__call($name, $arguments): Aggregate` with `@param array<int, mixed> $arguments` and a
  `/** @var callable $callback */` cast for the `call_user_func_array` call. `[SAFE]`

### Corruption incident (LEARNED LESSON)
A batch of edits used overlapping anchors (one edited `unwind`'s docblock while another prepended a
`groupBy` block matching the `@inheritDoc` of `unwind`), producing **duplicate** `groupBy` and `unwind`
methods and a garbage constructor body in the early copy. Fixed by deleting the spurious early block.
**Always run `php -l` after batch edits** — overlapping-anchor multi-edits can silently corrupt files.

### Review notes for app authors
- `Pipeline::sum()` now returns the `Pipeline` (fluent), not the numeric total. If app code does
  `$total = $pipeline->sum('amount')` expecting a number, this is a behavioral change — adjust accordingly.
- `Pipeline::praseDate()` now builds `UTCDateTime` from an int timestamp (correct), previously a string.
- `Pipeline::data()` with a null key now returns `$this` early instead of proceeding.

---

