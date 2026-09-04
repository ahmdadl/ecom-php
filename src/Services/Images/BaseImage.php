<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Services\Images;

/**
 * Base image helper on Intervention Image v3.
 */
class BaseImage
{
    protected string $imagePath;

    protected string $imageName;

    protected string $pathToImageFolder;

    protected string $imageExtension;

    /** @var \Intervention\Image\Interfaces\ImageInterface */
    protected object $imageObject;

    public function __construct(string $imagePath)
    {
        $publicRoot = function_exists('public_path') ? public_path() : getcwd();
        $this->imagePath = rtrim((string) $publicRoot, '/') . '/' . ltrim($imagePath, '/');
        $this->imageExtension = (string) pathinfo($this->imagePath, PATHINFO_EXTENSION);
        $this->imageName = str_replace('.' . $this->imageExtension, '', basename($imagePath));
        $this->pathToImageFolder = str_replace(basename($imagePath), '', $imagePath);
        $this->imageObject = $this->getImageObject($this->imagePath);
    }

    /**
     * @return \Intervention\Image\Interfaces\ImageInterface
     */
    public function getImageObject(string $imagePath): object
    {
        return ImageManagerFactory::make()->read($imagePath);
    }
}
