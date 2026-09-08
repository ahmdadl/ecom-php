<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Services\Images;

use Illuminate\Support\Facades\File;

class ImageResize extends BaseImage
{
    protected int $width = 0;

    protected int $height = 0;

    protected string $resizedImageName = '';

    /**
     * Resize keeping aspect ratio (Intervention v3 `scale`).
     */
    public function resize(int $width, int $height, int $quality = 100): string
    {
        $this->width = $width;
        $this->height = $height;

        if (! $this->imageHasResized()) {
            $image = $this->imageObject->scale(width: $width, height: $height);

            if ($quality < 100 && ImageCompressor::isCompressibleImage($this->imageExtension)) {
                $encoded = ImageCompressor::compressImage($image, $this->imageExtension);
            } else {
                $encoded = $image->encodeByExtension($this->imageExtension, quality: $quality);
            }

            $target = $this->publicPath($this->pathToImageFolder . $this->resizedImageName);
            File::put($target, (string) $encoded);
        }

        return $this->pathToImageFolder . '/' . $this->resizedImageName;
    }

    protected function imageHasResized(): bool
    {
        $this->resizedImageName = $this->imageName . '-' . ($this->width * $this->height) . '.' . $this->imageExtension;

        return file_exists($this->publicPath($this->pathToImageFolder . $this->resizedImageName));
    }

    protected function publicPath(string $relative): string
    {
        $relative = ltrim($relative, '/');

        return function_exists('public_path')
            ? public_path('/' . $relative)
            : (getcwd() . '/' . $relative);
    }
}
