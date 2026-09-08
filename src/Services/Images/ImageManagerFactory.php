<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Services\Images;

use RuntimeException;

/**
 * Builds an Intervention Image v3 ImageManager (Imagick preferred when configured).
 */
final class ImageManagerFactory
{
    /**
     * @return \Intervention\Image\ImageManager
     */
    public static function make(?string $driver = null): object
    {
        if (! class_exists(\Intervention\Image\ImageManager::class)) {
            throw new RuntimeException(
                'intervention/image v3 is required for Mongez image services. '
                . 'Install with: composer require intervention/image:^3.0'
            );
        }

        $driver ??= (string) (function_exists('config')
            ? config('mongez.images.driver', config('image.driver', 'gd'))
            : 'gd');

        $driverClass = strtolower($driver) === 'imagick' && extension_loaded('imagick')
            ? \Intervention\Image\Drivers\Imagick\Driver::class
            : \Intervention\Image\Drivers\Gd\Driver::class;

        return new \Intervention\Image\ImageManager($driverClass);
    }
}
