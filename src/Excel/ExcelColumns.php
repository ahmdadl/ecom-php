<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Excel;

use Carbon\CarbonInterface;
use DateTimeInterface;
use HZ\Illuminate\Mongez\Support\MongoDate;

/**
 * Shared column helpers for Excel export / import (no Maatwebsite dependency).
 */
final class ExcelColumns
{
    /**
     * Resolve localized text from Mongez `{localeCode, text}` arrays.
     */
    public static function localizedColumn(
        mixed $value,
        ?string $locale = null,
        string $textColumn = 'text',
    ): mixed {
        $locale ??= function_exists('app') ? (string) app()->getLocale() : 'en';

        if (function_exists('get_localized_value') && (is_array($value) || is_string($value))) {
            return get_localized_value($value, $locale, $textColumn);
        }

        if (is_string($value) || is_numeric($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Format a date column for spreadsheets.
     */
    public static function dateColumn(
        mixed $date,
        ?string $format = null,
    ): ?string {
        if ($date === null || $date === '') {
            return null;
        }

        $format ??= (string) (function_exists('config')
            ? config('mongez.resources.date.format', 'd-m-Y H:i:s')
            : 'd-m-Y H:i:s');

        if ($date instanceof CarbonInterface || $date instanceof DateTimeInterface) {
            return $date->format($format);
        }

        $carbon = MongoDate::fromMongo($date);

        return $carbon?->format($format);
    }
}
