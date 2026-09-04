# Changelog

## Unreleased

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

### Fixed

- `Select::remove()` now rejects columns via Collection API (Illuminate has no
  `remove()`).
- `MongoDBRepositoryManager::reassociate()` / `disassociate()` no longer overwrite
  the related model argument with the parent before calling Associatable.

### Notes

- When `MongezOctaneServiceProvider` is active, calling
  `JsonResourceManager::reset()` / `ModelTrait::resetStaticState()` from an
  application Octane flush listener is redundant. Keep those public APIs for
  tests and non-Octane scripts; register app statics via `onBootReset()` or
  `RequestScoped` instead. See `docs/octane-nid.md`.
