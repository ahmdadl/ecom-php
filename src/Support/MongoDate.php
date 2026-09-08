<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use MongoDB\BSON\UTCDateTime;

/**
 * Convert between app dates and MongoDB {@see UTCDateTime}.
 *
 * Values are stored as UTC milliseconds. When converting back to Carbon,
 * the result uses `config('app.timezone')` when the `carbon()` helper is available.
 */
final class MongoDate
{
    public static function toMongo(mixed $date): UTCDateTime
    {
        if ($date instanceof UTCDateTime) {
            return $date;
        }

        if ($date instanceof DateTimeInterface) {
            return new UTCDateTime((int) $date->format('Uv'));
        }

        if (is_numeric($date)) {
            $ms = (int) $date;
            if ($ms < 1_000_000_000_000) {
                $ms *= 1000;
            }

            return new UTCDateTime($ms);
        }

        $parsed = new \DateTimeImmutable((string) $date);

        return new UTCDateTime((int) $parsed->format('Uv'));
    }

    public static function fromMongo(mixed $date): ?CarbonInterface
    {
        if ($date === null || $date === '') {
            return null;
        }

        if ($date instanceof UTCDateTime) {
            return self::carbonFromMs((int) $date->toDateTime()->format('Uv'));
        }

        if ($date instanceof CarbonInterface) {
            return $date;
        }

        if ($date instanceof DateTimeInterface) {
            return self::carbonFromMs((int) $date->format('Uv'));
        }

        if (is_numeric($date)) {
            $ms = (int) $date;
            if ($ms < 1_000_000_000_000) {
                $ms *= 1000;
            }

            return self::carbonFromMs($ms);
        }

        return self::carbonFromMs((int) (new \DateTimeImmutable((string) $date))->format('Uv'));
    }

    /**
     * Build Carbon from a millisecond timestamp (or second timestamp if small).
     */
    public static function toCarbon(mixed $date): CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date;
        }

        if ($date instanceof UTCDateTime) {
            return self::carbonFromMs((int) $date->toDateTime()->format('Uv'));
        }

        if ($date instanceof DateTimeInterface) {
            return self::carbonFromMs((int) $date->format('Uv'));
        }

        $ms = (int) $date;
        if ($ms < 1_000_000_000_000) {
            $ms *= 1000;
        }

        return self::carbonFromMs($ms);
    }

    private static function carbonFromMs(int $milliseconds): CarbonInterface
    {
        if (function_exists('app') && function_exists('carbon')) {
            try {
                if (app()->bound('config')) {
                    return carbon()->createFromTimestampMs($milliseconds);
                }
            } catch (\Throwable) {
                // fall through when the container is unavailable (unit tests)
            }
        }

        return Carbon::createFromTimestampMs($milliseconds);
    }
}
