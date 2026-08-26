<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Generates a QR code for a given verification URL and returns it as a
 * base64-encoded PNG data URI (via GD) or an SVG string for embedding.
 *
 * The QR payload must be a safe, tokenized reference URL — never the raw
 * token hash or any PII.
 */
final class IdCardQrCodeRenderer
{
    /**
     * Returns a base64 data URI (SVG) suitable for use in an SVG <image> element.
     * Falls back to an empty string on error.
     *
     * @param  int  $pixelSize  Desired pixel side length of the QR image
     */
    public function asSvgDataUri(string $url, int $pixelSize = 96): string
    {
        try {
            $options = new QROptions;
            $options->outputInterface = QRMarkupSVG::class;
            $options->eccLevel = 'M';
            $options->svgAddXmlHeader = false;
            $options->drawLightModules = true;
            $options->connectPaths = true;
            /*
             * The library base64-encodes its output into a data URI by default.
             * This method builds its own data URI around the markup, so leaving
             * that on nests a data: string inside the <svg> wrapper as plain
             * text and the code renders as an empty white box.
             */
            $options->outputBase64 = false;

            $svg = (new QRCode($options))->render($url);

            /*
             * The generated <svg> carries its own viewBox sized to the module
             * count, so the wrapper only sets the pixel dimensions and lets the
             * inner element scale itself. Forcing a viewBox here would crop the
             * code, since the module count varies with the URL's length.
             */
            $wrapped = sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
                .'width="%d" height="%d">%s</svg>',
                $pixelSize,
                $pixelSize,
                $svg,
            );

            return 'data:image/svg+xml;base64,'.base64_encode($wrapped);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Returns the raw SVG string for inline embedding inside another SVG.
     * The returned string is the inner content (no <?xml?> header).
     */
    public function asInlineSvgContent(string $url): string
    {
        try {
            $options = new QROptions;
            $options->outputInterface = QRMarkupSVG::class;
            $options->eccLevel = 'M';
            $options->svgAddXmlHeader = false;
            $options->drawLightModules = true;
            $options->connectPaths = true;
            // Callers embed this inside another SVG, so it must be real markup
            // rather than the library's default base64 data URI.
            $options->outputBase64 = false;

            return (new QRCode($options))->render($url);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Returns raw PNG bytes for download, or an empty string on error.
     *
     * Used by the feedback QR export, where the caller needs a real image file
     * to save or place in a print layout rather than an inline data URI.
     *
     * `$scale` is the pixel size of a single QR module, so the final image is
     * roughly (module count x scale) square.
     */
    public function asPngBytes(string $url, int $scale = 10): string
    {
        try {
            $options = new QROptions;
            $options->outputInterface = QRGdImagePNG::class;
            $options->eccLevel = 'M';
            $options->scale = $scale;
            $options->outputBase64 = false;
            $options->drawLightModules = true;

            return (string) (new QRCode($options))->render($url);
        } catch (\Throwable) {
            return '';
        }
    }
}
