<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;

class Mongez
{

    /**
     * Root of mongez file in storage directory
     *
     * @const string
     */
    const MONGEZ_STORAGE_DIRECTORY = 'mongez';

    /**
     * Mongez file name in stored directory.
     *
     * @const string
     */
    const MONGEZ_STORAGE_FILE_NAME = 'mongez.json';

    /**
     * Mongez default content
     * 
     * @const array
     */
    const MONGEZ_STORAGE_FILE_DEfAULT_CONTENT = [
        'installed' => true,
        'modules' => [],
        'postmanVersion' => "1.0"
    ];

    /**
     * Mongez File path.
     *
     * @var string
     */
    protected static $mongezFilePath;

    /**
     * Request locale code
     *
     * @var string
     */
    protected static $requestLocaleCode = '';

    /**
     * Mongez file content
     * 
     * @var array
     */
    protected static $mongezContent;

    /**
     * Prepare the Mongez Console
     * Create Mongez storage directory.
     *
     * @return void
     */
    public static function init()
    {
        static::$mongezFilePath = static::getMongezStorageDirectory() . '/' . static::MONGEZ_STORAGE_FILE_NAME;
    }

    /**
     * Set request locale code
     *
     * @return void
     */
    public static function setRequestLocaleCode(string $requestLocaleCode)
    {
        static::$requestLocaleCode = $requestLocaleCode;

        if (app()->bound('request')) {
            $request = app('request');

            $request->attributes->set('mongezRequestLocaleCode', $requestLocaleCode);
        }
    }

    /**
     * Set the application locale from the incoming request.
     *
     * Reads the locale from the LOCALE-CODE header, then the `localeCode`
     * input, then the `acceptLanguage` input. When found, the application
     * locale and the per-request Mongez locale code are both updated.
     *
     * When no locale is present the stored per-request locale code is reset
     * so persistent workers (e.g. Laravel Octane) do not leak the previous
     * request's locale to the next one. The application locale itself is left
     * untouched in that case.
     */
    public static function setLocaleFromRequest(Request $request): void
    {
        $localeCode = $request->header('LOCALE-CODE')
            ?: ($request->input('localeCode') ?: $request->input('acceptLanguage'));

        if ($localeCode) {
            App::setLocale($localeCode);
            static::setRequestLocaleCode($localeCode);
            return;
        }

        // Reset so a request without a locale does not inherit the previous
        // request's locale in persistent workers (e.g. Laravel Octane).
        static::setRequestLocaleCode('');
    }

    /**
     * Check if request has a locale code key
     */
    public static function requestHasLocaleCode(): bool
    {
        return static::getRequestLocaleCode() !== '';
    }

    /**
     * Get request locale code
     */
    public static function getRequestLocaleCode(): string
    {
        if (app()->bound('request')) {
            $request = app('request');

            $localeCode = $request->attributes->get('mongezRequestLocaleCode', '');

            if ($localeCode !== '') return $localeCode;
        }

        return static::$requestLocaleCode;
    }

    /**
     * Check if package is installed
     */
    public static function isInstalled(): bool
    {
        return File::isFile(static::getMongezStorageFilePath());
    }

    /**
     * Prepare the package for the first time 
     * 
     * @return void
     */
    public static function install()
    {
        File::MakeDirectory(static::getMongezStorageDirectory(), 0777);

        File::put(static::getMongezStorageFilePath(), json_encode(static::MONGEZ_STORAGE_FILE_DEfAULT_CONTENT, JSON_PRETTY_PRINT));
    }

    /**
     * Reset the request scoped state of the helper
     *
     * This is used between requests when running on Laravel Octane
     * to make sure no request state leaks from one request to another.
     * The storage file path is kept as it is an immutable application state.
     *
     * @return void
     */
    public static function reset()
    {
        static::$requestLocaleCode = '';
        static::$mongezContent = null;
    }

    /**
     * Get mongez file path.
     *
     * @return string
     */
    protected static function getMongezStorageFilePath()
    {
        if (!static::$mongezFilePath) {
            static::$mongezFilePath = static::getMongezStorageDirectory() . '/' . static::MONGEZ_STORAGE_FILE_NAME;
        }

        return static::$mongezFilePath;
    }

    /**
     * Get Mongez storage directory.
     *
     * @return string
     */
    protected static function getMongezStorageDirectory()
    {
        return storage_path(static::MONGEZ_STORAGE_DIRECTORY);
    }

    /**
     * Set storage file content.
     * 
     * @array $array  
     */
    protected static function setStorageFileContent(array $content)
    {
        File::putJson(static::getMongezStorageFilePath(), $content);
    }

    /**
     * Get value from mongez config file by key.
     *
     * @return mixed
     */
    public static function getStored($key)
    {
        if (!static::$mongezContent) {
            static::$mongezContent = static::getStorageFileContent();
        }

        return Arr::get(static::$mongezContent, $key);
    }

    /**
     * Get all stored config data.
     *
     * @return mixed
     */
    protected static function getStorageFileContent()
    {
        return File::getJson(static::getMongezStorageFilePath());
    }

    /**
     * Update value of config key.
     *
     * @param string $key.
     * @param string $value.
     * @return mixed
     */
    public static function setStored($key, $value)
    {
        static::$mongezContent[$key] = $value;
    }

    /**
     * Append value to an arrayable key
     *
     * @param  mixed $value
     * @return void
     */
    public static function append(string $key, $value)
    {
        $list = static::getStored($key);

        if (!$list) {
            $list = [];
        }

        if (in_array($value, $list)) return;

        $list[] = $value;

        static::setStored($key, $list);
    }

    /**
     * Update storage file 
     * 
     * @return void 
     */
    public static function updateStorageFile()
    {
        static::setStorageFileContent(static::$mongezContent);
    }

    /**
     * Get the package path
     * 
     * @param string $path 
     * @return string
     */
    public static function packagePath($path = '')
    {
        return dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    }

    /**
     * Remove value from an arrayable key
     * 
     * @param string $moduleName
     * @return void
     */
    public static function remove(string $key, string $value)
    {
        $list = static::getStored($key);
        $valueIndex = array_search($value, $list);

        unset($list[$valueIndex]);

        static::setStored($key, $list);
        static::updateStorageFile();
    }

    /**
     * Get current app type
     */
    public static function appType(): string
    {
        return config('app.type');
    }
}
