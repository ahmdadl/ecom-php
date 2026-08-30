<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing;

abstract class AdminApiTestCase extends ApiTestCase
{
    /**
     * If marked as true, a bearer token will be passed with Bearer in the Authorization Header
     */
    protected ?bool $isAuthenticated = true;

    /**
     * Add Prefix to all routes
     * 
     * @var string
     */
    protected $apiPrefix = '/api/admin';

    /**
     * Module route
     */
    protected string $route = '';

    /**
     * Response Object
     *
     * @var \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    protected $response;

    /**
     * Get full data but replace the given array keys
     *
     * @param  array<string, mixed> $newData
     * @return array<string, mixed>
     */
    protected function fullDataReplace(array $newData): array
    {
        return $this->fullDataWith($newData);
    }

    /**
     * Get full data except the given keys
     *
     * @param  array<int, string> $exceptKeys
     * @return array<string, mixed>
     */
    protected function fullDataExcept(array $exceptKeys): array
    {
        return collect($this->fullData())->except($exceptKeys)->toArray();
    }

    /**
     * Merge the given array with the full data
     *
     * @param  array<string, mixed> $otherData
     * @return array<string, mixed>
     */
    protected function fullDataWith(array $otherData): array
    {
        return array_merge($this->fullData(), $otherData);
    }

    /**
     * Get request route
     */
    protected function getRoute(): string
    {
        return $this->route;
    }

    /**
     * Define the full data that should be fully valid.
     * This includes required and optional data
     *
     * @return array<string, mixed>
     */
    protected function fullData(): array
    {
        return [];
    }

    /**
     * Define the record shape that will be returned
     * It must contain the entire record shape even if not present in all requests
     * 
     * @return array
     */
    // abstract protected function responseShape(): array;
}
