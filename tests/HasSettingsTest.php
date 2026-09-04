<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Helpers\Mongez;
use HZ\Illuminate\Mongez\Repository\Concerns\HasSettings;

final class HasSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionClass(Mongez::class);
        $ref->setStaticPropertyValue('resetCallbacks', []);
        $ref->setStaticPropertyValue('baseResetCallbacks', []);

        config([
            'mongez.settings.cache' => false,
            'mongez.settings.ttl' => 3600,
            'mongez.settings.prefix' => 'test.settings',
        ]);
    }

    public function test_get_setting_loads_group_and_reads_dotted_key(): void
    {
        $repo = new SettingsBagStub([
            'general' => [
                ['name' => 'maintenance', 'value' => true, 'type' => 'bool'],
                ['name' => 'siteName', 'value' => 'Zamil', 'type' => 'string'],
            ],
        ]);

        $this->assertTrue($repo->getSetting('general.maintenance'));
        $this->assertSame('Zamil', $repo->getSetting('general.siteName'));
        $this->assertSame('fallback', $repo->getSetting('general.missing', 'fallback'));
    }

    public function test_load_is_idempotent_per_group(): void
    {
        $repo = new SettingsBagStub([
            'general' => [
                ['name' => 'maintenance', 'value' => false],
            ],
        ]);

        $repo->load('general');
        $repo->load('general');

        $this->assertSame(1, $repo->queryCount);
        $this->assertFalse($repo->getSetting('general.maintenance'));
    }

    public function test_flush_clears_request_scoped_tree(): void
    {
        $repo = new SettingsBagStub([
            'general' => [
                ['name' => 'maintenance', 'value' => true],
            ],
        ]);

        $repo->getSetting('general.maintenance');
        $repo->flushLoadedSettings();

        $repo->source = [
            'general' => [
                ['name' => 'maintenance', 'value' => false],
            ],
        ];

        $this->assertFalse($repo->getSetting('general.maintenance'));
        $this->assertSame(2, $repo->queryCount);
    }

    public function test_map_setting_value_hook_is_used(): void
    {
        $repo = new class([
            'general' => [
                ['name' => 'title', 'value' => ['en' => 'Hello', 'ar' => 'مرحبا'], 'type' => 'localization'],
            ],
        ]) extends SettingsBagStub {
            protected function mapSettingValue(array $setting): mixed
            {
                if (($setting['type'] ?? null) === 'localization') {
                    return $setting['value']['en'] ?? null;
                }

                return parent::mapSettingValue($setting);
            }
        };

        $this->assertSame('Hello', $repo->getSetting('general.title'));
    }

    public function test_boot_reset_clears_loaded_settings(): void
    {
        $repo = new SettingsBagStub([
            'general' => [
                ['name' => 'maintenance', 'value' => true],
            ],
        ]);

        $repo->getSetting('general.maintenance');
        $this->assertNotEmpty($repo->peekLoaded());

        Mongez::onBootReset(static function () use ($repo): void {
            $repo->flushLoadedSettings();
        });

        Mongez::reset();

        $this->assertSame([], $repo->peekLoaded());
    }

    public function test_durable_cache_remembers_group_when_enabled(): void
    {
        config([
            'mongez.settings.cache' => true,
            'mongez.settings.ttl' => 60,
            'mongez.settings.prefix' => 'test.settings',
        ]);

        $repo = new SettingsBagStub([
            'general' => [
                ['name' => 'maintenance', 'value' => true],
            ],
        ]);

        $repo->getSetting('general.maintenance');
        $repo->flushLoadedSettings();
        $repo->source = [
            'general' => [
                ['name' => 'maintenance', 'value' => false],
            ],
        ];

        $this->assertTrue($repo->getSetting('general.maintenance'));
        $this->assertSame(1, $repo->queryCount);

        $repo->forgetSettingsCache('general');
        $repo->flushLoadedSettings();

        $this->assertFalse($repo->getSetting('general.maintenance'));
    }

    public function test_get_group_and_get_groups(): void
    {
        $repo = new SettingsBagStub([
            'general' => [
                ['name' => 'maintenance', 'value' => true],
            ],
            'shipping' => [
                ['name' => 'fee', 'value' => 10],
            ],
        ]);

        $this->assertSame(['maintenance' => true], $repo->getGroup('general'));
        $this->assertSame([
            'general' => ['maintenance' => true],
            'shipping' => ['fee' => 10],
        ], $repo->getGroups('general', 'shipping'));
    }
}

/**
 * Plain stub using HasSettings without a full RepositoryManager boot.
 */
class SettingsBagStub
{
    use HasSettings;

    public const NAME = 'settings_stub';

    /** @var array<string, list<array<string, mixed>>> */
    public array $source;

    public int $queryCount = 0;

    /**
     * @param  array<string, list<array<string, mixed>>>  $source
     */
    public function __construct(array $source = [])
    {
        $this->source = $source;
    }

    /**
     * @return array<string, mixed>
     */
    public function peekLoaded(): array
    {
        return $this->loadedSettings;
    }

    /**
     * @param  list<string>  $groups
     * @return array<string, list<array<string, mixed>>>
     */
    protected function querySettingsGrouped(array $groups): array
    {
        $this->queryCount++;

        if ($groups === []) {
            return $this->source;
        }

        $result = [];

        foreach ($groups as $group) {
            $result[$group] = $this->source[$group] ?? [];
        }

        return $result;
    }
}
