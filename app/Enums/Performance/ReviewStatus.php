<?php

declare(strict_types=1);

namespace App\Enums\Performance;

enum ReviewStatus: string
{
    case Draft = 'DRAFT';
    case EmployeeSubmitted = 'EMPLOYEE_SUBMITTED';
    case ManagerReview = 'MANAGER_REVIEW';
    case Returned = 'RETURNED';
    case Completed = 'COMPLETED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
