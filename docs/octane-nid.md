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
properties, and run queue workers separately from Octane workers. Mongez resets
its own request state at the start of each request. Applications with additional
static state can register a callback with `Mongez::onReset()`.
