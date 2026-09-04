<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Support;

use HZ\Illuminate\Mongez\Helpers\Mongez;

/**
 * Opt-in helpers for classes that keep request-scoped static properties under Octane.
 *
 * Usage (from a service provider `boot()` method):
 *
 * ```php
 * class Application
 * {
 *     use RequestScoped;
 *
 *     public static string $currentApplicationType = '';
 *
 *     protected static function requestScopedDefaults(): array
 *     {
 *         return ['currentApplicationType' => ''];
 *     }
 * }
 *
 * // AppServiceProvider::boot()
 * Application::registerRequestScopedDefaults();
 * ```
 *
 * Prefer this over a custom Octane listener that re-resets Mongez internals.
 */
trait RequestScoped
{
    private static bool $mongezRequestScopedRegistered = false;

    /**
     * Map of static property name => default value restored on each Octane reset.
     *
     * @return array<string, mixed>
     */
    protected static function requestScopedDefaults(): array
    {
        return [];
    }

    /**
     * Subscribe this class's request-scoped statics to {@see Mongez::onBootReset()}.
     *
     * Safe to call more than once; registration happens only on the first call.
     * Call from a service provider `boot()` method.
     */
    public static function registerRequestScopedDefaults(): void
    {
        if (static::$mongezRequestScopedRegistered) {
            return;
        }

        static::$mongezRequestScopedRegistered = true;

        Mongez::onBootReset(static function (): void {
            static::resetRequestScopedState();
        });
    }

    /**
     * Restore request-scoped static properties to their defaults.
     */
    public static function resetRequestScopedState(): void
    {
        foreach (static::requestScopedDefaults() as $property => $default) {
            static::$$property = $default;
        }
    }
}
