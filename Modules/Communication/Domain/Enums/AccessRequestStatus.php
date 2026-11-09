<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Enums;

/** Break-glass access lifecycle. Only an approved request inside its window grants anything. */
enum AccessRequestStatus: string
{
    case Requested = 'REQUESTED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
