<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Why a case moved to a stage. Appeals (the employee challenges a decision) and
 * timeout escalations (the handler did not decide in time) stay separate for reporting.
 */
enum GrievanceMovementType: string
{
    case InitialAssignment = 'initial_assignment';
    case TimeoutEscalation = 'timeout_escalation';
    case EmployeeAppeal = 'employee_appeal';
    case Referred = 'referred';
    case Returned = 'returned';
    case Reassigned = 'reassigned';
    case ManualEscalation = 'manual_escalation';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
