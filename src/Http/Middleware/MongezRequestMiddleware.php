<?php

namespace HZ\Illuminate\Mongez\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use HZ\Illuminate\Mongez\Helpers\Mongez;

class MongezRequestMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Handles CORS pre-flight requests, per-request locale code detection
     * and beta database switching without mutating the application state
     * in a way that persists between requests (Octane safe).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->method() === 'OPTIONS') {
            return response()->json([
                'success' => true,
                'mongez' => true,
            ]);
        }

        $this->switchBetaDatabase($request);

        $this->prepareLocaleCode($request);

        return $next($request);
    }

    /**
     * Switch the default database connection if a beta header is sent
     *
     * @param  \Illuminate\Http\Request $request
     * @return void
     */
    protected function switchBetaDatabase(Request $request)
    {
        if (!($betaDBName = $request->server('HTTP_BETA'))) {
            return;
        }

        $defaultDatabaseDriver = config('database.default');
        $dbConfigName = 'database.connections.' . $defaultDatabaseDriver . '.database';

        if ($betaDBName === 'true') {
            $betaDBName = 'BETA';
        }

        $betaDatabase = env("DB_DATABASE_$betaDBName");

        config([
            $dbConfigName => $betaDatabase,
        ]);
    }

    /**
     * Prepare locale code based on the current request
     *
     * @param  \Illuminate\Http\Request $request
     * @return void
     */
    protected function prepareLocaleCode(Request $request)
    {
        $localeCode = $request->header('LOCALE-CODE') ?: ($request->input('localeCode') ?: $request->input('acceptLanguage'));

        if ($localeCode) {
            app()->setLocale($localeCode);
            Mongez::setRequestLocaleCode($localeCode);
        }
    }
}
