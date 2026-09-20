<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use RuntimeException;

/**
 * Converts an SVG string to a PNG binary using the Imagick extension.
 *
 * If Imagick is not available a RuntimeException is thrown so the caller
 * can return an appropriate error response.
 */
final class IdCardPngExporter
{
    /**
     * Print resolution for exported cards. 300 DPI is the card-printing
     * standard: an 85.6 mm card becomes 1011 px, which holds the QR and the
     * Ethiopic text cleanly. The PNG carries this density, so printing it
     * reproduces the card's real millimetres.
     */
    public const EXPORT_DPI = 300;

    /** ISO/IEC 7810 ID-1, used only when the SVG declares no physical size. */
    private const FALLBACK_W_MM = 85.6;

    private const FALLBACK_H_MM = 54.0;

    public function isAvailable(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * Convert an SVG string to PNG binary at 2x resolution.
     *
     * @throws RuntimeException when Imagick is not available or conversion fails
     */
    public function svgToPng(string $svg): string
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'PNG export requires the Imagick PHP extension. '
                .'Please install it and restart the web server.',
            );
        }

        // RSVG reads from a file more reliably than from a blob on Windows.
        // Strip the XML declaration which some RSVG versions reject.
        $svgData = preg_replace('/^<\?xml[^?]*\?>\s*/s', '', $svg) ?? $svg;
        $tmpSvg = tempnam(sys_get_temp_dir(), 'idcard_');
        file_put_contents($tmpSvg, $svgData);

        try {
            $document = new \DOMDocument;
            $document->loadXML($svgData, LIBXML_NONET);
            $widthMm = $this->millimetres($document->documentElement?->getAttribute('width'), self::FALLBACK_W_MM);
            $heightMm = $this->millimetres($document->documentElement?->getAttribute('height'), self::FALLBACK_H_MM);
            $width = $this->pixelsForMillimetres($widthMm);
            $height = $this->pixelsForMillimetres($heightMm);

            $im = new \Imagick;
            $im->setBackgroundColor(new \ImagickPixel('white'));
            // Rasterise straight at the target resolution rather than drawing
            // small and scaling up, so the output is sharp.
            $im->setResolution(self::EXPORT_DPI, self::EXPORT_DPI);
            $im->readImage('svg:'.$tmpSvg);
            $im->setImageFormat('png');
            if ($im->getImageWidth() !== $width || $im->getImageHeight() !== $height) {
                $im->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
            }
            $im->flattenImages();
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            // Recorded in the file, so a printer reproduces the real size.
            $im->setImageUnits(\Imagick::RESOLUTION_PIXELSPERINCH);
            $im->setImageResolution(self::EXPORT_DPI, self::EXPORT_DPI);

            $png = $im->getImageBlob();
            $im->clear();

            return $png;
        } catch (\ImagickException $e) {
            throw new RuntimeException('Imagick conversion failed: '.$e->getMessage(), 0, $e);
        } finally {
            @unlink($tmpSvg);
        }
    }

    /**
     * Reads an SVG length as millimetres. The renderer writes "85.6mm"; a bare
     * number is the older pixel form at the canvas's 10 px per mm.
     */
    public function millimetres(?string $length, float $fallback): float
    {
        $length = trim((string) $length);
        if ($length === '') {
            return $fallback;
        }
        if (str_ends_with($length, 'mm')) {
            $value = (float) substr($length, 0, -2);

            return $value > 0 ? $value : $fallback;
        }

        $pixels = (float) $length;

        return $pixels > 0 ? $pixels / 10 : $fallback;
    }

    /** Pixels needed to print a length at the export resolution. */
    public function pixelsForMillimetres(float $mm): int
    {
        return max(1, (int) round($mm / 25.4 * self::EXPORT_DPI));
    }
}
