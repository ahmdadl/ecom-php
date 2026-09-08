<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing\Traits;

use HZ\Illuminate\Mongez\Helpers\Mongez;
use HZ\Illuminate\Mongez\Resources\JsonResourceManager;
use HZ\Illuminate\Mongez\Testing\TestResponse;

/**
 * Simulate Octane multi-request isolation in feature tests.
 *
 * Call {@see simulateOctaneTurn()} / {@see octaneSequence()} so Mongez request
 * state (locale, resource keys, registered flush callbacks) resets between
 * logical requests — the same boundary `MongezOctaneServiceProvider` enforces.
 */
trait SimulatesOctaneRequests
{
    /**
     * Headers that set Mongez / app locale the same way as production middleware.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    protected function localeHeaders(string $locale, array $extra = []): array
    {
        return array_merge([
            'LOCALE-CODE' => $locale,
        ], $extra);
    }

    /**
     * Reset Mongez package request state (and JsonResourceManager when present).
     */
    protected function resetOctaneRequestState(): void
    {
        Mongez::reset();

        JsonResourceManager::reset();
    }

    /**
     * Run a single logical request after resetting Octane-scoped package state.
     *
     * @template T
     * @param  callable(): T  $request
     * @return T
     */
    protected function simulateOctaneTurn(callable $request): mixed
    {
        $this->resetOctaneRequestState();

        return $request();
    }

    /**
     * Run several logical requests with a reset between each (including before the first).
     *
     * @param  callable(): mixed  ...$requests
     * @return list<mixed>
     */
    protected function octaneSequence(callable ...$requests): array
    {
        $results = [];

        foreach ($requests as $request) {
            $results[] = $this->simulateOctaneTurn($request);
        }

        return $results;
    }

    /**
     * GET with a LOCALE-CODE header.
     *
     * @param  array<string, string>  $headers
     */
    protected function getWithLocale(string $uri, string $locale, array $headers = []): TestResponse
    {
        /** @var TestResponse $response */
        $response = $this->get($uri, $this->localeHeaders($locale, $headers));

        return $response;
    }

    /**
     * POST with a LOCALE-CODE header.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function postWithLocale(string $uri, string $locale, array $data = [], array $headers = []): TestResponse
    {
        /** @var TestResponse $response */
        $response = $this->post($uri, $data, $this->localeHeaders($locale, $headers));

        return $response;
    }
}
