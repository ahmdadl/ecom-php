<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Aggregate\Aggregate;
use HZ\Illuminate\Mongez\Support\MongoDate;
use HZ\Illuminate\Mongez\Support\PeriodDateCalculator;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ReportingPrimitivesTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_period_daily_boundaries_and_previous_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 15:30:00'));

        $period = PeriodDateCalculator::for('daily');

        $this->assertSame('2026-09-04 00:00:00', $period->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-04 23:59:59', $period->to->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-03 00:00:00', $period->prevFrom->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-03 23:59:59', $period->prevTo->format('Y-m-d H:i:s'));
    }

    public function test_period_weekly_starts_on_sunday_by_default(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00')); // Thursday

        $period = PeriodDateCalculator::for('weekly', weekStartsAt: CarbonInterface::SUNDAY);

        $this->assertSame('2026-08-30', $period->from->format('Y-m-d')); // Sunday
        $this->assertSame('2026-09-05', $period->to->format('Y-m-d')); // Saturday
    }

    public function test_period_weekly_can_start_on_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00'));

        $period = PeriodDateCalculator::for('weekly', weekStartsAt: CarbonInterface::MONDAY);

        $this->assertSame('2026-08-31', $period->from->format('Y-m-d'));
        $this->assertSame('2026-09-06', $period->to->format('Y-m-d'));
    }

    public function test_period_monthly_and_yoy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));

        $period = PeriodDateCalculator::forYearOverYear('monthly');

        $this->assertSame('2026-09-01', $period->from->format('Y-m-d'));
        $this->assertSame('2026-09-30', $period->to->format('Y-m-d'));
        $this->assertSame('2025-09-01', $period->prevFrom->format('Y-m-d'));
        $this->assertSame('2025-09-30', $period->prevTo->format('Y-m-d'));
    }

    public function test_period_last_year_mode(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 08:00:00'));

        $period = PeriodDateCalculator::for('monthly', lastYear: true);

        $this->assertSame('2025-03-01', $period->from->format('Y-m-d'));
        $this->assertSame('2025-03-31', $period->to->format('Y-m-d'));
    }

    public function test_mongo_date_round_trip(): void
    {
        $carbon = Carbon::parse('2026-09-04 12:00:00', 'UTC');
        $utc = MongoDate::toMongo($carbon);

        $this->assertInstanceOf(UTCDateTime::class, $utc);

        $back = MongoDate::fromMongo($utc);
        $this->assertNotNull($back);
        $this->assertSame($carbon->getTimestamp(), $back->getTimestamp());
    }

    public function test_mongo_date_helpers_accept_string_and_seconds(): void
    {
        $fromString = to_mongo_date('2026-01-01 00:00:00');
        $this->assertInstanceOf(UTCDateTime::class, $fromString);

        $fromSeconds = mongo_date_to_carbon(1_704_067_200); // 2024-01-01 approx depends on tz
        $this->assertInstanceOf(CarbonInterface::class, $fromSeconds);
    }

    public function test_where_period_emits_between_match(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00'));
        $period = PeriodDateCalculator::for('daily');

        $aggregate = new Aggregate(new \stdClass());
        $aggregate->wherePeriod('createdAt', $period);

        $log = $aggregate->getQueryLog();
        $match = $log[0]['$match']['createdAt'];

        $this->assertInstanceOf(UTCDateTime::class, $match['$gte']);
        $this->assertInstanceOf(UTCDateTime::class, $match['$lte']);
    }

    public function test_where_date_range_from_only(): void
    {
        $aggregate = new Aggregate(new \stdClass());
        $from = Carbon::parse('2026-01-01');
        $aggregate->whereDateRange('createdAt', $from, null);

        $log = $aggregate->getQueryLog();
        $this->assertArrayHasKey('$gte', $log[0]['$match']['createdAt']);
        $this->assertArrayNotHasKey('$lte', $log[0]['$match']['createdAt']);
    }

    public function test_where_date_range_noop_adds_no_stage(): void
    {
        $aggregate = new Aggregate(new \stdClass());
        $result = $aggregate->whereDateRange('createdAt');

        $this->assertSame($aggregate, $result);
        $this->assertSame([], $aggregate->getQueryLog());
    }

    public function test_date_range_match_stage_structure(): void
    {
        $aggregate = new Aggregate(new \stdClass());
        $stage = $aggregate->dateRangeMatchStage(
            'createdAt',
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
        );

        $this->assertArrayHasKey('$match', $stage);
        $this->assertInstanceOf(UTCDateTime::class, $stage['$match']['createdAt']['$gte']);
        $this->assertInstanceOf(UTCDateTime::class, $stage['$match']['createdAt']['$lte']);
    }
}
