<?php

namespace HZ\Illuminate\Mongez\Tests;

use PHPUnit\Framework\TestCase;
use HZ\Illuminate\Mongez\Events\Events;
use HZ\Illuminate\Mongez\Helpers\Mongez;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelTrait;
use HZ\Illuminate\Mongez\Database\Eloquent\ModelEvents;
use HZ\Illuminate\Mongez\Resources\JsonResourceManager;
use HZ\Illuminate\Mongez\Providers\MongezOctaneServiceProvider;

class OctaneStateResetTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearMongezResetCallbacks();

        $ref = new \ReflectionClass(JsonResourceManager::class);
        $ref->setStaticPropertyValue('baseDisabledKeys', []);
        $ref->setStaticPropertyValue('baseAllowedKeys', []);
        $ref->setStaticPropertyValue('disabledKeys', []);
        $ref->setStaticPropertyValue('allowedKeys', []);
        $ref->setStaticPropertyValue('subClassesCache', null);
    }

    protected function tearDown(): void
    {
        $this->clearMongezResetCallbacks();

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

    public function testMongezResetRunsRegisteredCallbacks(): void
    {
        $calls = 0;
        Mongez::onReset(static function () use (&$calls): void {
            $calls++;
        });

        Mongez::reset();

        $this->assertSame(1, $calls);
    }

    public function testMongezBootResetCallbackSurvivesMultipleResets(): void
    {
        $calls = 0;
        Mongez::onBootReset(static function () use (&$calls): void {
            $calls++;
        });

        Mongez::reset();
        Mongez::reset();

        $this->assertSame(2, $calls);
    }

    public function testMongezRequestTimeOnResetCallbackRunsOnceThenDrops(): void
    {
        $bootCalls = 0;
        $requestCalls = 0;

        Mongez::onBootReset(static function () use (&$bootCalls): void {
            $bootCalls++;
        });
        Mongez::snapshotBaseState();

        Mongez::onReset(static function () use (&$requestCalls): void {
            $requestCalls++;
        });

        Mongez::reset();
        Mongez::reset();

        $this->assertSame(2, $bootCalls);
        $this->assertSame(1, $requestCalls);
    }

    public function testForgetRequestStateAliasesReset(): void
    {
        Mongez::setRequestLocaleCode('ar');
        Mongez::forgetRequestState();

        $this->assertFalse(Mongez::requestHasLocaleCode());
    }

    public function testModelStaticStateReset(): void
    {
        ModelStub::setDisableUpdateTime(true);
        $this->assertTrue(ModelStub::$disableUpdateTime);
        ModelStub::resetStaticState();
        $this->assertFalse(ModelStub::$disableUpdateTime);
    }

    public function testModelEventsResetClearsPerClassStaticState(): void
    {
        $this->resetOctaneProviderStaticState();

        /** @var \Illuminate\Contracts\Foundation\Application $app */
        $app = \Mockery::mock(\Illuminate\Contracts\Foundation\Application::class);

        $provider = new MongezOctaneServiceProvider($app);

        ModelStubWithEvents::$modelClass = 'TestModel';
        ModelStubWithEvents::$modelOptions = [['option' => 'value']];

        $this->discoverModelClasses($provider);
        $this->resetModelsState($provider);

        $this->assertSame('', ModelStubWithEvents::$modelClass);
        $this->assertEmpty(ModelStubWithEvents::$modelOptions);
    }

    protected function resetModelsState(MongezOctaneServiceProvider $provider): void
    {
        $method = new \ReflectionMethod(MongezOctaneServiceProvider::class, 'resetModelsState');
        $method->invoke($provider);
    }

    public function testModelClassesDiscoveryIsCachedUntilNewClassesAreDeclared(): void
    {
        $this->resetOctaneProviderStaticState();

        /** @var \Illuminate\Contracts\Foundation\Application $app */
        $app = \Mockery::mock(\Illuminate\Contracts\Foundation\Application::class);

        $provider = new MongezOctaneServiceProvider($app);

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

    protected function octaneProviderStaticProperty(string $property): mixed
    {
        return new \ReflectionClass(MongezOctaneServiceProvider::class)->getStaticProperties()[$property];
    }

    protected function resetOctaneProviderStaticState(): void
    {
        $ref = new \ReflectionClass(MongezOctaneServiceProvider::class);

        $ref->setStaticPropertyValue('modelClasses', []);
        $ref->setStaticPropertyValue('declaredClassesCount', -1);
    }

    protected function clearMongezResetCallbacks(): void
    {
        $ref = new \ReflectionClass(Mongez::class);
        $ref->setStaticPropertyValue('resetCallbacks', []);
        $ref->setStaticPropertyValue('baseResetCallbacks', []);
    }

    /**
     * @param class-string $class
     */
    protected function staticProperty(string $class, string $property): mixed
    {
        return new \ReflectionClass($class)->getStaticProperties()[$property];
    }

    protected function instanceProperty(object $object, string $property): mixed
    {
        return new \ReflectionClass($object)->getProperty($property)->getValue($object);
    }
}

class ModelStub extends \Illuminate\Database\Eloquent\Model
{
    public const CREATED_BY = 'created_by';
    public const UPDATED_BY = 'updated_by';
    public const DELETED_BY = 'deleted_by';

    use ModelTrait;

    public static function setDisableUpdateTime(bool $value): void
    {
        static::$disableUpdateTime = $value;
    }
}

class ModelStubWithEvents extends \Illuminate\Database\Eloquent\Model
{
    use ModelTrait, ModelEvents;

    public const CREATED_BY = 'created_by';
    public const UPDATED_BY = 'updated_by';
    public const DELETED_BY = 'deleted_by';

    public const ON_MODEL_CREATE = [];
    public const ON_MODEL_CREATE_PUSH = [];
    public const ON_MODEL_UPDATE = [];
    public const ON_MODEL_UPDATE_ARRAY = [];
    public const ON_MODEL_DELETE_UNSET = [];
    public const ON_MODEL_DELETE_PULL = [];
    public const ON_MODEL_DELETE = [];
    public const MODEL_LINKS = [];
    public const MODEL_LINKS_ARRAY = [];
    public const MODEL_LINKS_DELETE = [];
}

class ModelStubWithEventsQueued extends ModelStubWithEvents
{
    public const RELATED_MODELS_QUEUE_MODE = true;
}

class ModelStubWithEventsQueueString extends ModelStubWithEvents
{
    public const RELATED_MODELS_QUEUE_MODE = 'queue';
}
