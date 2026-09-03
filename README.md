# Public Archive

## Requirements

- PHP: `^8.4 || ^8.5`
- Laravel: `^11.0 || ^12.0 || ^13.0`
- MongoDB Driver: `mongodb/laravel-mongodb v5+`

## Laravel Octane support

The package is safe to run under [Laravel Octane](https://octane.laravel.com)
with Laravel `^11.0 || ^12.0 || ^13.0` and `mongodb/laravel-mongodb` v5+.

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

Notes for Octane users:

- Declare Mongez events via `config/mongez.php` (`events` key) rather than calling
  `events()->subscribe()` at runtime, so they are re-applied consistently.
- `JsonResourceManager::disable()` / `JsonResourceManager::only()` called at boot
  are preserved; the same calls made during a request are isolated to that request.

