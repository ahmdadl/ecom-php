<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Services\Images;

class ImageWatermark extends BaseImage
{
    protected string $watermarkImagePath;

    public function __construct(string $imagePath)
    {
        parent::__construct($imagePath);

        $relative = $this->pathToImageFolder . $this->imageName . '-watermark.' . $this->imageExtension;
        $this->watermarkImagePath = function_exists('public_path')
            ? public_path($relative)
            : (getcwd() . '/' . ltrim($relative, '/'));
    }

    /**
     * Place a watermark (Intervention v3 `place`, replaces v2 `insert`).
     *
     * @param  string  $position  e.g. top-left, center, bottom-right
     */
    public function setWatermark(
        string $watermarkImagePath,
        string $position,
        int $xAxis = 0,
        int $yAxis = 0,
        int $opacity = 100,
    ): string {
        if (! $this->imageHasWatermark()) {
            $absoluteWatermark = function_exists('public_path')
                ? public_path($watermarkImagePath)
                : $watermarkImagePath;

            $watermark = $this->getImageObject($absoluteWatermark);
            $this->imageObject->place($watermark, $position, $xAxis, $yAxis, opacity: $opacity);
            $this->imageObject->save($this->watermarkImagePath);
        }

        return $this->watermarkImagePath;
    }

    protected function imageHasWatermark(): bool
    {
        return file_exists($this->watermarkImagePath);
    }
}
