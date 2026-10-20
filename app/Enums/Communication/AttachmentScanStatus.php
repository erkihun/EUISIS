<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** Malware scan state of an uploaded file. A file is downloadable only when CLEAN or SKIPPED (scanning disabled). */
enum AttachmentScanStatus: string
{
    case Pending = 'PENDING';
    case Clean = 'CLEAN';
    case Infected = 'INFECTED';
    case Skipped = 'SKIPPED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isDownloadable(): bool
    {
        return in_array($this, [self::Clean, self::Skipped], true);
    }
}
