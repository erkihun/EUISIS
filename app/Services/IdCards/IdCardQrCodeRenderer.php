<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use chillerlan\QRCode\Data\QRCodeDataException;
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
 *
 * Two families of method live here:
 *  - the general ones (asSvgDataUri, asInlineSvgContent, asPngBytes) let the
 *    library pick a symbol version, and are used by the employee feedback QR;
 *  - the idCard* ones pin the symbol to the card specification below.
 */
final class IdCardQrCodeRenderer
{
    public function __construct(private readonly QrCodeVersionResolver $versionResolver) {}

    /**
     * The symbol a payload will be rendered with, without rendering it.
     *
     * Exposed so a preview can report the resolved version and a caller can
     * record it against the card.
     *
     * @return array{version: int, ecc: string, model: int, capacity_bits: int, required_bits: int}
     */
    public function resolveSymbol(string $url): array
    {
        return $this->versionResolver->resolve($url);
    }

    /**
     * Options for the QR printed on a physical ID card.
     *
     * Model 2 (the only model this library produces), error correction Q, and
     * the smallest version that holds the payload at or above the configured
     * floor. The version is resolved explicitly rather than left to
     * Version::AUTO so the choice is bounded by the configured ceiling and can
     * be recorded against the card.
     *
     * @param  array{version: int, ecc: string, model: int, capacity_bits: int, required_bits: int}  $symbol
     */
    private function idCardOptions(array $symbol): QROptions
    {
        $options = new QROptions;
        $options->version = $symbol['version'];
        $options->eccLevel = $this->versionResolver->eccOrdinal();
        $options->svgAddXmlHeader = false;
        $options->drawLightModules = true;
        $options->connectPaths = true;
        $options->outputBase64 = false;

        return $options;
    }

    /**
     * The card's QR as an SVG data URI, pinned to the card specification.
     *
     * @param  int  $pixelSize  Desired pixel side length of the QR image
     *
     * @throws IdCardQrPayloadTooLongException when no allowed version fits
     * @throws QrPayloadContainsPiiException when the payload carries employee data
     */
    public function idCardSvgDataUri(string $url, int $pixelSize = 96): string
    {
        $svg = $this->renderIdCardSvg($url);

        /*
         * The generated <svg> carries its own viewBox sized to the module
         * count, so the wrapper only sets the pixel dimensions and lets the
         * inner element scale itself.
         */
        $wrapped = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
            .'width="%d" height="%d">%s</svg>',
            $pixelSize,
            $pixelSize,
            $svg,
        );

        return 'data:image/svg+xml;base64,'.base64_encode($wrapped);
    }

    /**
     * The card's QR as raw PNG bytes, pinned to the card specification.
     *
     * `$scale` is the pixel size of a single QR module.
     *
     * @throws IdCardQrPayloadTooLongException when no allowed version fits
     * @throws QrPayloadContainsPiiException when the payload carries employee data
     */
    public function idCardPngBytes(string $url, int $scale = 10): string
    {
        $symbol = $this->resolveSymbol($url);
        $options = $this->idCardOptions($symbol);
        $options->outputInterface = QRGdImagePNG::class;
        $options->scale = $scale;

        return (string) $this->render($options, $url, $symbol);
    }

    /**
     * The card's QR as markup for embedding inside another SVG.
     *
     * @throws IdCardQrPayloadTooLongException when no allowed version fits
     * @throws QrPayloadContainsPiiException when the payload carries employee data
     */
    public function idCardInlineSvgContent(string $url): string
    {
        return $this->renderIdCardSvg($url);
    }

    /** @throws IdCardQrPayloadTooLongException */
    private function renderIdCardSvg(string $url): string
    {
        $symbol = $this->resolveSymbol($url);
        $options = $this->idCardOptions($symbol);
        $options->outputInterface = QRMarkupSVG::class;

        return (string) $this->render($options, $url, $symbol);
    }

    /**
     * Render with the resolved symbol.
     *
     * The resolver has already rejected a payload that cannot fit, so an
     * overflow here means the estimate and the library disagreed; it is
     * reported with the same error rather than silently producing no code.
     *
     * Deliberately not caught and blanked like the general methods: a card
     * whose QR silently failed would be printed and issued unscannable.
     *
     * @param  array{version: int, ecc: string, model: int, capacity_bits: int, required_bits: int}  $symbol
     *
     * @throws IdCardQrPayloadTooLongException
     */
    private function render(QROptions $options, string $url, array $symbol): mixed
    {
        try {
            return (new QRCode($options))->render($url);
        } catch (QRCodeDataException) {
            throw IdCardQrPayloadTooLongException::forPayload(
                $url,
                $symbol['required_bits'],
                $symbol['capacity_bits'],
                $symbol['version'],
                $symbol['ecc'],
            );
        }
    }

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
