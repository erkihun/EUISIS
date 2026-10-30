<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Committee lifecycle. EPMS panels read `active` only.
 */
enum GrievanceCommitteeStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Inactive = 'inactive';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
