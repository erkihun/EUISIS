<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * Lifecycle of a conflict-of-interest declaration.
 */
enum GrievanceRecusalStatus: string
{
    case Declared = 'declared';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
