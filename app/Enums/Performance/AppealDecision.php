<?php

declare(strict_types=1);

namespace App\Enums\Performance;

enum AppealDecision: string
{
    case Upheld = 'UPHELD';
    case PartiallyUpheld = 'PARTIALLY_UPHELD';
    case Rejected = 'REJECTED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
