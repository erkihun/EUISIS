<?php

declare(strict_types=1);

namespace App\Enums;

enum CafeteriaUsageMode: string
{
    case SingleDay = 'single_day';
    case UseRemainingWeek = 'use_remaining_week';

    public function label(): string
    {
        return match ($this) {
            self::SingleDay => __('cafeteria.usageModeSingleDay'),
            self::UseRemainingWeek => __('cafeteria.usageModeRemainingWeek'),
        };
    }
}
