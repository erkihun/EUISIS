<?php

declare(strict_types=1);

namespace App\Support\Images;

use GdImage;
use Throwable;

/**
 * Shrinks uploaded raster images to the size the interface actually shows and
 * re-encodes them in a modern format, with GD alone (no extra extension).
 *
 * Before this, a 1.3 MB favicon and 1–2 MB logos and photos were served as
 * uploaded on every page. A result is produced only when it is worth storing:
 * the image was downscaled, or the new encoding is smaller. Anything GD cannot
 * read (ICO, SVG, GIF, corrupt data) or that is too large to decode within the
 * memory limit is left exactly as uploaded.
 *
 * Encodings: logos and seals become lossless WebP (transparency and crisp edges
 * kept), photos lossy WebP, favicons PNG. Without WebP support in GD the
 * original format is kept and only the size is reduced.
 */
final class ImageOptimizer
{
    /** Decoding a larger image would exceed a 128 MB memory limit. */
    public const MAX_PIXELS = 20_000_000;

    public const PHOTO_QUALITY = 80;

    private const READABLE = ['image/png', 'image/jpeg', 'image/webp'];

    /** @param  int|null  $maxDimension  a smaller longest side than the kind's, for a derived copy such as a thumbnail */
    public function optimize(string $sourcePath, ImageKind $kind, ?int $maxDimension = null): ?OptimizedImage
    {
        if (! is_file($sourcePath) || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $info = @getimagesize($sourcePath);

        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;
        $mime = (string) ($info['mime'] ?? '');

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS || ! in_array($mime, self::READABLE, true)) {
            return null;
        }

        $image = $this->decode($sourcePath, $mime);

        if ($image === null) {
            return null;
        }

        try {
            if ($mime === 'image/jpeg') {
                $image = $this->applyExifOrientation($image, $sourcePath);
            }

            $keepsAlpha = $mime !== 'image/jpeg';
            $max = min($kind->maxDimension(), $maxDimension ?? PHP_INT_MAX);
            $scale = min(1.0, $max / max(imagesx($image), imagesy($image)));
            $downscaled = $scale < 1.0;

            if ($downscaled) {
                $image = $this->resample(
                    $image,
                    max(1, (int) round(imagesx($image) * $scale)),
                    max(1, (int) round(imagesy($image) * $scale)),
                    $keepsAlpha,
                );
            }

            [$tempPath, $extension] = $this->encode($image, $kind, $mime, $keepsAlpha);
            $outWidth = imagesx($image);
            $outHeight = imagesy($image);
        } finally {
            imagedestroy($image);
        }

        if ($tempPath === null) {
            return null;
        }

        $bytes = (int) filesize($tempPath);
        $originalBytes = (int) filesize($sourcePath);

        if ($bytes < 1 || (! $downscaled && $bytes >= $originalBytes)) {
            @unlink($tempPath);

            return null;
        }

        return new OptimizedImage($tempPath, $extension, $outWidth, $outHeight, $bytes);
    }

    public static function supportsWebp(): bool
    {
        return function_exists('imagewebp');
    }

    private function decode(string $path, string $mime): ?GdImage
    {
        try {
            $image = match ($mime) {
                'image/png' => @imagecreatefrompng($path),
                'image/jpeg' => @imagecreatefromjpeg($path),
                'image/webp' => @imagecreatefromwebp($path),
                default => false,
            };
        } catch (Throwable) {
            return null;
        }

        if (! $image instanceof GdImage) {
            return null;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /** Photos from phones are stored sideways with an EXIF flag; bake the rotation in so the browser need not. */
    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        try {
            $exif = @exif_read_data($path);
        } catch (Throwable) {
            return $image;
        }

        $orientation = (int) ($exif['Orientation'] ?? 1);
        $degrees = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);

        if (! $rotated instanceof GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function resample(GdImage $source, int $width, int $height, bool $keepsAlpha): GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);

        if ($keepsAlpha) {
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefill($target, 0, 0, $transparent);
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        imagedestroy($source);

        return $target;
    }

    /** @return array{0: ?string, 1: string} temporary file path (null on failure) and the extension it should be stored under */
    private function encode(GdImage $image, ImageKind $kind, string $sourceMime, bool $keepsAlpha): array
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'image-optimizer-');

        if ($tempPath === false) {
            return [null, ''];
        }

        $written = false;
        $extension = '';

        if ($kind === ImageKind::Favicon) {
            $written = imagepng($image, $tempPath, 9);
            $extension = 'png';
        } elseif (self::supportsWebp()) {
            $written = $kind === ImageKind::Photo
                ? imagewebp($image, $tempPath, self::PHOTO_QUALITY)
                : (defined('IMG_WEBP_LOSSLESS') && imagewebp($image, $tempPath, IMG_WEBP_LOSSLESS)) || imagewebp($image, $tempPath, 90);
            $extension = 'webp';
        }

        if (! $written) {
            // Same format as uploaded, smaller: PNG (lossless, transparency kept) or JPEG for photographs.
            if ($sourceMime === 'image/jpeg' || ($kind === ImageKind::Photo && ! $keepsAlpha)) {
                $written = imagejpeg($image, $tempPath, 82);
                $extension = 'jpg';
            } else {
                $written = imagepng($image, $tempPath, 9);
                $extension = 'png';
            }
        }

        if (! $written) {
            @unlink($tempPath);

            return [null, ''];
        }

        return [$tempPath, $extension];
    }
}
