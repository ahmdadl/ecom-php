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


