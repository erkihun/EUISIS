<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a task standard version.
 *
 * Only an APPROVED version measures daily work. An approved version is never
 * edited; a change is a new version. RETIRED stops a version measuring new
 * work without touching items already measured against it.
 */
enum TaskStandardStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Retired = 'retired';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
