<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Dispatch status. `delivered` only when the provider confirms it.
 */
enum GrievanceDispatchStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Acknowledged = 'acknowledged';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
