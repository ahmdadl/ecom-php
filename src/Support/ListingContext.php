<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Support;

/**
 * Blessed base for request-scoped listing contexts.
 *
 * A listing context is primed once per `list()` call (see
 * `Listable::primeListing()`) with the ids on the current page, resolves the
 * per-row lookups the resources need with a fixed number of batch queries,
 * and is read from the resource's `extend()` via `$this->isListing()`.
 *
 * Static state is reset between Octane requests through {@see RequestScoped}
 * (call `static::registerRequestScopedDefaults()` from a service provider
 * `boot()` method).
 *
 * @example
 * ```php
 * final class WorkOrderListingContext extends ListingContext
 * {
 *     protected static array $workOrders = [];
 *
 *     protected static function requestScopedDefaults(): array
 *     {
 *         return ['workOrders' => []];
 *     }
 *
 *     public static function prime(array $nids): void { ... }
 *     public static function covers(int $nid): bool { ... }
 * }
 * ```
 */
abstract class ListingContext
{
    use RequestScoped;
}
