<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Kinds of configurable reason code.
 */
enum GrievanceReasonCodeType: string
{
    case IntakeReturn = 'intake_return';
    case IntakeRejection = 'intake_rejection';
    case Withdrawal = 'withdrawal';
    case Closure = 'closure';
    case Reopen = 'reopen';
    case Reassignment = 'reassignment';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
