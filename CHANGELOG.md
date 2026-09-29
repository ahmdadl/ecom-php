# Changelog

## Unreleased

### Changed

- `mongez:migrate-nid` is now the single command for the whole `id` -> `nid`
  cutover, replacing the overlapping `mongez:nid-health`,
  `mongez:ensure-nid-indexes` and `mongez:check-duplicates`. It mirrors
  `scripts/mongo-nid-migration.js` and runs five phases in order — `inventory`,
  `rename`, `counters`, `indexes`, `verify` — each selectable with `--phase`.
  Still a dry run unless `--execute` is passed.
  - **Breaking:** `--rebuild-counters` is gone; the `counters` phase runs by
    default. `mongez:nid-health`, `mongez:ensure-nid-indexes` and
    `mongez:check-duplicates` are removed.
  - `--top-level-only` restricts the rename to the top-level `id` and uses
    server-side updates instead of rewriting documents.
  - `--skip-path` and the new `mongez.nid.skip_paths` config leave opaque
    nested `id` keys (e.g. a payment provider payload) untouched.
  - `mongez.nid.indexes` config drives which `nid` indexes each collection gets;
    `mongez.nid.default_nid_index` turns the implicit unique `nid_1` off.
- Fixed `mongez:migrate-nid --execute` writing the literal string `"$id"` into
  `nid` instead of the document's own `id` value.
- Fixed a dry run printing "the exit code is not gated" and then exiting
  non-zero anyway. Only `--execute`, and a `--phase=verify`-only run, gate on
  findings now.
- Fixed a `*Trash` collection permanently failing the run. Those collections are
  keyed by `primaryId` and keep the deleted document's identity under
  `record.nid`, so they have no top-level `nid` and a unique `nid_1` cannot
  apply to them. They are now reported as a warning you can silence by opting
  the collection out of `mongez.nid.indexes`, instead of counted as a blocker.
- Fixed an existing index being accepted on name alone. A non-unique `nid_1`
  left over from an earlier migration no longer stands in for the unique index
  the plan requires: the `indexes` phase replaces it, and `verify` fails on it.
- Fixed the `inventory` and `indexes` phases judging a collection on the `nid`
  values stored at that moment. A dry run reaches them with the rename still
  pending, so a database that had not been migrated yet read as unable to take
  a unique `nid` index and each of its collections was reported as a gap. Both
  phases now judge the state the run ends in: a document on `id` counts as
  one that will carry `nid`. Two consequences:
  - A collection whose documents are simply still on `id` is no longer blocked
    — it used to fail a migration that then went on to complete cleanly.
  - Duplicate detection now groups on the value `id` is about to become, so a
    half-migrated collection is no longer reported clean. Colliding `id` and
    `nid` values were invisible before, and reached the unique index, which
    then refused to build.
- That projection is now scoped per phase rather than applied to the whole run.
  `inventory` always projects, because it is a pre-flight report and
  `--phase=inventory` used to reach a different verdict from the default
  five-phase run. The `indexes` and `verify` phases project only when this same
  invocation also renames — asked to index a database still on `id`, they now
  say so instead of assuming a rename that was not requested.
- Fixed `--phase=indexes` advising a collection to be opted out of
  `mongez.nid.indexes` when the real problem was that it had not been migrated.
  A `*Trash` collection has no top-level identity by design and stays a
  warning; a collection still sitting on `id` is waiting for the rename and is
  now a blocker that says to include the rename phase.

### Added

- `HZ\Illuminate\Mongez\Support\NidKeyRenamer`, `NidIndexSpec` and
  `NidIndexPlan`.
- `scripts/mongo-nid-migration.js`, the standalone `mongosh` equivalent.

## [5.3.0] - 2026-09-08

### Added

- `Mongez::onBootReset()` for boot-persistent Octane cleanup callbacks (safe after
  `snapshotBaseState()`).
- `Mongez::forgetRequestState()` alias of `Mongez::reset()`.
- `HZ\Illuminate\Mongez\Support\RequestScoped` trait to declare request-scoped
  static defaults and auto-subscribe them to `Mongez::onBootReset()`.
- Model embed helpers: `patchEmbedded()` and `refreshEmbeddedSharedInfo()` on
  `Associatable` (partial list updates + sharedInfo refresh for singular/list embeds).
- Repository wrappers: `MongoDBRepositoryManager::patchEmbedded()`,
  `refreshEmbeddedSharedInfo()`, and corrected `reassociate()` / `disassociate()`.
- Mongo filter sugar: `embeddedNid`, `inEmbeddedNid`, `localizedLike`, `localized`
  (plus fixed `inBool` / `notInBool` / float in-map bindings).
- Test helpers: `TestResponse::assertRecordNid()` and `assertRecordsHaveNid()`.
- Opt-in `Repository\Concerns\HasSettings` for dotted `getSetting('group.key')`
  with request-scoped load tree, optional durable cache
  (`mongez.settings.*`), and `registerSettingsRequestFlush()` for Octane.
- Aggregate polish: `paginate()`, `hydrate()`, `wrapAs()`, `chunk()`, `cursor()`,
  and `toPaginationPipelines()`.
- `Testing\Traits\SimulatesOctaneRequests` for multi-request / locale isolation
  in feature tests.
- Reporting primitives: `Support\PeriodDateCalculator`, `Support\MongoDate`,
  helpers `to_mongo_date` / `from_mongo_date` / `mongo_date_to_carbon`, and
  aggregate `wherePeriod()`, `whereDateRange()`, `facetCompareCurrentVsPrevious()`.
- Intervention Image v3 stack: `ImageManagerFactory`, `ImageCompressor`,
  updated `BaseImage` / `ImageResize` / `ImageWatermark` (soft dependency).
- Excel bases: `Excel\ExportSheet`, `FromRepositoryExport`, `ImportSheet`,
  `ExcelColumns` (peer dependency on `maatwebsite/excel`).

### Fixed

- `Select::remove()` now rejects columns via Collection API (Illuminate has no
  `remove()`).
- `MongoDBRepositoryManager::reassociate()` / `disassociate()` no longer overwrite
  the related model argument with the parent before calling Associatable.
- Aggregate `Pipeline` now assigns stage name / parent in its constructor (stages
  were previously emitted as `$` instead of `$match` / `$group` / etc.).

### Notes

- When `MongezOctaneServiceProvider` is active, calling
  `JsonResourceManager::reset()` / `ModelTrait::resetStaticState()` from an
  application Octane flush listener is redundant. Keep those public APIs for
  tests and non-Octane scripts; register app statics via `onBootReset()` or
  `RequestScoped` instead. See `docs/octane-nid.md`.
