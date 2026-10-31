<?php

declare(strict_types=1);

namespace Modules\Performance\Domain\Enums;

enum AmendmentStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
