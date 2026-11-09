<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * Lifecycle of an SLA pause request.
 */
enum GrievanceSlaPauseStatus: string
{
    case Requested = 'requested';
    case Active = 'active';
    case Ended = 'ended';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
