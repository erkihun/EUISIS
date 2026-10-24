<?php

declare(strict_types=1);

namespace App\Enums;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** How a calculated FTE becomes a headcount (D-7). `manual` leaves the decision to the planner. */
enum RoundingRule: string
{
    case Ceil = 'ceil';
    case Floor = 'floor';
    case HalfUp = 'half_up';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function apply(BigDecimal $fte): ?int
    {
        return match ($this) {
            self::Ceil => $fte->toScale(0, RoundingMode::Ceiling)->toInt(),
            self::Floor => $fte->toScale(0, RoundingMode::Floor)->toInt(),
            self::HalfUp => $fte->toScale(0, RoundingMode::HalfUp)->toInt(),
            self::Manual => null,
        };
    }
}
