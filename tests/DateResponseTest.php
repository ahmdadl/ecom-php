<?php

namespace HZ\Illuminate\Mongez\Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

class DateResponseTest extends TestCase
{
    /**
     * Immutable dates must not trip PHP 8.5's #[NoDiscard] enforcement
     * (phpunit.xml.dist has failOnWarning="true", so the warning alone
     * fails this test on the unfixed code).
     */
    public function testImmutableDateRaisesNoWarning(): void
    {
        $out = date_response(CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC'));

        $this->assertIsArray($out);
        $this->assertArrayHasKey('format', $out);
    }

    /**
     * The timezone conversion must actually apply to immutable inputs.
     * Before the fix the return value of setTimezone() was discarded, so
     * the output stayed in the input timezone (silently wrong result).
     */
    public function testImmutableDateConvertsToAppTimezone(): void
    {
        config()->set('app.timezone', 'Asia/Riyadh');

        $out = date_response(
            CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC'),
            ['timestamp' => false, 'humanTime' => false, 'intl' => false]
        );

        // 12:00 UTC == 15:00 Asia/Riyadh
        $this->assertSame('29-10-2026 03:00:00 pm', $out);
    }

    /**
     * Mutable inputs keep working exactly as before (setTimezone returns
     * $this there, so the assignment is a no-op for them).
     */
    public function testMutableDateStillConverts(): void
    {
        config()->set('app.timezone', 'Asia/Riyadh');

        $out = date_response(
            Carbon::parse('2026-10-29 12:00:00', 'UTC'),
            ['timestamp' => false, 'humanTime' => false, 'intl' => false]
        );

        $this->assertSame('29-10-2026 03:00:00 pm', $out);
    }
}
