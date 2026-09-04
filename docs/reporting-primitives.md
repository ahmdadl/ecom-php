# Reporting primitives

Period boundaries, Mongo date conversion, and aggregate date filters (Phase 3).

## PeriodDateCalculator

```php
use HZ\Illuminate\Mongez\Support\PeriodDateCalculator;
use Carbon\CarbonInterface;

$period = PeriodDateCalculator::for('weekly'); // daily|weekly|monthly|quarter|ytd
// $period->from, ->to, ->prevFrom, ->prevTo

PeriodDateCalculator::for('monthly', lastYear: true);
PeriodDateCalculator::forYearOverYear('monthly');
PeriodDateCalculator::labelFor('weekly');
PeriodDateCalculator::labelForYearOverYear('weekly');

// Configurable week start (default Sunday via mongez.reports.week_starts_at)
PeriodDateCalculator::for('weekly', weekStartsAt: CarbonInterface::MONDAY);
```

## Mongo dates

Prefer package helpers (snake_case). API apps can thin-wrap to keep `toMongoDate` names.

```php
use HZ\Illuminate\Mongez\Support\MongoDate;

$utc = to_mongo_date('2026-09-04 12:00:00'); // UTCDateTime
$carbon = from_mongo_date($utc);              // Carbon|null (app timezone when container is up)
$carbon = mongo_date_to_carbon(1_704_067_200_000);

MongoDate::toMongo($carbon);
MongoDate::fromMongo($utc);
```

Stored values are UTC milliseconds. Round-trip uses `config('app.timezone')` when Laravel is booted.

## Aggregate sugar

```php
$aggregate = repo('orders')->aggregate()
    ->wherePeriod('createdAt', PeriodDateCalculator::for('monthly'))
    ->groupByDay('createdAt')
    ->count('nid');

$aggregate->whereDateRange('createdAt', $from, $to); // from-only / to-only / both / neither

$compare = $aggregate->facetCompareCurrentVsPrevious(
    PeriodDateCalculator::for('weekly'),
    function ($q) {
        $q->groupBy()->data($q->utils()->map($q->utils()->count('nid')));
    },
    'createdAt',
);
// ['current' => [...], 'previous' => [...]]
```

Prefer these helpers over new `Model::raw(fn ($col) => $col->aggregate(...))` when the fluent API covers the case.
