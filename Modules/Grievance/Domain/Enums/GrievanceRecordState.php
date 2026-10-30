<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Retention state of a case record. Submitted cases are never hard-deleted.
 */
enum GrievanceRecordState: string
{
    case Active = 'active';
    case Closed = 'closed';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
