<?php

namespace HZ\Illuminate\Mongez\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $originalDatabase = $this->switchBetaDatabase($request);

        $this->prepareLocaleCode($request);

        try {
            return $next($request);
        } finally {
            if ($originalDatabase !== null) {
                $this->restoreDatabase($originalDatabase);
            }
        }
    }

    /**
     * Switch the default database connection if a beta header is sent
     *
     * Returns the original database name if the connection was switched,
     * otherwise null.
     *
     * @param  \Illuminate\Http\Request $request
     * @return string|null
     */
    protected function switchBetaDatabase(Request $request)
    {
        $betaDBName = $request->server('HTTP_BETA');

        if (!$betaDBName) {
            return null;
        }

        $driver = config('database.default');
        $connectionConfigKey = 'database.connections.' . $driver . '.database';
        $originalDatabase = config($connectionConfigKey);

        if ($betaDBName === 'true') {
            $betaDBName = 'BETA';
        }

        $betaDatabase = $this->resolveBetaDatabaseName($betaDBName);

        if (!$betaDatabase || $betaDatabase === $originalDatabase) {
            return null;
        }

        config([
            $connectionConfigKey => $betaDatabase,
        ]);

        // discard the already resolved connection so it reconnects to the beta database
        DB::purge($driver);

        return $originalDatabase;
    }

    /**
     * Restore the original database connection after the request is finished
     *
     * @param  string $originalDatabase
     * @return void
     */
    protected function restoreDatabase(string $originalDatabase)
    {
        $driver = config('database.default');

        config([
            'database.connections.' . $driver . '.database' => $originalDatabase,
        ]);

        // discard the beta connection so the next request uses the original database
        DB::purge($driver);
    }

    /**
     * Resolve the beta database name from the given beta flag
     *
     * Only alphanumeric/underscore names are allowed to avoid
     * injecting arbitrary environment variable names.
     *
     * @param  string $betaDBName
     * @return string|null
     */
    protected function resolveBetaDatabaseName(string $betaDBName)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $betaDBName)) {
            return null;
        }

        $betaDatabase = config('mongez.database.beta.' . $betaDBName);

        if ($betaDatabase !== null) {
            return $betaDatabase;
        }

        return env("DB_DATABASE_$betaDBName") ?: null;
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
        } else {
            // ensure the locale doesn't leak from a previous request
            app()->setLocale(config('app.locale'));
        }
    }
}
