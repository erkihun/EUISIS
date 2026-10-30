<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Evidence lifecycle. Accepted evidence is never replaced in place.
 */
enum GrievanceEvidenceStatus: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
