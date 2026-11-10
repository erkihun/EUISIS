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
 * kept), photos lossy WebP, favicons PNG, images for print PNG or JPEG as
 * uploaded (dompdf embeds no WebP). Without WebP support in GD the
 * original format is kept and only the size is reduced.
 */
final class ImageOptimizer
{
    /**
     * Nothing larger than this is decoded, whatever the memory limit (about an
     * 11,000 px square, up to 1.2 GB while GD converts it). Smaller images are
     * decoded when memory allows, and the limit is raised for the one image when
     * it has to be. The uploads that defeated the old fixed 20-megapixel cap were
     * 6,683 × 6,683 (favicon), 7,473 × 4,368 (logo) and an 11,576 × 8,186
     * employee photo.
     */
    public const MAX_PIXELS = 120_000_000;

    /**
     * Bytes to reserve per pixel: GD holds a decoded truecolor pixel in four bytes
     * and, for a palette PNG or a rotated JPEG, a second full-size copy while it
     * converts. Running out of memory is fatal (the request dies, the upload is
     * lost), so the estimate is for that worst case.
     */
    private const BYTES_PER_PIXEL = 10;

    /** Headroom for the resampled copy, the encoder and the framework itself. */
    private const DECODE_OVERHEAD_BYTES = 48 * 1024 * 1024;

    /** The memory limit is never raised past this for an image. */
    private const MAX_MEMORY_LIMIT_BYTES = 1536 * 1024 * 1024;

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

        $previousMemoryLimit = $this->reserveMemory($width, $height);

        if ($previousMemoryLimit === null) {
            return null;
        }

        try {
            return $this->shrink($sourcePath, $mime, $kind, $maxDimension);
        } finally {
            if ($previousMemoryLimit !== (string) ini_get('memory_limit')) {
                ini_set('memory_limit', $previousMemoryLimit);
            }
        }
    }

    private function shrink(string $sourcePath, string $mime, ImageKind $kind, ?int $maxDimension): ?OptimizedImage
    {
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
            unset($image);
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

    /**
     * Makes sure decoding an image of this size fits in memory, raising the limit
     * for the current request when it has to. Returns the limit to restore
     * afterwards, or null when the image cannot be decoded safely.
     */
    private function reserveMemory(int $width, int $height): ?string
    {
        $current = (string) ini_get('memory_limit');
        $limit = self::iniBytes($current);
        $needed = memory_get_usage(true) + $width * $height * self::BYTES_PER_PIXEL + self::DECODE_OVERHEAD_BYTES;

        if ($limit < 0 || $needed <= $limit) {
            return $current;
        }

        if ($needed > self::MAX_MEMORY_LIMIT_BYTES) {
            return null;
        }

        $raised = ini_set('memory_limit', (string) (int) ceil($needed / (1024 * 1024)).'M');

        return $raised === false ? null : $current;
    }

    /** Bytes for a php.ini size such as "512M", "1G" or "-1" (unlimited, returned negative). */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtoupper(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
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
        } elseif (self::supportsWebp() && ! $kind->forPrint()) {
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
