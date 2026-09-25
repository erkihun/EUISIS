<?php

declare(strict_types=1);

namespace App\Enums\Performance;

enum GoalAllocationType: string
{
    case Primary = 'PRIMARY';
    case Shared = 'SHARED';
    case Supporting = 'SUPPORTING';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
