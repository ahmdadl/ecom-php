# Public Archive

## Requirements

- PHP: `^8.4 || ^8.5`
- Laravel: `^11.0 || ^12.0 || ^13.0`
- MongoDB Driver: `mongodb/laravel-mongodb v5+`

## Laravel Octane support

The package is safe to run under [Laravel Octane](https://octane.laravel.com)
with Laravel `^11.0 || ^12.0 || ^13.0` and `mongodb/laravel-mongodb` v5+.

MongoDB models expose the numeric application identity as `nid`; MongoDB's
native `_id` remains available as the driver identity. See
[the Octane and nid guide](docs/octane-nid.md) for migration, queue, and
deployment instructions.

Embedded document helpers and list filter sugar (`patchEmbedded`,
`embeddedNid`, `localizedLike`) are covered in
[embeds and filters](docs/embeds-and-filters.md).

Optional settings reads, aggregate pagination/hydration, and Octane multi-request
test helpers are covered in
[settings and aggregate polish](docs/settings-and-aggregate-polish.md).

For apps still reading integer identity as `$model->id`, enable the temporary
compat shim `mongez.mongodb.id_aliases_nid` and gate renames with
`php artisan mongez:audit-nid` (details in the same guide).

Install Octane in your application:

```bash
composer require laravel/octane
```

When Octane is present, `MongezOctaneServiceProvider` is registered automatically
and keeps the package state isolated between requests by resetting it on every
`RequestReceived` event:

- `Mongez::reset()` clears the request locale and cached storage file content.
- `JsonResourceManager::reset()` discards per-request `disable()`/`only()` keys
  while preserving the keys registered at boot time.
- `Events::reset()` discards per-request listeners while preserving the listeners
  that were registered during boot (config events and any `events()->subscribe()`
  calls made in service providers).
- Repository resource and model static state is reset as well.

A defensive `RequestTerminated` listener also rolls back any database transaction
that was left open during a request.

**Do not** re-reset Mongez internals from an application Octane listener when the
package provider is active — that work is already done. Register app-owned
statics with `Mongez::onBootReset()` or the `HZ\Illuminate\Mongez\Support\RequestScoped`
trait instead (details in [docs/octane-nid.md](docs/octane-nid.md)).

Notes for Octane users:

- Declare Mongez events via `config/mongez.php` (`events` key) rather than calling
  `events()->subscribe()` at runtime, so they are re-applied consistently.
- `JsonResourceManager::disable()` / `JsonResourceManager::only()` called at boot
  are preserved; the same calls made during a request are isolated to that request.
- Call `Application::registerRequestScopedDefaults()` (or similar) from a service
  provider `boot()` method so app statics clear on every `Mongez::reset()`.

