# Plan: Mongez features for api-zamil-octane

**Status:** In progress (Phase 2 package work on `feat/nid-compat-audit`)  
**Date:** 2026-09-04  
**Package:** `hassanzohdy/mongez`  
**Primary consumer:** `../api-zamil-octane` (Laravel 12, Octane/FrankenPHP, MongoDB v5, `nid`)

## Goal

Add package features that reduce churn, risk, and duplicated infrastructure in
`api-zamil-octane`, without pulling Zamil-specific domain (cart, couriers,
installation capacity, Payfort/Tamara/Tabby, ZITG) into mongez.

## Related docs

| Doc | Role |
|-----|------|
| `OCTANE_MONGODB_V5_PLAN.md` | Package Octane + `nid` foundation (done) |
| `docs/caching-plan.md` | Model/repository cache (done) |
| `docs/octane-nid.md` | Consumer Octane / `nid` ops guide |
| `../api-zamil-octane/OCTANE_FIX_PLAN.md` | App readiness phases; Phase 3 identity strategy |
| `../api-zamil-octane/MONGEZ_V5_COMPAT_VALIDATION_REPORT.md` | GAP catalog (`id` / resources / `sharedInfo`) |
| `../api-zamil-octane/IMAGE_COMPRESSION_PLAN.md` | Uploads compression (app-side today) |

## Design rules

1. **Package patterns, not domain** — helpers, base classes, CLI, config flags.
2. **Opt-in and backward compatible** — default behavior stays current; new APIs or config unlock migration aids.
3. **Octane-safe by default** — no new request-scoped static leaks; register app cleanup via `Mongez::onReset()`.
4. **Ship package → adopt in API** — each mongez phase ends with a clear api-zamil-octane adoption checklist.
5. **Do not absorb** payment gateways, courier integrations, cart logic, or installation-team rules.

## Current consumer pain (summary)

| Area | Evidence in api-zamil-octane |
|------|------------------------------|
| Identity cutover | ~900+ `$model->id` sites; 81 resources with `'id'` in `INTEGER_DATA`; 72 models with `'id'` in `SHARED_INFO` |
| Duplicate Octane flush | `FlushMongezStaticState` re-resets Mongez; app statics (`Application::$currentApplicationType`) not on `Mongez::onReset()` |
| Giant report repos | Orders ~3.2k LOC, WorkOrders ~2.5k; heavy `Model::raw(...aggregate...)` + period math |
| Dates / periods | `PeriodDateCalculator`, `toMongoDate` / `fromMongoDate` live in app utils |
| Images | Mongez still Intervention v2 (`Image::make`); compression plan is app-only |
| Excel | Many near-identical export/import classes across modules |
| Embeds | Heavy `associate` / `reassociate` / `sharedInfo` without partial-patch helpers |

---

## Phase overview

| Phase | Theme | Effort (package) | Unblocks for API |
|-------|--------|------------------|------------------|
| **0** | Prerequisites / non-goals | — | Align expectations |
| **1** | Identity safety (`id` shim + audit CLI) | S–M | Mongo v5 / `nid` cutover |
| **2** | Octane app-state ergonomics | S | Drop duplicate Mongez flush; register app statics |
| **3** | Reporting primitives (periods, dates, aggregate sugar) | M | Shrink Orders / WO / traffic reports |
| **4** | Image stack (Intervention v3 + compression) | M | Uploads + mongez image services |
| **5** | Excel base classes | M | Standardize admin import/export |
| **6** | Embedded document tooling | M–L | Safer denorm updates at order/WO scale |
| **7** | Optional settings concern + polish | S–M | Cached dotted settings; filter sugar |

Suggested sequence is dependency-ordered: **1 → 2 → 3**, then **4 / 5 / 6** can run in parallel; **7** last.

---

## Phase 0 — Prerequisites and non-goals

### Do first

- [x] Confirm api-zamil-octane identity strategy preference:
  - **Hybrid (recommended):** temporary shim in mongez + incremental app rename to `nid` ✅ approved 2026-09-04
  - **Strict:** no shim; app finishes full rename first
- [x] Keep consuming mongez via path repo (`composer.json` → `../mongez`) until phases land on a tagged release.
- [x] Treat this plan as additive to existing Octane/`nid`/cache work — do not regress those.

### Explicitly out of scope

- Zamil cart / checkout business rules  
- Courier / ZITG / Payfort / Tamara / Tabby implementations  
- Installation team capacity / holiday domain modules  
- Replacing FrankenPHP / Docker ops docs (stay in the API repo)

---

## Phase 1 — Identity safety (`nid` cutover aids)

**Why first:** Silent ObjectId→int corruption is the highest blast-radius risk for the API under MongoDB v5.

### Package work

#### 1.1 Optional `id` → `nid` accessor shim

- [x] Config: `mongez.mongodb.id_aliases_nid` (default `false` for new apps; API sets `true` during migration).
- [x] When enabled on Mongo models:
  - `getIdAttribute()` returns integer `nid` (not ObjectId string).
  - Document clearly that `$model->id` is a **compat alias**, not the driver key.
- [x] Resource path (optional sub-flag or same flag):
  - Reading `'id'` from `INTEGER_DATA` / value helpers resolves `nid`.
- [x] `sharedInfo()` path:
  - When `SHARED_INFO` still lists `'id'`, emit `id` as the integer `nid` while the shim is on.
- [x] Unit tests: shim on/off; resource integer casting; `sharedInfo` identity keys.
- [x] Docs in `docs/octane-nid.md`: enable → migrate app → disable → remove.

#### 1.2 Audit CLI: `php artisan mongez:audit-nid`

- [x] Scan app `app/` (configurable path) and report:
  - `$model->id` / `->id` property reads (heuristic)
  - `where('id')` / `orderBy('id')` / `['id']` query fragments
  - `'id'` inside `INTEGER_DATA`, `STRING_DATA`, `SHARED_INFO`
  - Dotted embedded `.id` paths in PHP strings where detectable
- [x] Exit codes: `0` clean, `1` findings (CI-friendly).
- [x] Optional `--json` for tooling.
- [x] README / docs: recommended CI gate during migration.

### API adoption checklist

- [ ] Set `mongez.mongodb.id_aliases_nid=true` in staging (add key to published `config/mongez.php` or `MONGEZ_MONGODB_ID_ALIASES_NID=true`).
- [x] Run `mongez:audit-nid`; triage counts vs `MONGEZ_V5_COMPAT_VALIDATION_REPORT.md`.
  - **2026-09-04 scan of current `api-zamil-octane/app`:** **0 findings** (checkout already largely on `nid`; report’s ~900 / `INTEGER_DATA` / `SHARED_INFO` counts look stale).
- [ ] Bulk-rename resources/models (`INTEGER_DATA` / `SHARED_INFO` → `nid`) — mostly done on current checkout; re-check after path-repo bump.
- [ ] Codemod high-traffic modules (Orders, Products, Customers, WorkOrders) to `$model->nid`.
- [ ] After audit is clean (or accepted exceptions), set shim `false` and re-soak.

### Done when

- Shim toggles without breaking Octane state reset tests.
- Audit CLI produces actionable output on a checkout of api-zamil-octane.
- API can run FrankenPHP soak with shim on without identity garbage in JSON.

---

## Phase 2 — Octane app-state ergonomics

**Why:** Mongez already resets itself; the API still duplicates that and keeps app statics on a custom listener.

### Package work

#### 2.1 Document and harden `Mongez::onReset()`

- [x] README / `docs/octane-nid.md`: “register application static state with `Mongez::onReset()`”.
- [x] Ensure boot-time vs request-time callback split stays clear (`baseResetCallbacks` vs per-request) — added `onBootReset()`.
- [x] Add a small example test showing a consumer callback firing on `reset()`.

#### 2.2 `RequestScoped` / `OctaneState` helper trait

- [x] Trait for classes holding request-scoped static properties (pattern of `Application::$currentApplicationType`).
- [x] API: `declare static fields + registerDefaults()`; auto-subscribe to `Mongez::onReset()` once at boot.
- [x] Optional: `Mongez::forgetRequestState()` alias already covered by `reset()`.

#### 2.3 Deprecation note for consumer-side Mongez flushes

- [x] Changelog: calling `JsonResourceManager::reset()` / `ModelTrait::resetStaticState()` from the app is redundant when `MongezOctaneServiceProvider` is active.
- [x] Keep public `reset()` APIs — do not break apps that still call them.

### API adoption checklist

- [ ] Register `Application::$currentApplicationType` (and similar) via `Mongez::onReset()`.
- [ ] Slim `FlushMongezStaticState` to **app-only** cleanup (e.g. `PdfTempDir`, tagged cache on `WorkerStarting`).
- [ ] Remove defensive `class_exists` Mongez resets once package Octane provider is confirmed in production compose.
- [ ] Extend/adjust `tests/Feature/OctaneStateResetTest.php` for app-type isolation.

### Done when

- [x] Package docs show the one recommended pattern.
- [ ] API listener no longer re-implements Mongez internal resets.
- [ ] Octane feature tests still pass with one worker / multi-request locale + app-type headers.

---

## Phase 3 — Reporting primitives

**Why:** Orders, WorkOrders, and Home traffic reports reimplement period math, Mongo dates, and aggregation date ranges.

### Package work

#### 3.1 Period calculator

- [ ] Port/generalize `App\Shared\PeriodDateCalculator` into e.g. `HZ\Illuminate\Mongez\Support\PeriodDateCalculator`.
- [ ] Support: daily, weekly, monthly, quarter, ytd (+ previous period + label helper).
- [ ] Configurable week start (API uses Sunday).
- [ ] Unit tests for boundaries and “last year” mode.

#### 3.2 Mongo date helpers

- [ ] Move/generalize `toMongoDate` / `fromMongoDate` / `mongoDateToCarbon` into mongez helpers (or `Helpers/functions.php` with clear namespacing).
- [ ] Keep UTC / app-timezone behavior documented.
- [ ] Deprecate nothing in the API until wrappers can re-export or call package helpers.

#### 3.3 Aggregate sugar

- [ ] On `Aggregate` / `Pipeline`:
  - `wherePeriod($column, PeriodDateCalculator|array $fromTo)`
  - `whereDateRange($column, $from = null, $to = null)` (same semantics as TrafficReportsService)
  - helpers for count/sum grouped by day/week/month (extend existing `groupByDay` etc. with period filter)
- [ ] Optional: `facetCompareCurrentVsPrevious(PeriodDateCalculator $period, callable $build)` for report “Vs last period” patterns.
- [ ] Discourage new `Model::raw(fn ($col) => $col->aggregate(...))` in docs when the fluent API covers the case.
- [ ] Unit tests with mocked pipelines / fixture documents where feasible.

### API adoption checklist

- [ ] Replace `App\Shared\PeriodDateCalculator` with package class (thin alias optional).
- [ ] Point General utils Mongo date helpers at mongez implementations.
- [ ] Refactor `TrafficReportsService` date filters to package helpers first (smallest win).
- [ ] Incrementally replace hottest Orders/WorkOrders raw aggregates that are pure date+count/sum.

### Done when

- Period + date helpers have package tests.
- At least one API report path uses package period/aggregate helpers end-to-end.
- No change required to Zamil domain rules.

---

## Phase 4 — Image stack (Intervention v3 + compression)

**Why:** Package image services are on Intervention v2; API uploads plan already specifies v3 + quality-aware encoding.

### Package work

#### 4.1 Migrate `Services/Images/*` to Intervention v3

- [ ] Replace `Image::make()` with `ImageManager` + driver (Imagick preferred, GD fallback).
- [ ] Update `BaseImage`, `ImageResize`, `ImageWatermark`.
- [ ] Suggest `intervention/image` v3 in `composer.json` `suggest` or soft dependency docs (avoid hard require if optional).
- [ ] Feature/unit tests with a tiny fixture image.

#### 4.2 Compression helper

- [ ] Add package helper inspired by API `IMAGE_COMPRESSION_PLAN.md`:
  - `isCompressibleImage($extension)`
  - `compressImage(Image $image, string $extension): EncodedImage` (JPEG/WebP/AVIF quality, progressive JPEG; PNG policy documented)
- [ ] Config: default qualities under `mongez.images.*`.

### API adoption checklist

- [ ] Point Uploads utils/controller at package compression + manager binding.
- [ ] Set `config/image.php` driver preference (Imagick) as already planned.
- [ ] Verify admin + site upload paths and `UploadsController::show()` derivatives.

### Done when

- Mongez image classes run on Intervention v3.
- API can delete duplicated compression logic or reduce it to thin wrappers.
- Smoke: upload + watermark + cached derivative size regresses vs uncompressed defaults.

---

## Phase 5 — Excel export / import base

**Why:** Many modules ship near-copy Excel classes; localized columns repeat `get_localized_value` patterns.

### Package work

#### 5.1 Base export

- [ ] Abstract `ExportSheet` / `FromRepositoryExport` (Maatwebsite Excel optional — document peer dependency).
- [ ] Helpers: map models → rows, localized column (`localizedColumn($value, 'en'|'ar'|locale)`), date columns via mongez date helpers.
- [ ] Consistent heading + chunking hooks for large collections.

#### 5.2 Base import

- [ ] Abstract `ImportSheet` with row validation hooks, nid lookups, and error collection shape compatible with API admin UX.
- [ ] Example stub in docs / Engez generator optional later.

### API adoption checklist

- [ ] Pilot on 1–2 simple exporters (Cities, Leads) then Products/Orders.
- [ ] Keep domain-specific column maps in the app; inherit only plumbing.

### Done when

- Package bases exist with tests (fake rows / no real XLSX required if possible).
- At least two API Excel classes migrate without behavior change.

---

## Phase 6 — Embedded document tooling

**Why:** Orders/WOs denormalize heavily via `associate` / `reassociate` / `sharedInfo`; partial updates and cache invalidation are manual and error-prone.

### Package work

#### 6.1 Partial embedded patch

- [ ] `Model::patchEmbedded(string $path, mixed $matchNidOrCriteria, array $data)` (or repository concern).
- [ ] Update matching element(s) in an embedded array without rewriting unrelated siblings when safe.
- [ ] Clear docs on Mongo positional / `arrayFilters` usage and limitations.

#### 6.2 Shared-info / related sync polish

- [ ] Helpers to refresh one embedded `sharedInfo()` snapshot by related `nid`.
- [ ] Tie into existing related-model queue mode (`mongez.queue.relatedModels`) with examples for order-scale graphs.
- [ ] Ensure cache invalidation hooks (`invalidateCacheByIds`) are documented for query-builder embed writes.

#### 6.3 Filter sugar for embeds / localized fields

- [ ] Filter map entries for embedded nid: e.g. `embeddedNid` → `customer.nid`.
- [ ] Localized text search: filter on `name` array by `text` + locale (or `name.text` with locale scope).
- [ ] Tests in `MongoDBFilter` / FilterManager.

### API adoption checklist

- [ ] Replace selected hot `reassociate` full-array rewrites with `patchEmbedded` where safe (order items / WO statuses).
- [ ] Use new filters in admin list endpoints that currently hand-roll embedded nid queries.
- [ ] Confirm related-model queue + cache invalidation after mass embed updates.

### Done when

- Patch helper covered by unit tests (array document fixtures).
- One Orders or WorkOrders write path uses it in staging without embed corruption.
- New filters available to Engez-generated repositories.

---

## Phase 7 — Optional settings concern and polish

**Why:** Dotted settings (`getSetting('general.maintenance')`) and a few leftover DX gaps appear in every Mongez e-commerce app; keep this thin and optional.

### Package work

#### 7.1 Settings concern (optional)

- [ ] Trait or small service: load groups, `get($dottedKey, $default)`, cache with model-cache invalidation on save.
- [ ] Do **not** ship Zamil’s full Settings module — only the storage/read pattern.
- [ ] Octane-safe: no static group cache without `onReset` or request/container scoping.

#### 7.2 Aggregate / list polish (if still needed after Phase 3)

- [ ] Pagination helpers for aggregation results.
- [ ] “Hydrate pipeline results as models/resources” optional helper.
- [ ] Cursor/chunk helpers for large admin exports (ties to Phase 5).

#### 7.3 Testing helpers

- [ ] Octane multi-request test helper (locale + headers) reusable by consumers.
- [ ] Resource assertion helpers around `nid` in `TestResponse`.

### API adoption checklist

- [ ] Optionally wrap `SettingsRepository` getters with package concern.
- [ ] Use Octane test helper in API feature suite.
- [ ] Drop any remaining obsolete shims once Phase 1 audit is clean.

### Done when

- Settings concern is opt-in and documented.
- API Octane tests share package helper without copying boilerplate.

---

## Cross-cutting work (every phase)

| Item | Requirement |
|------|-------------|
| Tests | PHPUnit for new APIs; keep `OctaneStateResetTest` green |
| Static analysis | No new PHPStan level regressions in touched files |
| Docs | Update README and/or `docs/octane-nid.md` when consumer-facing |
| Changelog | Note config flags, deprecations, and adoption steps |
| API validation | Path-repo bump + targeted Feature/Octane tests in api-zamil-octane |

---

## Suggested milestones

| Milestone | Phases | Outcome for api-zamil-octane |
|-----------|--------|------------------------------|
| **M1 — Safe cutover** | 1 + 2 | Shim + audit CLI live; Octane app state registered correctly |
| **M2 — Report DX** | 3 | Period/date/aggregate helpers used in traffic + one heavy report |
| **M3 — Media & admin IO** | 4 + 5 | v3 images + Excel bases adopted on pilot modules |
| **M4 — Scale embeds** | 6 + 7 | Safer embed patches; optional settings; polish |

---

## Effort sketch (package only)

| Phase | Rough size | Notes |
|-------|------------|-------|
| 1 | 2–4 days | Highest ROI; unblocks identity migration |
| 2 | 1–2 days | Mostly docs + small trait |
| 3 | 3–5 days | Period + dates + aggregate sugar |
| 4 | 2–4 days | Depends on Intervention v3 edge cases |
| 5 | 2–3 days | Peer dependency on Maatwebsite |
| 6 | 4–7 days | Hardest correctness surface |
| 7 | 2–3 days | Optional; can slip |

API adoption effort is **larger** than package effort for Phases 1, 3, 5, and 6 (many call sites).

---

## Risks

| Risk | Mitigation |
|------|------------|
| Shim hides incomplete renames forever | Audit CLI + documented “shim off” gate before production strict mode |
| Aggregate sugar does not cover complex `$facet` reports | Keep raw escape hatch; migrate only repetitive patterns |
| Image v3 breaks watermark/resize callers | Compatibility wrappers + fixture tests; API smoke on upload |
| `patchEmbedded` mis-updates arrays | Strict match on `nid`; integration tests with order-item shaped fixtures |
| Scope creep into Zamil domain | Reject PRs that hardcode order/courier/payment rules in mongez |

---

## Immediate next actions

1. ~~Approve Phase 1 strategy: **hybrid shim default for api-zamil-octane**.~~ ✅
2. ~~Implement Phase 1.1 + 1.2 on `feat/nid-compat-audit`.~~ ✅ (package side)
3. Wire api-zamil-octane config (`MONGEZ_MONGODB_ID_ALIASES_NID` / published mongez.php) if any leftover `id` surfaces appear after path-repo bump; audit already clean on current checkout.
4. ~~Start **Phase 2** (Octane app-state ergonomics).~~ ✅ (package side)
5. Adopt Phase 2 in api-zamil-octane: `RequestScoped` on `Application`, slim `FlushMongezStaticState`.
6. Start **Phase 3** (reporting primitives) when ready.

---

## Tracking

Copy into issues/PRs as needed:

- [x] Phase 1 — Identity safety (package done; API config soak optional)  
- [x] Phase 2 — Octane app-state (package done; API adoption pending)  
- [ ] Phase 3 — Reporting primitives  
- [ ] Phase 4 — Images v3  
- [ ] Phase 5 — Excel bases  
- [ ] Phase 6 — Embedded tooling  
- [ ] Phase 7 — Settings + polish  
