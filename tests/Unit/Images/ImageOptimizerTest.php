<?php

declare(strict_types=1);

namespace Tests\Unit\Images;

use App\Support\Images\ImageKind;
use App\Support\Images\ImageOptimizer;
use PHPUnit\Framework\TestCase;

class ImageOptimizerTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_large_transparent_logo_is_downscaled_and_keeps_its_transparency(): void
    {
        $source = $this->png(1600, 1200, transparent: true);

        $result = (new ImageOptimizer)->optimize($source, ImageKind::Logo);

        $this->assertNotNull($result);
        $this->tempFiles[] = $result->path;
        $this->assertSame(512, max($result->width, $result->height));
        $this->assertSame(ImageOptimizer::supportsWebp() ? 'webp' : 'png', $result->extension);
        $this->assertLessThan(filesize($source), $result->bytes);

        $decoded = $result->extension === 'webp' ? imagecreatefromwebp($result->path) : imagecreatefrompng($result->path);
        $this->assertNotFalse($decoded);
        $this->assertSame(512, imagesx($decoded));
        // The top-left pixel was fully transparent in the source and must still be.
        $this->assertSame(127, (imagecolorat($decoded, 0, 0) >> 24) & 0x7F);
    }

    public function test_a_photograph_is_downscaled_to_the_photo_limit(): void
    {
        $source = $this->jpeg(3000, 2000);

        $result = (new ImageOptimizer)->optimize($source, ImageKind::Photo);

        $this->assertNotNull($result);
        $this->tempFiles[] = $result->path;
        $this->assertSame(1024, $result->width);
        $this->assertSame(683, $result->height);
        $this->assertContains($result->extension, ['webp', 'jpg']);
    }

    public function test_a_favicon_stays_png_and_is_shrunk_to_256_pixels(): void
    {
        $source = $this->png(1024, 1024, transparent: true);

        $result = (new ImageOptimizer)->optimize($source, ImageKind::Favicon);

        $this->assertNotNull($result);
        $this->tempFiles[] = $result->path;
        $this->assertSame('png', $result->extension);
        $this->assertSame([256, 256], [$result->width, $result->height]);
    }

    public function test_an_image_that_is_already_small_and_compact_is_left_alone(): void
    {
        $source = $this->png(16, 16, transparent: false);

        $result = (new ImageOptimizer)->optimize($source, ImageKind::Logo);

        if ($result !== null) {
            $this->tempFiles[] = $result->path;
            // Re-encoding is only kept when it is actually smaller.
            $this->assertLessThan(filesize($source), $result->bytes);
        } else {
            $this->assertNull($result);
        }
    }

    public function test_files_gd_cannot_read_are_left_untouched(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'not-an-image-');
        $this->tempFiles[] = $source;
        file_put_contents($source, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->assertNull((new ImageOptimizer)->optimize($source, ImageKind::Logo));
        $this->assertNull((new ImageOptimizer)->optimize('/definitely/missing.png', ImageKind::Photo));
    }

    private function png(int $width, int $height, bool $transparent): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        if ($transparent) {
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            // An opaque disc in the middle, so the file has real content to encode.
            imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), intdiv($width, 2), intdiv($height, 2), imagecolorallocate($image, 18, 33, 112));
        } else {
            imagefill($image, 0, 0, imagecolorallocate($image, 18, 33, 112));
        }

        $path = tempnam(sys_get_temp_dir(), 'png-').'.png';
        imagepng($image, $path, 6);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        // A gradient with noise compresses like a photograph rather than a flat fill.
        for ($y = 0; $y < $height; $y += 8) {
            for ($x = 0; $x < $width; $x += 8) {
                imagefilledrectangle($image, $x, $y, $x + 7, $y + 7, imagecolorallocate($image, intdiv($x * 255, $width), intdiv($y * 255, $height), random_int(0, 255)));
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'jpg-').'.jpg';
        imagejpeg($image, $path, 95);
        $this->tempFiles[] = $path;

        return $path;
    }
}
