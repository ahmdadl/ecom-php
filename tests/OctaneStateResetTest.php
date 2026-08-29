<?php

namespace HZ\Illuminate\Mongez\Tests;

use PHPUnit\Framework\TestCase;
use HZ\Illuminate\Mongez\Events\Events;
use HZ\Illuminate\Mongez\Helpers\Mongez;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelTrait;
use HZ\Illuminate\Mongez\Resources\JsonResourceManager;
use HZ\Illuminate\Mongez\Providers\MongezOctaneServiceProvider;

class OctaneStateResetTest extends TestCase
{
    protected function setUp(): void
    {
        $ref = new \ReflectionClass(JsonResourceManager::class);
        $ref->setStaticPropertyValue('baseDisabledKeys', []);
        $ref->setStaticPropertyValue('baseAllowedKeys', []);
        $ref->setStaticPropertyValue('disabledKeys', []);
        $ref->setStaticPropertyValue('allowedKeys', []);
        $ref->setStaticPropertyValue('subClassesCache', null);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(JsonResourceManager::class);
        $ref->setStaticPropertyValue('baseDisabledKeys', []);
        $ref->setStaticPropertyValue('baseAllowedKeys', []);
        $ref->setStaticPropertyValue('disabledKeys', []);
        $ref->setStaticPropertyValue('allowedKeys', []);
        $ref->setStaticPropertyValue('subClassesCache', null);
        parent::tearDown();
    }

    public function testJsonResourceManagerResetPreservesBootKeys(): void
    {
        JsonResourceManager::disable('boot_disabled');
        JsonResourceManager::only('boot_allowed');
        JsonResourceManager::snapshotBaseState();
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
        $events->subscribe('boot.event', 'Some\BootListener@handle');
        $events->snapshotBaseState();
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

    public function testModelClassesDiscoveryIsCachedUntilNewClassesAreDeclared(): void
    {
        $this->resetOctaneProviderStaticState();

        $provider = new MongezOctaneServiceProvider(\Mockery::mock(\Illuminate\Contracts\Foundation\Application::class));

        $this->discoverModelClasses($provider);

        $classes = $this->octaneProviderStaticProperty('modelClasses');

        $this->assertContains(ModelStub::class, $classes);
        $this->assertSame(
            count(get_declared_classes()),
            $this->octaneProviderStaticProperty('declaredClassesCount')
        );

        // a lazily declared model class is picked up on the next discovery
        $lazyClass = 'LazyOctaneStub_' . uniqid();

        eval(sprintf(
            'namespace HZ\Illuminate\Mongez\Tests; class %s { use \HZ\Illuminate\Mongez\Database\Eloquent\ModelTrait; }',
            $lazyClass
        ));

        $this->discoverModelClasses($provider);

        $this->assertContains(
            'HZ\Illuminate\Mongez\Tests\\' . $lazyClass,
            $this->octaneProviderStaticProperty('modelClasses')
        );

        $this->resetOctaneProviderStaticState();
    }

    protected function discoverModelClasses(MongezOctaneServiceProvider $provider): void
    {
        $method = new \ReflectionMethod(MongezOctaneServiceProvider::class, 'discoverModelClasses');
        $method->invoke($provider);
    }

    protected function octaneProviderStaticProperty(string $property)
    {
        return new \ReflectionClass(MongezOctaneServiceProvider::class)->getStaticProperties()[$property];
    }

    protected function resetOctaneProviderStaticState(): void
    {
        $ref = new \ReflectionClass(MongezOctaneServiceProvider::class);

        $ref->setStaticPropertyValue('modelClasses', []);
        $ref->setStaticPropertyValue('declaredClassesCount', -1);
    }

    protected function staticProperty(string $class, string $property)
    {
        return new \ReflectionClass($class)->getStaticProperties()[$property];
    }

    protected function instanceProperty(object $object, string $property)
    {
        return new \ReflectionClass($object)->getProperty($property)->getValue($object);
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
