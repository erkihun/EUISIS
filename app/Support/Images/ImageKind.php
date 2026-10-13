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

    /** Longest side after optimisation, in pixels. */
    public function maxDimension(): int
    {
        return match ($this) {
            self::Logo => 512,
            self::Favicon => 256,
            self::Photo => 1024,
        };
    }
}
