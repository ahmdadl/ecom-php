<?php

namespace HZ\Illuminate\Mongez\Tests;

use PHPUnit\Framework\TestCase;
use HZ\Illuminate\Mongez\Events\Events;
use HZ\Illuminate\Mongez\Helpers\Mongez;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelTrait;
use HZ\Illuminate\Mongez\Resources\JsonResourceManager;

class OctaneStateResetTest extends TestCase
{
    protected function setUp(): void
    {
        // make sure the static state is clean before each test
        JsonResourceManager::reset();
    }

    public function testJsonResourceManagerResetPreservesBootKeys(): void
    {
        // boot-time registration
        JsonResourceManager::disable('boot_disabled');
        JsonResourceManager::only('boot_allowed');

        JsonResourceManager::snapshotBaseState();

        // per-request registration
        JsonResourceManager::disable('request_disabled');

        JsonResourceManager::reset();

        $this->assertSame(
            ['boot_disabled'],
            $this->staticProperty(JsonResourceManager::class, 'disabledKeys')
        );

        $this->assertSame(
            ['boot_allowed'],
            $this->staticProperty(JsonResourceManager::class, 'allowedKeys')
        );
    }

    public function testEventsResetPreservesBootListeners(): void
    {
        $events = new Events();

        // boot-time registration
        $events->subscribe('boot.event', 'Some\BootListener@handle');

        $events->snapshotBaseState();

        // per-request registration
        $events->subscribe('request.event', 'Some\RequestListener@handle');

        $events->reset();

        $this->assertSame(
            ['Some\BootListener@handle'],
            $this->instanceProperty($events, 'eventsList')['boot.event']
        );

        $this->assertArrayNotHasKey('request.event', $this->instanceProperty($events, 'eventsList'));
    }

    public function testMongezResetClearsRequestLocale(): void
    {
        Mongez::setRequestLocaleCode('ar');

        Mongez::reset();

        $this->assertFalse(Mongez::requestHasLocaleCode());
    }

    public function testModelStaticStateReset(): void
    {
        ModelStub::setDisableUpdateTime(true);

        $this->assertTrue(ModelStub::$disableUpdateTime);

        ModelStub::resetStaticState();

        $this->assertFalse(ModelStub::$disableUpdateTime);
    }

    protected function staticProperty(string $class, string $property)
    {
        return (new \ReflectionClass($class))->getStaticPropertyValue($property);
    }

    protected function instanceProperty(object $object, string $property)
    {
        return (new \ReflectionClass($object))->getProperty($property)->getValue($object);
    }
}

class ModelStub
{
    use ModelTrait;

    public static function setDisableUpdateTime(bool $value): void
    {
        static::$disableUpdateTime = $value;
    }
}
