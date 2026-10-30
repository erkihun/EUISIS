<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * Letter lifecycle. Issued letters are immutable; corrections void and re-issue.
 */
enum GrievanceLetterStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Signed = 'signed';
    case Issued = 'issued';
    case Voided = 'voided';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
