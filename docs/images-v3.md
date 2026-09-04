# Images (Intervention v3) and compression

Soft dependency: `composer require intervention/image:^3.0`.

## Manager

```php
use HZ\Illuminate\Mongez\Services\Images\ImageManagerFactory;

$manager = ImageManagerFactory::make(); // imagick if mongez.images.driver=imagick + ext loaded
$image = $manager->read($path);
```

Config (`mongez.images.*`):

| Key | Default | Role |
|-----|---------|------|
| `driver` | `gd` | `imagick` or `gd` |
| `jpeg_quality` | `85` | Lossy JPEG quality |
| `webp_quality` | `80` | WebP quality |
| `avif_quality` | `75` | AVIF quality |
| `jpeg_progressive` | `true` | Progressive JPEG |

## Compression

```php
use HZ\Illuminate\Mongez\Services\Images\ImageCompressor;

ImageCompressor::isCompressibleImage('jpg'); // jpg/jpeg/png/webp/avif
$encoded = ImageCompressor::compressImage($image, 'jpg');
ImageCompressor::compressImageFile($path, 'webp');
```

PNG stays lossless; use an external optimizer or convert derivatives to WebP for smaller PNGs.

## Resize / watermark

```php
use HZ\Illuminate\Mongez\Services\Images\ImageResize;
use HZ\Illuminate\Mongez\Services\Images\ImageWatermark;

$path = (new ImageResize('uploads/photo.jpg'))->resize(800, 600);
(new ImageWatermark('uploads/photo.jpg'))
    ->setWatermark('uploads/logo.png', 'bottom-right', 10, 10, opacity: 80);
```

v2 `Image::make` / `insert` are replaced by v3 `ImageManager::read` / `place`.
