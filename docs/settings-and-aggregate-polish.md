# Settings concern and aggregate polish

Opt-in helpers for dotted settings reads, aggregation pagination/hydration, and
Octane multi-request tests (Phase 7).

## Settings (`HasSettings`)

Use the trait on a settings repository. It owns a **request-scoped** tree
(`$loadedSettings`) plus optional durable Laravel cache for groups.

```php
use HZ\Illuminate\Mongez\Repository\Concerns\HasSettings;

class SettingsRepository extends MongoDBRepositoryManager
{
    use HasSettings;

    // MODEL must expose group / name / value (/ type) columns.
}

// AppServiceProvider::boot() — Octane-safe flush for singleton repos
SettingsRepository::registerSettingsRequestFlush();
```

```php
settingsRepo()->getSetting('general.maintenance', false);
settingsRepo()->getGroup('shipping');
settingsRepo()->load('general', 'shipping');

// After save: clear durable cache + request tree
settingsRepo()->forgetSettingsCache(); // or forgetSettingsCache('general')
settingsRepo()->flushLoadedSettings();
```

Override `mapSettingValue()` for localization / file wrapping. Override
`querySettingsGrouped()` for a custom storage shape (Redis document cache, etc.).

Config (`config/mongez.php`):

| Key | Default | Role |
|-----|---------|------|
| `mongez.settings.cache` | `false` | Durable group cache via Laravel `Cache` |
| `mongez.settings.ttl` | `3600` | Cache TTL seconds |
| `mongez.settings.prefix` | `mongez.settings` | Cache key prefix |

This is **not** Zamil’s Settings module — only the load/get/flush pattern.

## Aggregate polish

```php
$aggregate = repo('orders')->aggregate()
    ->where('status', 'completed');

// Same paginationInfo shape as repository list endpoints
$result = $aggregate->paginate(25, page: 1);
// ['data' => [...], 'paginationInfo' => [...]]

$models = $aggregate->hydrate(); // or hydrate(Order::class)
$resources = $aggregate->wrapAs(OrderResource::class);

$aggregate->chunk(100, function (array $rows, int $page): void {
    // export / process
});

foreach ($aggregate->cursor(100) as $rows) {
    // ...
}
```

`toPaginationPipelines($perPage, $page)` returns the pipeline (including `$facet`)
without executing — useful in tests.

## Octane multi-request test helper

```php
use HZ\Illuminate\Mongez\Testing\Traits\SimulatesOctaneRequests;

class OctaneIsolationTest extends TestCase
{
    use SimulatesOctaneRequests;

    public function test_locale_does_not_leak(): void
    {
        [$first, $second] = $this->octaneSequence(
            fn () => $this->getWithLocale('/api/home', 'ar'),
            fn () => $this->get('/api/home'), // no locale header
        );

        $first->assertOk();
        $second->assertOk();
    }
}
```

`simulateOctaneTurn()` / `octaneSequence()` call `Mongez::reset()` (and
`JsonResourceManager::reset()` when available) between turns so feature tests
mirror Octane request boundaries without spinning a worker.
