# Mongez with Octane and MongoDB v5

Mongez models use MongoDB's `_id` as the driver identity and `nid` as the
application's numeric identity. Use `nid` in resources, relationships, route
parameters, filters, and repository queries.

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
```

Back up MongoDB before executing the migration. The command migrates top-level
fields only; embedded documents must be migrated according to their schema.

## Octane requirements

Keep request-specific services scoped, do not cache request data in static
properties, and run queue workers separately from Octane workers. Mongez resets
its own request state at the start of each request. Applications with additional
static state can register a callback with `Mongez::onReset()`.
