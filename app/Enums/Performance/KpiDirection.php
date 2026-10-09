<?php

declare(strict_types=1);

namespace App\Enums\Performance;

/**
 * How achievement is computed from target and actual (see KpiAchievementCalculator).
 */
enum KpiDirection: string
{
    case HigherIsBetter = 'HIGHER_IS_BETTER';
    case LowerIsBetter = 'LOWER_IS_BETTER';
    case TargetIsBest = 'TARGET_IS_BEST';
    case Binary = 'BINARY';
    case Milestone = 'MILESTONE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
