<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Helpers\Mongez;
use HZ\Illuminate\Mongez\Support\RequestScoped;
use PHPUnit\Framework\TestCase;

class RequestScopedTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearMongezResetCallbacks();
        RequestScopedStub::resetRegistrationFlag();
        RequestScopedStub::$currentApplicationType = '';
        RequestScopedStub::$requestFlag = null;
    }

    protected function tearDown(): void
    {
        $this->clearMongezResetCallbacks();
        RequestScopedStub::resetRegistrationFlag();
        parent::tearDown();
    }

    public function testRegisterRequestScopedDefaultsResetsStaticsOnMongezReset(): void
    {
        RequestScopedStub::registerRequestScopedDefaults();
        Mongez::snapshotBaseState();

        RequestScopedStub::$currentApplicationType = 'awui';
        RequestScopedStub::$requestFlag = 'dirty';

        Mongez::reset();

        $this->assertSame('', RequestScopedStub::$currentApplicationType);
        $this->assertNull(RequestScopedStub::$requestFlag);
    }

    public function testRegisterRequestScopedDefaultsIsIdempotent(): void
    {
        RequestScopedStub::registerRequestScopedDefaults();
        RequestScopedStub::registerRequestScopedDefaults();
        Mongez::snapshotBaseState();

        RequestScopedStub::$currentApplicationType = 'awui';
        Mongez::reset();
        $this->assertSame('', RequestScopedStub::$currentApplicationType);

        RequestScopedStub::$currentApplicationType = 'wui';
        Mongez::reset();
        $this->assertSame('', RequestScopedStub::$currentApplicationType);

        // Still a single boot callback after two resets (no accumulation).
        $this->assertCount(1, $this->mongezStaticProperty('baseResetCallbacks'));
    }

    public function testRegistrationAfterSnapshotStillSurvivesReset(): void
    {
        Mongez::snapshotBaseState();
        RequestScopedStub::registerRequestScopedDefaults();

        RequestScopedStub::$currentApplicationType = 'partners_ui';
        Mongez::reset();

        $this->assertSame('', RequestScopedStub::$currentApplicationType);

        RequestScopedStub::$currentApplicationType = 'android';
        Mongez::reset();

        $this->assertSame('', RequestScopedStub::$currentApplicationType);
    }

    /**
     * @param class-string $class
     */
    protected function mongezStaticProperty(string $property): mixed
    {
        return (new \ReflectionClass(Mongez::class))->getStaticPropertyValue($property);
    }

    protected function clearMongezResetCallbacks(): void
    {
        $ref = new \ReflectionClass(Mongez::class);
        $ref->setStaticPropertyValue('resetCallbacks', []);
        $ref->setStaticPropertyValue('baseResetCallbacks', []);
    }
}

class RequestScopedStub
{
    use RequestScoped;

    public static string $currentApplicationType = '';

    public static mixed $requestFlag = null;

    protected static function requestScopedDefaults(): array
    {
        return [
            'currentApplicationType' => '',
            'requestFlag' => null,
        ];
    }

    public static function resetRegistrationFlag(): void
    {
        $ref = new \ReflectionClass(self::class);
        $ref->setStaticPropertyValue('mongezRequestScopedRegistered', false);
    }
}
