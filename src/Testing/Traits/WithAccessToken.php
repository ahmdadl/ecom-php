<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Testing\Traits;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;

trait WithAccessToken
{
    /**
     * Generated Access Token
     */
    protected static string $accessToken = '';

    /**
     * Get api key
     */
    protected static string $apiKey = '';

    /**
     * Get access token
     */
    public function getAccessToken(): string
    {
        if (static::$accessToken) return static::$accessToken;

        $accessToken = $this->accessTokenSettings();

        $accessTokenResponseKeyPath = $accessToken['tokenResponseKey'] ?? 'accessToken';

        $this->isAuthenticated = false;

        $response = $this->post($accessToken['route'], $accessToken['body'] ?? [], $accessToken['headers'] ?? []);

        $this->instantMessage('Generating Access Token...', 'cyan');

        static::$accessToken = Arr::get($response->toArray(), $accessTokenResponseKeyPath);
        $this->isAuthenticated = true;

        $this->instantMessage('Access Token Has been generated successfully...', 'green');

        return static::$accessToken;
    }

    /**
     * Get api key
     */
    protected function getApiKey(): string
    {
        return static::$apiKey;
    }

    /**
     * Get access token settings
     *
     * @return array<string, mixed>
     */
    protected function accessTokenSettings(): array
    {
        return config('mongez.testing.accessToken');
    }
}
