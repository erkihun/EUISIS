<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Employee appeal lifecycle.
 */
enum GrievanceAppealStatus: string
{
    case Submitted = 'submitted';
    case Routed = 'routed';
    case Withdrawn = 'withdrawn';
    case Decided = 'decided';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
