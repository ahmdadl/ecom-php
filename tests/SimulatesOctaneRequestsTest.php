<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Helpers\Mongez;
use HZ\Illuminate\Mongez\Testing\Traits\SimulatesOctaneRequests;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class SimulatesOctaneRequestsTest extends BaseTestCase
{
    use SimulatesOctaneRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionClass(Mongez::class);
        $ref->setStaticPropertyValue('resetCallbacks', []);
        $ref->setStaticPropertyValue('baseResetCallbacks', []);
        $ref->setStaticPropertyValue('requestLocaleCode', '');
    }

    public function test_locale_headers_set_locale_code(): void
    {
        $this->assertSame(
            ['LOCALE-CODE' => 'ar', 'X-App' => 'site'],
            $this->localeHeaders('ar', ['X-App' => 'site'])
        );
    }

    public function test_octane_sequence_resets_locale_between_turns(): void
    {
        $seen = $this->octaneSequence(
            static function (): string {
                Mongez::setRequestLocaleCode('ar');

                return Mongez::getRequestLocaleCode();
            },
            static function (): string {
                return Mongez::requestHasLocaleCode()
                    ? Mongez::getRequestLocaleCode()
                    : '';
            },
            static function (): string {
                Mongez::setRequestLocaleCode('en');

                return Mongez::getRequestLocaleCode();
            },
        );

        $this->assertSame(['ar', '', 'en'], $seen);
    }

    public function test_simulate_octane_turn_clears_prior_locale(): void
    {
        Mongez::setRequestLocaleCode('ar');

        $locale = $this->simulateOctaneTurn(static function (): string {
            return Mongez::requestHasLocaleCode()
                ? Mongez::getRequestLocaleCode()
                : 'cleared';
        });

        $this->assertSame('cleared', $locale);
    }
}
