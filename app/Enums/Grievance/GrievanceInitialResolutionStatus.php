<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Lifecycle of the pre-grievance initial-resolution attempt (Regulation
 * 201/2018 Art. 33(1): the employee must raise the matter in writing and
 * attempt resolution before a formal grievance may reach the committee).
 */
enum GrievanceInitialResolutionStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Failed;
    }
}
