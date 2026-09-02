# Plan: PHP 8.5, Laravel 13+, and Octane hardening

Branch suggestion: `php85-laravel13-octane-hardening`

Date: 2026-09-03

## Context

A verification pass confirmed the package **runs on PHP 8.5.9** with **Laravel 13.29.0**
and **mongodb/laravel-mongodb 5.9.1**, and that most Octane safety work from
`OCTANE_MONGODB_V5_PLAN.md` is already applied.

Remaining gaps fall into five workstreams:

1. **Declare PHP 8.5 support** in `composer.json` and tooling.
2. **Align Laravel 13 dev/test tooling** and refresh stale docs.
3. **Fix the `ModelEvents` Octane reset bug** (trait static state leak).
4. **Clear PHPStan level 8 errors** introduced by stricter PHP 8.5 typing.
5. **Strengthen verification** (Octane test, CI matrix, MongoDB test docs).

---

## Current state (verified)

| Check | Result |
|-------|--------|
| PHP runtime | 8.5.9 — unit tests pass |
| `composer.json` PHP constraint | `>=8.4` (does not advertise 8.5) |
| Laravel | `illuminate/support ^11\|^12\|^13`; dev lock at **13.29.0** |
| MongoDB driver | `^5.8`; dev lock at **5.9.1** |
| Testbench | `^11.0`; dev lock at **11.2.0** (Orchestra 11.x targets Laravel 13) |
| Octane unit tests | `OctaneStateResetTest` — 5/5 pass |
| Octane integration | `laravel/octane` only in `suggest`, not tested end-to-end |
| PHPStan | 2 errors at level 8 |
| README | Still references Laravel 11 and MongoDB v4 |
| MongoDB integration tests | 18 tests fail without a local MongoDB on `127.0.0.1:27017` |

---

## Workstream 1 — PHP 8.5 alignment

### Goal

Explicitly support and test against PHP 8.5 while keeping PHP 8.4 compatibility if
desired, or drop 8.4 if the project wants to target 8.5 only.

### Changes

| File | Change |
|------|--------|
| `composer.json` | Update `"php": ">=8.4"` → `"php": "^8.4 \|\| ^8.5"` (or `^8.5` if 8.4 support is dropped) |
| `rector.php` | Confirm `withPhpSets()` includes 8.5 rules; optionally pin `->withPhpVersion(PhpVersion::PHP_85)` |
| CI (if added) | Matrix: PHP 8.4 + 8.5 minimum |

### Decision needed

- **Option A (recommended):** `^8.4 || ^8.5` — broad compatibility, test both.
- **Option B:** `^8.5` only — simpler, matches “latest PHP 8.5” requirement literally.

---

## Workstream 2 — Laravel 13+ alignment

### Goal

Ensure declared constraints, dev dependencies, and documentation all reflect Laravel 13
and MongoDB driver v5.

### Changes

| File | Change |
|------|--------|
| `composer.json` | Keep `"illuminate/support": "^11.0\|^12.0\|^13.0"` (already correct) |
| `composer.json` | Keep `"mongodb/laravel-mongodb": "^5.8"` (already correct) |
| `composer.json` | Confirm `"orchestra/testbench": "^11.0"` — Orchestra 11.x maps to Laravel 13; add a comment in this plan only (not in code) |
| `README.md` | Update Octane section: Laravel **11–13**, `mongodb/laravel-mongodb` **v5+** |
| `README.md` | Add requirements block: PHP ^8.4\|^8.5, Laravel ^11\|^12\|^13 |
| `OCTANE_MONGODB_V5_PLAN.md` | Mark remaining items as done or archive; link to this plan for follow-up |

### No change required

- `orchestra/testbench` version `11.2.0` already pulls `laravel/framework ^13.23`.
  The major version number is Orchestra’s scheme, not Laravel’s.

---

## Workstream 3 — Octane: fix `ModelEvents` static reset

### Problem

`MongezOctaneServiceProvider::resetApplicationState()` calls:

```php
ModelEvents::resetState();
```

`ModelEvents` is a **trait**. In PHP, each model class that uses the trait gets its own
copy of `$modelClass`, `$modelOptions`, and `$sharedInfoMethod`. Calling
`ModelEvents::resetState()` resets the trait’s empty alias, **not** the per-model copies
(e.g. `Product::$modelClass`).

During create/update/delete handlers, those static properties are mutated and are not
always cleared when the handler finishes. Under Octane, the last value from request *N*
can leak into request *N+1* on the same worker.

### Fix

| File | Change |
|------|--------|
| `src/Providers/MongezOctaneServiceProvider.php` | Remove standalone `ModelEvents::resetState()` call |
| `src/Providers/MongezOctaneServiceProvider.php` | In `resetModelsState()`, after discovering model classes, call `$class::resetState()` on each class that uses `ModelEvents` (check via `class_uses_recursive`) |
| `src/Database/Eloquent/ModelEvents.php` | No API change required; keep `resetState()` on the trait so `$class::resetState()` works |
| `tests/OctaneStateResetTest.php` | Add `testModelEventsResetClearsPerClassStaticState()`: set `$modelClass` on `ModelStub`, call reset via Octane provider path, assert cleared |

### Alternative (not recommended)

Move `resetState()` into `ModelTrait::resetStaticState()` and delegate from there.
Rejected because `ModelEvents` is not part of `ModelTrait` and not every `ModelTrait`
consumer uses `ModelEvents`.

### Already correct (no change)

- `Mongez::reset()`, `JsonResourceManager::reset()`, `Events::reset()`
- Repository `resetCurrentResource()` loop
- `ModelTrait::resetStaticState()` via model discovery
- `MongezRequestMiddleware` beta DB + locale restore in `finally`
- `$middlewarePushed` guard in `MongezServiceProvider`
- `die()` only in console commands and `pred()` debug helper

---

## Workstream 4 — PHPStan level 8 fixes

### Error 1: `Aggregate.php:61`

```
Parameter #2 $string of function explode expects string, string|null given.
```

**Location:** `groupBy(...$columns)` — variadic `$column` can be `null` when called as
`groupBy(null)`.

**Fix:** Guard before `explode`:

```php
foreach ($columns as $column) {
    if ($column === null) {
        continue; // or handle the single-null case explicitly
    }
    [$name] = explode('.', $column);
    ...
}
```

Or narrow the `@param` and add an early return when the only argument is `null` (logic
already partially handles this at lines 57–58).

### Error 2: `Events.php:156`

```
Parameter #2 $array of function implode expects array<string>, array<int, object|string> given.
```

**Location:** `subscribe()` — `implode('@', $eventListener)` when `$eventListener` is
an array.

**Fix:** Normalize to strings before implode:

```php
if (is_array($eventListener)) {
    $eventListener = implode('@', array_map(
        static fn ($part) => is_object($part) ? $part::class : (string) $part,
        $eventListener
    ));
}
```

Or tighten the `@param` to `array<int, string>` if objects are never passed in practice.

### Verification

```bash
composer phpstan   # must exit 0
```

---

## Workstream 5 — Testing and CI

### Goal

Make “PHP 8.5 + Laravel 13 + Octane safe” verifiable on every change.

### Changes

| File | Change |
|------|--------|
| `composer.json` | Add `"laravel/octane": "^2.0"` to `require-dev` (optional but recommended for integration confidence) |
| `tests/OctaneStateResetTest.php` | Extend with `ModelEvents` per-class reset test (see Workstream 3) |
| `tests/ModelEventsOctaneResetTest.php` | *(optional split)* Dedicated test if `OctaneStateResetTest` grows too large |
| `phpunit.xml.dist` | Add `<env name="DB_URI" value="..."/>` comment or `.env.testing` example for MongoDB |
| `.github/workflows/tests.yml` | **New** — matrix: PHP 8.4 + 8.5, Laravel 13; services: MongoDB; steps: `composer test`, `composer phpstan` |
| `README.md` | Document local test setup: MongoDB required for integration tests; Octane tests are unit-only |

### MongoDB integration tests

The 18 failing tests are **environment failures** (no MongoDB), not compatibility bugs.
CI should start a MongoDB service container. Locally, document:

```bash
# Example
DB_URI=mongodb://127.0.0.1:27017/mongez_test composer test
```

### Octane dev dependency rationale

Adding `laravel/octane` to `require-dev` allows:

- Confirming `MongezOctaneServiceProvider` registers when Octane is present
- Future integration test that simulates `RequestReceived` through the real event dispatcher

Keep it in `suggest` for consumers; duplicate in `require-dev` for package development.

---

## Implementation order

Execute in this sequence to avoid rework:

```
Phase 1 — Correctness (Octane + PHPStan)
  ├── Fix ModelEvents reset in MongezOctaneServiceProvider
  ├── Add OctaneStateResetTest for ModelEvents
  ├── Fix Aggregate.php nullable column
  └── Fix Events.php implode typing

Phase 2 — Declarations
  ├── Update composer.json PHP constraint
  └── Refresh README requirements + Octane section

Phase 3 — Verification infrastructure
  ├── Add GitHub Actions workflow (PHP 8.4/8.5, MongoDB service)
  ├── Add laravel/octane to require-dev (optional)
  └── Run full test + phpstan locally

Phase 4 — Docs cleanup
  ├── Update OCTANE_MONGODB_V5_PLAN.md status
  └── Close or archive stale plan items
```

---

## Verification checklist

Run before merge:

- [ ] `composer validate`
- [ ] `composer phpstan` — 0 errors
- [ ] `vendor/bin/phpunit` — all tests pass (MongoDB running for integration suite)
- [ ] `vendor/bin/phpunit --filter OctaneStateResetTest` — includes new ModelEvents test
- [ ] PHP 8.5.9: full suite green
- [ ] PHP 8.4.x: full suite green (if keeping `^8.4` support)
- [ ] Manual Octane smoke (consumer app):
  - [ ] `composer require laravel/octane`
  - [ ] Two sequential requests with different `LOCALE-CODE` headers — locales do not leak
  - [ ] Model create with `onModel` relations — no cross-request `$modelClass` bleed

---

## Files touched (summary)

| File | Action |
|------|--------|
| `composer.json` | PHP constraint; optional `laravel/octane` dev dep |
| `src/Providers/MongezOctaneServiceProvider.php` | Fix ModelEvents reset |
| `src/Database/Eloquent/MongoDB/Aggregate/Aggregate.php` | PHPStan: null guard |
| `src/Events/Events.php` | PHPStan: implode typing |
| `tests/OctaneStateResetTest.php` | New ModelEvents reset test |
| `README.md` | PHP 8.5, Laravel 13, MongoDB v5, test setup |
| `.github/workflows/tests.yml` | New CI matrix |
| `OCTANE_MONGODB_V5_PLAN.md` | Status update / archive note |

---

## Out of scope (for this plan)

- Implementing the caching plan in `docs/caching-plan.md`
- Consumer-app migration scripts for `id` → `nid` (covered in `OCTANE_MONGODB_V5_PLAN.md`)
- Dropping Laravel 11/12 support (constraints already allow them; no action needed)
- Running `codegraph upgrade` or other tooling unrelated to this verification

---

## Success criteria

The package can be described as:

> **PHP 8.5-ready, Laravel 13+ compatible, and Octane-safe** — with automated tests and
> static analysis proving per-request state isolation, including `ModelEvents` static
> properties on every model class.
