<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * When a stage's SLA clock starts (configurable per SLA profile).
 */
enum GrievanceSlaStartPoint: string
{
    case OnAssignment = 'on_assignment';
    case OnReceipt = 'on_receipt';
    case OnAcceptance = 'on_acceptance';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
