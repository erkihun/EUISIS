<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Disciplinary referral lifecycle. A referral never becomes a disciplinary case by itself.
 */
enum GrievanceReferralStatus: string
{
    case Referred = 'referred';
    case Acknowledged = 'acknowledged';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
