<?php
namespace HZ\Illuminate\Mongez\Macros\FileSystem;

use Illuminate\Filesystem\Filesystem as IlluminateFilesystem;

/**
 * @mixin IlluminateFilesystem
 */
class FileSystem
{
    /**
     * Get json content.  
     * 
     * @param string $path
     * @param bool $assoc
     * @return \Closure
     */
    public function getJson(string $path = '', bool $assoc = true): \Closure
    { 
        return function ($path, $assoc = true) {
            $content = $this->get($path);

            if (! $content) return [];

            return json_decode($content, $assoc);    
        };
    }
    
    /**
     * Put json content.  
     * 
     * @param string $path
     * @param array<mixed>|object $content
     * @param int $flags
     * @return \Closure
     */
    public function putJson(string $path = '', $content = null, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): \Closure
    { 
        return function (string $path, $content, $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) {
            $this->put($path, (string) json_encode($content, $flags));
        };
    }
}
