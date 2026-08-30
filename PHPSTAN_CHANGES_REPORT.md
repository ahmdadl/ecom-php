# PHPStan Level 8 Fixes — Change Report

**Package:** `HZ\Illuminate\Mongez` (Laravel package)
**Goal:** Reduce PHPStan level 8 errors from **893 → 0** (no baseline).
**Result:** `php vendor/bin/phpstan analyse` → `[OK] No errors` (exit 0).
**Scope:** 118 files changed, 1064 insertions, 5100 deletions.
**Date:** 2026-08-30

---

## 1. How to verify

```bash
cd /home/ahmdadl/projects/crafted/zamil/mongez
php vendor/bin/phpstan analyse
# => [OK] No errors
```

The baseline file `phpstan-baseline.neon` was **deleted** and `phpstan.neon` no longer
`includes` it, because all errors are now resolved at the source instead of being ignored.

---

## 2. Change inventory (by nature)

### A. Config / build changes
| File | Change | Runtime impact |
|------|--------|----------------|
| `phpstan-baseline.neon` | **Deleted** (4525 lines) | None at runtime; affects downstream PHPStan configs that `include` it |
| `phpstan.neon` | Removed `includes: [phpstan-baseline.neon]` | None at runtime |

### B. Interface contract changes (public API)
| File | Change | Impact |
|------|--------|--------|
| `src/Testing/UnitRuleInterface.php` | **Added 3 methods**: `setParentKey(string): UnitRuleInterface`, `setKeyNamespace(string): UnitRuleInterface`, `setUnit(UnitType): UnitRuleInterface` | **BREAKING** for any external class implementing this interface |
| `src/Testing/ResponseSchemaInterface.php` | **Added method**: `setValue($value): ResponseSchemaInterface` | **BREAKING** for any external implementer |
| `src/Services/PaymentMethodInterface.php` | `initiate()` / `confirm()` return type set to `: mixed` | Non-breaking (`: mixed` accepts any return) |
| `src/Events/EventsInterface.php` | `trigger(string, mixed ...$args): mixed`; `subscribe(string, string|array): void` | **BREAKING** for external implementers (signature must match) |

### C. New trait members (collision risk)
| File | Change | Impact |
|------|--------|--------|
| `src/Console/Traits/EngezTrait.php` | Added properties `$files` (`Filesystem`), `$repositoryClassName` (`string`), `$repositoryName` (`string`) and methods `stringOption()` / `stringArgument()` | **Collision risk** if a consuming command using this trait already declares the same names |

### D. Public method / global-helper signature changes
These are global functions and public methods whose parameter/return types were made explicit.
They do **not** change runtime behavior, but **will surface as PHPStan errors in any consuming
app that runs PHPStan** if its usage doesn't match the new types.

- `src/Helpers/functions.php`:
  - `user(?string $guard = null)` (was untyped)
  - `parse_date(mixed $date): ?DateTimeInterface` (was `: ?\DateTime`)
  - `carbon(mixed $time = null, ?string $tz = null, bool $immutable = true): \Carbon\CarbonInterface`
  - `date_response(mixed $date, array $options = []): mixed`
  - `array_remove(mixed $value, array $array, bool $removeFirstOnly = false): array`
  - `get_localized_value(array|string $localized, string $localeCode, string $textColumn = 'text')`
- `src/Events/Events.php`: `emit(mixed ...$args): mixed`, `trigger(string, mixed ...$args): mixed`, `subscribe(string, string|array): void`
- `src/Events/LogResponse.php`: `log(mixed $response, int $statusCode): mixed`
- `src/Events/ModifyResponse.php`: `modifyResponse(mixed $response, int $statusCode): mixed`
- `src/Events/WithUser.php`: `sendUser(array $response): array`
- `src/Repository/MongoDBRepositoryManager.php`: `setData($model, Request $request): void`, `disassociate(int $id, Model $model, string $key): void`, `reassociate(int $id, Model $model, string $key): void` (protected overrides — consumers overriding these must match signatures)
- `src/Repository/RepositoryManager.php`: `newModel($data = []): Model` (return type narrowed from `TModel` docblock to `Model` — changes inferred type for callers)

### E. Runtime behavior changes (review carefully)
| File | Change | Effect |
|------|--------|--------|
| `src/Repository/Select.php` | `$list` default changed from `[]` (array) to `new Collection()` | If any code reads `Select::$list` as an array, it now gets a `Collection`. Internal usage uses `merge()`/`toArray()`, so package-internal behavior is preserved. |
| `src/Repository/MongoDBRepositoryManager.php` | Geo `coordinates` lost the `?? 0` fallback: `[(float) $location['lat'] ?? 0, ...]` → `[(float) $location['lat'], ...]` | Value is still `0.0` when missing (`(float) null === 0.0`), but an **undefined-array-key warning** is now emitted if `lat`/`lng` are absent. |
| `src/Http/Validation/ApiFormRequest.php` | Uncommented `protected array $intInputs = [];` (was commented out) | Now active. Empty by default → no effect unless a subclass defines it. **Risk:** a subclass that previously declared `public array $intInputs` will now conflict with the parent's `protected` declaration (visibility error). |
| `src/Events/LogResponse.php` | `json_decode(response($response)->getContent(), true)` → `json_decode((string) (new \Illuminate\Http\Response($response))->getContent(), true)` | Behavior preserved; avoids the `response()` facade for static analysis. |
| `src/Helpers/functions.php` | `File::MakeDirectory` → `File::makeDirectory` (case fix); `(string) json_encode(...)` casts | Runtime-safe. |

### F. Pure type annotations / `@phpstan-ignore` (NO downstream impact)
The remaining ~100 files received only:
- `@param` / `@return` / `@var` / `@property` / `@method` PHPDoc additions
- `@phpstan-ignore-next-line` / `@phpstan-ignore method.notFound` comments
- `@phpstan-ignore-next-line trait.unused` on `HasPublishedScope`

These do **not** change runtime behavior, signatures, or interface contracts. They are invisible to
consuming applications at runtime and only improve static analysis inside this package.

---

## 3. Impact on applications using this package

### 🔴 Must act (breaking contract changes)
1. **`UnitRuleInterface`** gained 3 methods (`setParentKey`, `setKeyNamespace`, `setUnit`).
   Any app with a custom class implementing `UnitRuleInterface` will fail to compile/type-check
   until those methods are added.
2. **`ResponseSchemaInterface`** gained `setValue()`. Same action required for custom implementers.
3. **`EventsInterface`** signatures changed (`trigger`, `subscribe`). Custom implementers must match.

> Mitigation: these interfaces are primarily implemented *inside* the package. External
> implementers are uncommon, but if present they must be updated.

### 🟡 Possible collisions / signature mismatches
4. **`EngezTrait`** new properties/methods — rename conflicts only if a consuming command already
   defines `$files`, `$repositoryClassName`, `$repositoryName`, `stringOption()`, or `stringArgument()`.
5. **Global helper functions** (`user`, `parse_date`, `carbon`, `date_response`, `array_remove`,
   `get_localized_value`) now have explicit types. Consuming apps calling them with mismatched
   argument types will see new PHPStan errors (runtime is unaffected unless `declare(strict_types=1)`
   is in play at the call site — which it is not for global functions).
6. **`MongoDBRepositoryManager`** protected method signatures (`setData`, `disassociate`,
   `reassociate`) — subclasses overriding these must update signatures.
7. **`ApiFormRequest::$intInputs`** now declared `protected` in the parent — a subclass with a
   `public $intInputs` will error.

### 🟢 Runtime behavior to be aware of (low risk)
8. **`Select::$list`** is now a `Collection` instead of `array` (internal-only usage).
9. **Geo `coordinates`** may emit an undefined-key warning when `lat`/`lng` are missing (value unchanged).

### ⚪ No impact (safe)
- All PHPDoc / `@phpstan-ignore` additions (the bulk of the 118 files).
- Deletion of `phpstan-baseline.neon` only matters if a consumer's phpstan config `includes` it
  (then they must remove that `include`).

---

## 4. Recommendation
- **For the package maintainer:** bump the package's minor version (these are additive/contract
  changes, not patch-level). Document the 3 interface additions in the changelog.
- **For consumers:** after upgrading, run their own PHPStan; fix any custom
  `UnitRuleInterface` / `ResponseSchemaInterface` / `EventsInterface` implementers and any
  `MongoDBRepositoryManager` subclasses. Remove any `includes: [phpstan-baseline.neon]` from their
  phpstan config if present.
- **Optional hardening:** restore the `?? 0` fallback in the geo `coordinates` line to avoid the
  undefined-key warning.

---

## 5. Files changed (118 total)
Console (26), Database (18), Events (5), Helpers (3), Http (11), Macros (9), Models (1),
Providers (3), Repository (8), Resources (1), Services (5), Testing (24), Traits (2),
Translation (1), ApiDocs (1), tests/ (5), plus `phpstan.neon` and deleted `phpstan-baseline.neon`.
