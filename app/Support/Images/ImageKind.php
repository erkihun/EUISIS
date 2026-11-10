<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * What an uploaded raster image is for, which decides how far it is shrunk
 * and how it is encoded (ImageOptimizer).
 */
enum ImageKind: string
{
    /** Institution and system logos, seals: shown at most ~180 px wide, printed small on ID cards. */
    case Logo = 'logo';

    /** Browser tab icon. */
    case Favicon = 'favicon';

    /** Employee and user photographs. */
    case Photo = 'photo';

    /** Emblems and stamps printed on letters: kept as PNG or JPEG, the only formats the PDF engine embeds. */
    case Print = 'print';

    /** A picture printed across the whole width of a letter (a header or footer drawn as one image). */
    case PrintWide = 'print_wide';

    /** Whether the result must stay PNG or JPEG, the only formats the PDF engine embeds. */
    public function forPrint(): bool
    {
        return $this === self::Print || $this === self::PrintWide;
    }

    /** Longest side after optimisation, in pixels. */
    public function maxDimension(): int
    {
        return match ($this) {
            self::Logo => 512,
            self::Favicon => 256,
            self::Photo => 1024,
            // 600 px is about 300 dpi at 50 mm, the largest an emblem prints.
            self::Print => 600,
            // 1,600 px is about 230 dpi across the 174 mm between the margins of an A4 page.
            self::PrintWide => 1600,
        };
    }
}
