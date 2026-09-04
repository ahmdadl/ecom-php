<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Repository\Concerns;

use HZ\Illuminate\Mongez\Helpers\Mongez;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Opt-in dotted settings reader for repositories.
 *
 * Provides request-scoped `load()` / `getSetting('group.key')` without shipping a
 * full Settings module. Call {@see registerSettingsRequestFlush()} once from a
 * service provider so singleton repositories clear `$loadedSettings` under Octane.
 *
 * @phpstan-require-extends \HZ\Illuminate\Mongez\Repository\RepositoryManager
 */
trait HasSettings
{
    protected bool $settingsInitialized = false;

    /**
     * Request-scoped settings tree keyed by group then name.
     *
     * @var array<string, mixed>
     */
    protected array $loadedSettings = [];

    private static bool $settingsFlushRegistered = false;

    /**
     * Clear the request-scoped settings tree (required under Octane).
     */
    public function flushLoadedSettings(): void
    {
        $this->settingsInitialized = false;
        $this->loadedSettings = [];
    }

    /**
     * Register Octane-safe flush via {@see Mongez::onBootReset()}.
     *
     * Resolves this repository by {@see static::NAME} and clears loaded settings
     * on every Mongez reset. Safe to call more than once.
     */
    public static function registerSettingsRequestFlush(): void
    {
        if (static::$settingsFlushRegistered) {
            return;
        }

        static::$settingsFlushRegistered = true;

        $nameConstant = static::class . '::NAME';

        if (! defined($nameConstant)) {
            return;
        }

        /** @var string $repositoryName */
        $repositoryName = constant($nameConstant);

        Mongez::onBootReset(static function () use ($repositoryName): void {
            try {
                $repository = repo($repositoryName);

                if (method_exists($repository, 'flushLoadedSettings')) {
                    $repository->flushLoadedSettings();
                }
            } catch (Throwable) {
                // Repository may not be resolvable during early worker boot.
            }
        });
    }

    /**
     * Load the given groups into the request-scoped tree.
     *
     * With no groups and no prior full load, loads all settings once.
     */
    public function load(string ...$groups): static
    {
        if ($groups === [] && ! $this->settingsInitialized) {
            $this->hydrateLoadedSettings($this->fetchAllSettingsGrouped());

            return $this;
        }

        $missing = [];

        foreach ($groups as $group) {
            if (! array_key_exists($group, $this->loadedSettings)) {
                $missing[] = $group;
            }
        }

        if ($missing === []) {
            return $this;
        }

        foreach ($this->fetchSettingsGrouped($missing) as $group => $rows) {
            foreach ($rows as $setting) {
                Arr::set(
                    $this->loadedSettings,
                    $group . '.' . $this->settingName($setting),
                    $this->mapSettingValue($setting)
                );
            }

            if (! array_key_exists($group, $this->loadedSettings)) {
                $this->loadedSettings[$group] = [];
            }
        }

        return $this;
    }

    /**
     * Populate the request-scoped tree from grouped raw setting rows.
     *
     * @param  array<string, list<array<string, mixed>>>  $settings
     */
    public function hydrateLoadedSettings(array $settings): static
    {
        $this->settingsInitialized = true;
        $this->loadedSettings = [];

        foreach ($settings as $group => $groupSettings) {
            foreach ($groupSettings as $setting) {
                Arr::set(
                    $this->loadedSettings,
                    $group . '.' . $this->settingName($setting),
                    $this->mapSettingValue($setting)
                );
            }

            if (! array_key_exists($group, $this->loadedSettings)) {
                $this->loadedSettings[$group] = [];
            }
        }

        return $this;
    }

    /**
     * Get a dotted setting value (`group.name`).
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        [$group] = explode('.', $key, 2);
        $this->load($group);

        return Arr::get($this->loadedSettings, $key, $default);
    }

    /**
     * Get all loaded values for a group.
     *
     * @return array<string, mixed>
     */
    public function getGroup(string $group): array
    {
        $this->load($group);

        /** @var array<string, mixed> $values */
        $values = $this->loadedSettings[$group] ?? [];

        return $values;
    }

    /**
     * Get multiple groups keyed by group name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getGroups(string ...$groups): array
    {
        $data = [];

        foreach ($groups as $group) {
            $data[$group] = $this->getGroup($group);
        }

        return $data;
    }

    /**
     * Forget durable Laravel cache entries for settings groups.
     */
    public function forgetSettingsCache(?string $group = null): void
    {
        if ($group !== null) {
            Cache::forget($this->settingsCacheKey($group));

            return;
        }

        $prefix = $this->settingsCachePrefix();

        // Best-effort: forget a sentinel "all" key used by full loads.
        Cache::forget($prefix . '.all');
    }

    /**
     * Fetch settings for the given groups, optionally via durable cache.
     *
     * @param  list<string>  $groups
     * @return array<string, list<array<string, mixed>>>
     */
    protected function fetchSettingsGrouped(array $groups): array
    {
        if ($groups === []) {
            return $this->fetchAllSettingsGrouped();
        }

        $result = [];

        foreach ($groups as $group) {
            $result[$group] = $this->rememberSettingsGroup($group, function () use ($group): array {
                return $this->querySettingsGrouped([$group])[$group] ?? [];
            });
        }

        return $result;
    }

    /**
     * Fetch every settings group (one durable cache entry when enabled).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function fetchAllSettingsGrouped(): array
    {
        if (! $this->settingsCacheEnabled()) {
            return $this->querySettingsGrouped([]);
        }

        /** @var array<string, list<array<string, mixed>>> $cached */
        $cached = Cache::remember(
            $this->settingsCachePrefix() . '.all',
            $this->settingsCacheTtl(),
            fn (): array => $this->querySettingsGrouped([])
        );

        return $cached;
    }

    /**
     * Query the settings model. Override for custom storage shapes.
     *
     * Expects MODEL rows with at least `group`, `name`, and `value`.
     *
     * @param  list<string>  $groups  Empty list means all groups.
     * @return array<string, list<array<string, mixed>>>
     */
    protected function querySettingsGrouped(array $groups): array
    {
        if (! defined('static::MODEL')) {
            return [];
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $modelClass = static::MODEL;

        $query = $modelClass::query();

        if ($groups !== []) {
            $query->whereIn('group', $groups);
        }

        $grouped = [];

        foreach ($query->get() as $setting) {
            $group = (string) $setting->getAttribute('group');
            $grouped[$group][] = [
                'group' => $group,
                'name' => $setting->getAttribute('name'),
                'value' => $setting->getAttribute('value'),
                'type' => $setting->getAttribute('type'),
            ];
        }

        foreach ($groups as $group) {
            $grouped[$group] ??= [];
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $setting
     */
    protected function settingName(array $setting): string
    {
        return (string) ($setting['name'] ?? '');
    }

    /**
     * Map a raw setting row to the stored value. Override for localization / files.
     *
     * @param  array<string, mixed>  $setting
     */
    protected function mapSettingValue(array $setting): mixed
    {
        return $setting['value'] ?? null;
    }

    /**
     * @template T
     * @param  callable(): list<array<string, mixed>>  $loader
     * @return list<array<string, mixed>>
     */
    protected function rememberSettingsGroup(string $group, callable $loader): array
    {
        if (! $this->settingsCacheEnabled()) {
            return $loader();
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(
            $this->settingsCacheKey($group),
            $this->settingsCacheTtl(),
            $loader
        );

        return $rows;
    }

    protected function settingsCacheEnabled(): bool
    {
        return (bool) config('mongez.settings.cache', false);
    }

    protected function settingsCacheTtl(): int
    {
        return (int) config('mongez.settings.ttl', 3600);
    }

    protected function settingsCachePrefix(): string
    {
        return (string) config('mongez.settings.prefix', 'mongez.settings');
    }

    protected function settingsCacheKey(string $group): string
    {
        return $this->settingsCachePrefix() . '.group.' . $group;
    }
}
