<?php

declare(strict_types=1);

namespace App\Enums\Performance;

enum KpiMeasurementType: string
{
    case Count = 'COUNT';
    case Percentage = 'PERCENTAGE';
    case Ratio = 'RATIO';
    case Average = 'AVERAGE';
    case Duration = 'DURATION';
    case Currency = 'CURRENCY';
    case Boolean = 'BOOLEAN';
    case Milestone = 'MILESTONE';
    case Score = 'SCORE';
    case QualitativeRating = 'QUALITATIVE_RATING';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
