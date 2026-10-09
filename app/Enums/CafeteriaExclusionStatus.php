<?php

declare(strict_types=1);

namespace App\Enums;

enum CafeteriaExclusionStatus: string
{
    case Active    = 'active';
    case Ended     = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active    => __('cafeteria.exclusionStatusActive'),
            self::Ended     => __('cafeteria.exclusionStatusEnded'),
            self::Cancelled => __('cafeteria.exclusionStatusCancelled'),
        };
    }
}
