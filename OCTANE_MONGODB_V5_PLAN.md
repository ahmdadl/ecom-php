# Plan: Make Mongez Octane-safe & compatible with mongodb/laravel-mongodb v5+

**Status: ✅ COMPLETED** — See [`PHP85_LARAVEL13_OCTANE_PLAN.md`](./PHP85_LARAVEL13_OCTANE_PLAN.md) for the verification pass and final hardening work.

Branch: `octane-safe-mongodb-v5`

## Context

The package (`ahmdadl/ecom-php`, namespace `HZ\Illuminate\Mongez`) currently requires
`mongodb/laravel-mongodb: ^4.0` and is not safe to run under Laravel Octane. Two workstreams:

1. **MongoDB v5 compatibility** — v5 introduces an `id` ↔ `_id` aliasing that breaks the
   package's integer auto-increment `id` strategy.
2. **Octane safety** — request-scoped state leaks across requests and `die()` in the
   service provider would kill Octane workers.

---

## Why the naive fix failed

Registering the existing `CustomMongoGrammar` (which skips the `id`→`_id` rewrite) is
**not enough**. v5's `MongoDB\Laravel\Eloquent` `DocumentModel` trait owns the aliasing:

- `getIdAttribute()`: `$value ??= $attributes['id'] ?? $attributes['_id'] ?? null;`
- `performInsert()` assigns the returned `_id` to the model's `id` after every insert.

So a **new** model ends up with `id` = the ObjectId `_id` (the integer `id` you set is
overwritten), and `$model->refresh()` re-reads it as the ObjectId. Existing documents only
"work" because their stored `id` field is still present in `attributes`. The alias cannot
be disabled at the grammar level — the model layer owns it.

---

## Decision: separate `nid` column, exposed everywhere (revised)

Keep `_id` as MongoDB's native ObjectId; add `nid` as the integer business key.

> **Revision (final):** the original plan proposed a `numericId` column with a
> `getIdAttribute()` alias so `$model->id` kept returning an integer. Final decision:
> column is named **`nid`** and it is **exposed everywhere** as a **breaking change**.
> No `getIdAttribute` aliasing — per v5 defaults `$model->id` returns the ObjectId
> `_id`; all package code and generated code use `nid` instead.

- Lighter & safer migration than "store the integer in `_id`" (which would require
  re-inserting every document to change `_id`'s type).
- The migration is a single **in-place** `updateMany` per collection (field rename,
  `_id` is never touched).
- Consumer code churn: anything that previously read the integer (`$model->id`,
  resource `DATA`/`INTEGER_DATA`, filters, embedded `sharedInfo()` sub-documents)
  now reads/writes `nid`.
- `CustomMongoGrammar` is removed (we stop querying the `id` column).

---

## Package changes (applied)

| File | Change |
|---|---|
| `src/Database/Eloquent/MongoDB/Model.php` | `$primaryKey = 'nid'` (was `'id'`, keep `$keyType = 'int'`); `creating` event sets `nid = nextId()`; `find($id)` → `where('nid', (int) $id)->first()`; `sharedInfo()` unsets `_id` **and** legacy `id`; no `getIdAttribute()` override (`$model->id` returns ObjectId per v5); `nextId()` / `lastInsertId()` keep using the `ids` collection (its own integer `id` field) |
| `src/Database/Eloquent/ModelTrait.php` | `getNid()` → `return $this->nid ?? static::getNextId();`; `getId()` kept as deprecated alias delegating to `getNid()`; updatesLog stores `'nid' => $model->nid` |
| `src/Database/Eloquent/MongoDB/RecycleBin.php` | trash insert / restore use `$this->nid` / `$record->nid` (`primaryId` trash-table column name unchanged) |
| `src/Database/Eloquent/HasPublishedScope.php` | `findPublished` queries `nid` |
| `src/Database/Eloquent/GeneralScopes.php` | `scopeFor` → `"{$key}.nid"` |
| `src/Database/Eloquent/ModelEvents.php` | searchingColumn suffix `.nid`; related-model lookups read `['nid']` keys, `whereIn('nid', ...)`, compare `$model->nid` |
| `src/Database/Eloquent/Associatable.php` | `reassociate()` / `disassociate()` default search columns → `nid` |
| `src/Repository/Concerns/Listable.php` | `has()`, `publish()`, `get()`, `getModel()` default/query on `nid` |
| `src/Repository/Concerns/Deletable.php` | triggers + cache forget use `$model->nid` |
| `src/Repository/RepositoryManager.php` | `ORDER_BY = ['nid', 'DESC']`; `setCache($model->nid)` |
| `src/Repository/MongoDBRepositoryManager.php` | document sync: `whereIn('nid', $ids)`, request-order sort by `$record['nid']` |
| `src/Http/RestfulApiController.php` / `AdminUIController.php` | unique-rule ignore column → `nid`; store redirect uses `$model->nid` (error payload key `id` kept) |
| `src/Http/Validation/Unique.php` | default ignoreColumn → `nid` |
| `src/Resources/JsonResourceManager.php` | protected `id()` helper returns `$this->data['nid']`; new protected `nid()` helper |
| `src/Console/Commands/EngezModel.php` | shared-info default `nid`; mongo schema cast `nid => int` |
| `src/Console/Commands/EngezResource.php` | int data default/appends `nid` |
| `src/Console/Commands/EngezRepository.php` | `setSearchFilters('inInt', ['nid'])` |
| `src/Console/Commands/EngezMigration.php` | default primaryKey `nid` |
| `src/Testing/TestResponse.php` | `getLastInsertId()` reads `data.record.nid` |
| `src/Testing/Units/{IdUnit,UserUnit}.php` | unit NAME / user unit key → `nid` |
| `src/Database/Seeders/SeederManager.php` | seeder relation values use `$model->nid` |
| `module/*` stubs | tests read `data.record.nid`; model-unit `nid => nid`; model docblocks `.nid` |
| `cloneable-modules/*` (MongoDB variants only) | ContactUs resource/model, settings mongo migration unique index, users group re-embed query → `nid` |
| `src/Database/Query/Grammars/CustomMongoGrammar.php` | **removed** (+ unused import in provider) |
| `src/Console/Commands/MongezTestCommand.php` | `DB::getMongoDB()` (gone in v5) → `DB::connection('mongodb')->getMongoDB()` |

### Notes
- `ids` collection logic is unchanged: it uses its own `id` field for counters.
- MYSQL path untouched — MySQL models/resources keep `id`.
- Dual-driver sample resources in `cloneable-modules/users` & `settings` left reading
  `id`; consumers running MongoDB should switch their copies to `nid`.

---

## Migration script (one-time, run against each database)

```js
db.getCollectionNames().forEach(c => {
    if (c === 'ids') return;
    db[c].updateMany(
        { id: { $exists: true } },
        [{ $set: { nid: "$id" } }, { $unset: "id" }]
    );
    db[c].createIndex({ nid: 1 });
});

// Seed the ids collection with max(nid) per collection
db.getCollectionNames().forEach(c => {
    if (c === 'ids') return;
    const max = db[c].aggregate([
        { $group: { _id: null, m: { $max: "$nid" } } }
    ]).toArray()[0];
    if (max) {
        db.ids.updateOne(
            { collection: c },
            { $set: { id: max.m } },
            { upsert: true }
        );
    }
});
```

> **Caveat:** the pipeline above only rewrites **top-level** `id` fields. Embedded
> sub-document arrays (MODEL_LINKS / sharedInfo embeds such as `createdBy.id`,
> `group.id`, items arrays, etc.) keep their old keys — run targeted per-collection
> pipeline updates for those in each consumer app, or re-save affected documents.

---

## Octane safety changes

| File | Change |
|---|---|
| `src/Http/Middleware/MongezRequestMiddleware.php` | **New.** Per-request: detect locale (headers/input) + return JSON for `OPTIONS` (no `die()` that kills the worker) |
| `src/Helpers/Mongez.php` | `setLocaleFromRequest($request)` — sets locale per request and **resets** it when none is provided (avoids leaking the previous request's locale). *(already added)* |
| `src/Providers/MongezServiceProvider.php` | Remove locale/OPTIONS logic from `boot()`; register the middleware via the kernel (guarded static flag so it isn't double-pushed); add `Laravel\Octane\Octane::requestReceived` listener (guarded by `class_exists`) calling `Mongez::setLocaleFromRequest()` |
| `src/Helpers/functions.php` | Remove the `static $locale` cache in `localized_date()` (it leaked the first request's locale) |
| `JsonResourceManager` / `Listable` / `ModelTrait` static configs | `disabledKeys`, `currentDefaultResource`, `disableUpdateTime` are per-class config (no in-package per-request mutation) — leave as-is; revisit only if consumers report leaks |

---

## Already applied (on this branch)

- `composer.json`: `mongodb/laravel-mongodb` `^4.0` → `^5.0`
- `src/Database/Eloquent/MongoDB/Database.php:18`: `getMongoDB()` → `getDatabase()`
- `src/Helpers/Mongez.php`: `setLocaleFromRequest()` method added
- All package changes in the table above (nid migration, grammar removal,
  MongezTestCommand fix)
- Migration script updated to `numericId` → `nid`

## Remaining to apply

- New middleware + service provider wiring + Octane listener (Octane workstream)
- Remove `localized_date()` static cache
- Migration script (run against each consumer database; nested embeds need targeted
  updates per the caveat above)

---

## Verification

- `composer validate`
- `php -l` on every changed file
- Confirm the package resolves against `mongodb/laravel-mongodb: ^5.0` (`composer update`
  in a consumer app / test harness)
- Smoke test: create a model → `refresh()` → assert `$model->nid` is the integer and
  `$model->id` returns the ObjectId (v5 default); list/find by nid works; resources
  output integer `nid`.
