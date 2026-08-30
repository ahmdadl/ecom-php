<?php

namespace HZ\Illuminate\Mongez\Macros\Http;

use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * @mixin IlluminateRequest
 * @method array<int, string> authorization()
 */
class Request
{
    /**
     * If the authorization argument is auto, then it will be auto detect the 
     * value of the passed Authorization header
     * 
     * @const bool
     */
    const AUTO = true;

    /**
     * The converted files, available when this macro is bound to a request at runtime.
     *
     * @var array<string, UploadedFile>
     */
    protected $convertedFiles;

    /**
     * Get request referer
     * 
     * @return \Closure
     */
    public function referer(): \Closure
    {
        return fn() => $this->server('HTTP_REFERER');
    }

    /**
     * Get request uri
     * 
     * @return \Closure
     */
    public function uri(): \Closure
    {
        return function () {
            $scriptName = $this->server('SCRIPT_NAME');
            $script = str_replace('/index.php', '', is_string($scriptName) ? $scriptName : '');

            return '/' . ltrim(Str::removeFirst($script, $this->server('REQUEST_URI')), '/'); // @phpstan-ignore staticMethod.notFound
        };
    }

    /**
     * Get the value of the Authorization header
     * 
     * @return \Closure
     */
    public function authorization(): \Closure
    {
        return function (): array {
            $authorization = $this->server('HTTP_AUTHORIZATION') ?: $this->server('REDIRECT_HTTP_AUTHORIZATION');

            if (!$authorization) {
                if ($token = $this->get('Token')) {
                    return ['Bearer', $token];
                }
                if ($key = $this->get('Key')) {
                    return ['key', $key];
                }

                return [];
            }

            return explode(' ', is_string($authorization) ? $authorization : '');
        };
    }

    /**
     * Add files to request
     * 
     * @param string $fileName
     * @param UploadedFile $file
     * 
     * @return \Closure
     */
    public function addFile(string $fileName = '', ?UploadedFile $file = null): \Closure
    {
        return function (string $fileName, UploadedFile $file) {
            $this->convertedFiles[$fileName] = $file;
        };
    }

    /**
     * Get authorization value only
     * If the authorization argument is auto, then it will be auto detect the 
     * value of the passed Authorization header
     * 
     * If the passed argument is set false, then the whole value will be returned
     * 
     * @param  string|bool $authorizationType
     * @return \Closure
     */
    public function authorizationValue($authorizationType = null): \Closure
    {
        return function ($authorizationType = Request::AUTO) {
            $authorization = $this->authorization();

            if (!$authorization) return null;

            [$type, $value] = $authorization;

            if ($authorizationType === Request::AUTO) {
                return $value;
            }

            if ($authorizationType === $type) return $value;
        };
    }
}
