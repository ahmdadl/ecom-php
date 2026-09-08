<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Services\Images;

use RuntimeException;

/**
 * Format-aware image encoding (Intervention Image v3).
 *
 * JPEG/WebP/AVIF use configurable lossy quality; PNG stays lossless.
 * Qualities come from `mongez.images.*` when config is available.
 */
final class ImageCompressor
{
    /**
     * @var list<string>
     */
    private const COMPRESSIBLE = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    public static function isCompressibleImage(string $extension): bool
    {
        return in_array(strtolower($extension), self::COMPRESSIBLE, true);
    }

    /**
     * @param  \Intervention\Image\Interfaces\ImageInterface  $image
     * @return \Intervention\Image\Interfaces\EncodedImageInterface
     */
    public static function compressImage(object $image, string $extension): object
    {
        if (! interface_exists(\Intervention\Image\Interfaces\ImageInterface::class)) {
            throw new RuntimeException(
                'intervention/image v3 is required. Install with: composer require intervention/image:^3.0'
            );
        }

        $extension = strtolower($extension);
        $jpegQuality = (int) self::config('mongez.images.jpeg_quality', 85);
        $webpQuality = (int) self::config('mongez.images.webp_quality', 80);
        $avifQuality = (int) self::config('mongez.images.avif_quality', 75);
        $progressive = (bool) self::config('mongez.images.jpeg_progressive', true);

        return match ($extension) {
            'jpg', 'jpeg' => $image->encodeByExtension(
                $extension,
                quality: $jpegQuality,
                progressive: $progressive,
            ),
            'webp' => $image->encodeByExtension($extension, quality: $webpQuality),
            'avif' => $image->encodeByExtension($extension, quality: $avifQuality),
            'png' => $image->encodeByExtension($extension),
            default => $image->encode(),
        };
    }

    public static function compressImageFile(string $path, string $extension): void
    {
        if (! self::isCompressibleImage($extension)) {
            return;
        }

        $image = ImageManagerFactory::make()->read($path);
        self::compressImage($image, $extension)->save($path);
    }

    private static function config(string $key, mixed $default): mixed
    {
        return function_exists('config') ? config($key, $default) : $default;
    }
}
