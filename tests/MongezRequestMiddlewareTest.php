<?php

namespace HZ\Illuminate\Mongez\Tests;

use Illuminate\Http\Request;
use HZ\Illuminate\Mongez\Http\Middleware\MongezRequestMiddleware;
use Orchestra\Testbench\TestCase;

class MongezRequestMiddlewareTest extends TestCase
{
    public function test_database_is_restored_when_request_setup_fails(): void
    {
        config([
            'database.default' => 'testing',
            'database.connections.testing.database' => 'primary',
        ]);

        $middleware = new FailingRequestMiddleware;

        try {
            $middleware->handle(Request::create('/'), static fn (Request $request) => response('ok'));
            $this->fail('Expected request setup to fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('locale setup failed', $exception->getMessage());
        }

        $this->assertSame('primary', config('database.connections.testing.database'));
    }
}

class FailingRequestMiddleware extends MongezRequestMiddleware
{
    protected function switchBetaDatabase(Request $request)
    {
        config(['database.connections.testing.database' => 'beta']);

        return 'primary';
    }

    protected function prepareLocaleCode(Request $request)
    {
        throw new \RuntimeException('locale setup failed');
    }
}
