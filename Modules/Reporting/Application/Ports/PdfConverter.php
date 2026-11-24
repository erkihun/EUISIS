<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

/**
 * Optional conversion of a generated Word file to PDF for preview. When no
 * converter is configured the module works without it: Word submission and
 * download never depend on this port.
 */
interface PdfConverter
{
    public function isAvailable(): bool;

    /** @throws \RuntimeException when the conversion fails */
    public function convert(string $wordPath, string $pdfPath): void;
}
