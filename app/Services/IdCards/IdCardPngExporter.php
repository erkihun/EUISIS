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
    // Physical card at 10px/mm = 856x540; x2 for retina/print quality
    private const EXPORT_W = 1712;

    private const EXPORT_H = 1080;

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
            $im = new \Imagick;
            $im->setBackgroundColor(new \ImagickPixel('white'));
            $im->setResolution(144, 144); // 72dpi x2 for print quality
            $im->readImage('svg:'.$tmpSvg);
            $im->setImageFormat('png');
            $document = new \DOMDocument;
            $document->loadXML($svgData, LIBXML_NONET);
            $width = (int) ($document->documentElement?->getAttribute('width') ?: self::EXPORT_W / 2) * 2;
            $height = (int) ($document->documentElement?->getAttribute('height') ?: self::EXPORT_H / 2) * 2;
            $im->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
            $im->flattenImages();
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);

            $png = $im->getImageBlob();
            $im->clear();

            return $png;
        } catch (\ImagickException $e) {
            throw new RuntimeException('Imagick conversion failed: '.$e->getMessage(), 0, $e);
        } finally {
            @unlink($tmpSvg);
        }
    }
}
