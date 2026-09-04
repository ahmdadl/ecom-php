<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Current / previous period boundaries for reports (daily, weekly, monthly, quarter, ytd).
 *
 * Week start defaults to Sunday (api-zamil-octane); override via
 * {@see for()} `$weekStartsAt` or `mongez.reports.week_starts_at`.
 */
final class PeriodDateCalculator
{
    public function __construct(
        public readonly CarbonInterface $from,
        public readonly CarbonInterface $to,
        public readonly CarbonInterface $prevFrom,
        public readonly CarbonInterface $prevTo,
    ) {
    }

    public static function labelFor(string $period, bool $lastYear = false, ?CarbonInterface $now = null): string
    {
        $now = self::resolveNow($now, $lastYear);

        return match ($period) {
            'weekly' => 'Vs Week ' . $now->copy()->subWeek()->isoWeek(),
            'monthly' => 'Vs ' . $now->copy()->subMonth()->format('F Y'),
            'quarter' => (function () use ($now) {
                $prev = $now->copy()->subQuarter();

                return 'Vs Q' . $prev->quarter . ' ' . $prev->year;
            })(),
            'ytd' => 'Vs ' . $now->copy()->subYear()->format('F Y'),
            default => 'Vs ' . $now->copy()->subDay()->format('j F Y'),
        };
    }

    public static function for(
        string $period,
        bool $lastYear = false,
        ?CarbonInterface $now = null,
        ?int $weekStartsAt = null,
    ): self {
        $now = self::resolveNow($now, $lastYear);
        $weekStartsAt ??= self::defaultWeekStartsAt();
        $weekEndsAt = ($weekStartsAt + 6) % 7;

        return match ($period) {
            'weekly' => new self(
                $now->copy()->startOfWeek($weekStartsAt),
                $now->copy()->endOfWeek($weekEndsAt),
                $now->copy()->subWeek()->startOfWeek($weekStartsAt),
                $now->copy()->subWeek()->endOfWeek($weekEndsAt),
            ),
            'monthly' => new self(
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
                $now->copy()->subMonth()->startOfMonth(),
                $now->copy()->subMonth()->endOfMonth(),
            ),
            'quarter' => new self(
                $now->copy()->startOfQuarter(),
                $now->copy()->endOfQuarter(),
                $now->copy()->subQuarter()->startOfQuarter(),
                $now->copy()->subQuarter()->endOfQuarter(),
            ),
            'ytd' => new self(
                $now->copy()->startOfYear(),
                $now->copy()->endOfDay(),
                $now->copy()->subYear()->startOfYear(),
                $now->copy()->subYear()->endOfDay(),
            ),
            default => new self(
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ),
        };
    }

    /**
     * Current period vs the same window one year earlier (YoY).
     */
    public static function forYearOverYear(
        string $period,
        ?CarbonInterface $now = null,
        ?int $weekStartsAt = null,
    ): self {
        $current = self::for($period, false, $now, $weekStartsAt);

        return new self(
            $current->from,
            $current->to,
            $current->from->copy()->subYear(),
            $current->to->copy()->subYear(),
        );
    }

    public static function labelForYearOverYear(string $period, ?CarbonInterface $now = null): string
    {
        $lastYear = self::resolveNow($now, false)->copy()->subYear();

        return match ($period) {
            'weekly' => 'Vs Week ' . $lastYear->isoWeek() . ' ' . $lastYear->year,
            'monthly' => 'Vs ' . $lastYear->format('F Y'),
            'quarter' => 'Vs Q' . $lastYear->quarter . ' ' . $lastYear->year,
            'ytd' => 'Vs YTD ' . $lastYear->format('j F Y'),
            default => 'Vs ' . $lastYear->format('j F Y'),
        };
    }

    /**
     * @return array{from: CarbonInterface, to: CarbonInterface, prevFrom: CarbonInterface, prevTo: CarbonInterface}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'prevFrom' => $this->prevFrom,
            'prevTo' => $this->prevTo,
        ];
    }

    private static function resolveNow(?CarbonInterface $now, bool $lastYear): CarbonInterface
    {
        $base = $now?->copy() ?? Carbon::now();

        return $lastYear ? $base->subYear() : $base;
    }

    private static function defaultWeekStartsAt(): int
    {
        if (! function_exists('config') || ! function_exists('app')) {
            return CarbonInterface::SUNDAY;
        }

        try {
            if (! app()->bound('config')) {
                return CarbonInterface::SUNDAY;
            }

            return (int) config('mongez.reports.week_starts_at', CarbonInterface::SUNDAY);
        } catch (\Throwable) {
            return CarbonInterface::SUNDAY;
        }
    }
}
