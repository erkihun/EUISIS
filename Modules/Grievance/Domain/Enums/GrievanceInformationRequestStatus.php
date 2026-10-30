<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * Information request lifecycle.
 */
enum GrievanceInformationRequestStatus: string
{
    case Open = 'open';
    case Responded = 'responded';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
