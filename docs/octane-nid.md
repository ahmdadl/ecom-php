# Mongez with Octane and MongoDB v5

Mongez models use MongoDB's `_id` as the driver identity and `nid` as the
application's numeric identity. Use `nid` in resources, relationships, route
parameters, filters, and repository queries.

## Temporary `id` → `nid` compatibility shim

Large apps migrating from the old integer `id` column can enable an opt-in
compat shim while they rename call sites:

```dotenv
MONGEZ_MONGODB_ID_ALIASES_NID=true
```

Or in config:

```php
// config/mongez.php
'mongodb' => [
    'id_aliases_nid' => true,
],
```

When enabled:

- `$model->id` returns integer `nid` (not the ObjectId string)
- Resource helpers that read `'id'` (for example `INTEGER_DATA`) resolve `nid`
- `sharedInfo()` keeps emitting `'id'` as the integer when `SHARED_INFO` still
  lists `'id'`

`$model->id` is a **compat alias**, not the driver document key. Prefer
`$model->nid` in new code. Use `$model->_id` when you need the ObjectId.

### Recommended migration

1. Enable `mongez.mongodb.id_aliases_nid` in staging.
2. Run the audit CLI and triage findings:

```bash
php artisan mongez:audit-nid
php artisan mongez:audit-nid --json
php artisan mongez:audit-nid --path=app/Modules/Orders
```

Exit code `0` means no findings; `1` means findings (CI-friendly).

3. Rename resources/models (`INTEGER_DATA` / `SHARED_INFO` → `nid`) and high-traffic
   `$model->id` reads to `$model->nid`.
4. When the audit is clean (or exceptions are accepted), set the shim to `false`
   and re-soak under Octane.

Do not leave the shim enabled indefinitely — it hides incomplete renames.

## Related-model queues

Related-model propagation is synchronous by default. Enable it globally with:

```dotenv
MONGEZ_QUEUE_RELATED_MODELS=true
MONGEZ_QUEUE_CONNECTION=redis
MONGEZ_QUEUE_NAME=mongez-related
```

Individual models can override the global setting with
`RELATED_MODELS_QUEUE_MODE` or `RELATED_MODELS_MODES`. Queue propagation runs
after the surrounding transaction commits.

## Migrating existing collections

The migration command is a dry run unless `--execute` is supplied:

```bash
php artisan mongez:migrate-nid
php artisan mongez:migrate-nid --execute --rebuild-counters
php artisan mongez:ensure-nid-indexes
php artisan mongez:ensure-nid-indexes --execute
php artisan mongez:nid-health
php artisan mongez:audit-nid
```

Back up MongoDB before executing the migration. The command migrates top-level
fields only; embedded documents must be migrated according to their schema.

## Octane requirements

Keep request-specific services scoped, do not cache request data in static
properties, and run queue workers separately from Octane workers.

When Octane is installed, `MongezOctaneServiceProvider` resets Mongez package
state on every `RequestReceived` event (`Mongez::reset()`,
`JsonResourceManager::reset()`, events, repositories, and model statics). Do
**not** re-call those package resets from an app listener — they are redundant
and easy to drift from the package. Keep public `reset()` APIs callable for
tests and non-Octane scripts; just stop invoking them from consumer Octane
flush listeners.

### Register application static state

App-owned statics (for example `Application::$currentApplicationType`) should
clear via Mongez, not via a second listener that reaches into Mongez internals.

**Option A — `RequestScoped` trait (recommended):**

```php
use HZ\Illuminate\Mongez\Support\RequestScoped;

class Application
{
    use RequestScoped;

    public static string $currentApplicationType = '';

    protected static function requestScopedDefaults(): array
    {
        return ['currentApplicationType' => ''];
    }
}

// AppServiceProvider::boot()
Application::registerRequestScopedDefaults();
```

**Option B — manual callback:**

```php
use HZ\Illuminate\Mongez\Helpers\Mongez;

// Prefer onBootReset so the callback survives every request reset.
Mongez::onBootReset(static function (): void {
    Application::$currentApplicationType = '';
});
```

### Boot-time vs request-time callbacks

| API | Survives `Mongez::reset()`? | Use for |
|-----|-----------------------------|---------|
| `Mongez::onBootReset($cb)` | Yes (always) | App static cleanup from service providers |
| `Mongez::onReset($cb)` before `snapshotBaseState()` | Yes (captured in boot snapshot) | Same, if registered during boot |
| `Mongez::onReset($cb)` after `snapshotBaseState()` | Once only | Ephemeral per-request cleanup |

`MongezOctaneServiceProvider` calls `Mongez::snapshotBaseState()` inside an
application `booted` callback. Prefer `onBootReset()` / `RequestScoped` so
registration order does not matter.

`Mongez::forgetRequestState()` is an alias of `Mongez::reset()`.

Slim app Octane listeners to **app-only** work (temp dirs, tagged cache on
`WorkerStarting`, etc.). See the Phase 2 adoption checklist in
`API_ZAMIL_OCTANE_FEATURES_PLAN.md`.
