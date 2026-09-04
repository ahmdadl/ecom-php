<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Services\Images\ImageCompressor;
use HZ\Illuminate\Mongez\Services\Images\ImageManagerFactory;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ImageStackTest extends BaseTestCase
{
    public function test_is_compressible_image(): void
    {
        $this->assertTrue(ImageCompressor::isCompressibleImage('jpg'));
        $this->assertTrue(ImageCompressor::isCompressibleImage('WEBP'));
        $this->assertFalse(ImageCompressor::isCompressibleImage('svg'));
        $this->assertFalse(ImageCompressor::isCompressibleImage('gif'));
    }

    public function test_manager_and_compress_round_trip_when_intervention_installed(): void
    {
        if (! class_exists(\Intervention\Image\ImageManager::class)) {
            $this->markTestSkipped('intervention/image v3 not installed');
        }

        $manager = ImageManagerFactory::make('gd');
        $image = $manager->create(10, 10)->fill('ff0000');

        $encoded = ImageCompressor::compressImage($image, 'jpg');
        $bytes = (string) $encoded;

        $this->assertNotSame('', $bytes);
        $this->assertGreaterThan(50, strlen($bytes));
    }
}
