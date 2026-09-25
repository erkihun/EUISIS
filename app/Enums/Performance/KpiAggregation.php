<?php

declare(strict_types=1);

namespace App\Enums\Performance;

/**
 * How actuals combine across periods and from child contributors (see KpiAggregationService).
 */
enum KpiAggregation: string
{
    case Sum = 'SUM';
    case Average = 'AVERAGE';
    case WeightedAverage = 'WEIGHTED_AVERAGE';
    case RatioFromTotals = 'RATIO_FROM_TOTALS';
    case LatestValue = 'LATEST_VALUE';
    case Min = 'MIN';
    case Max = 'MAX';
    case Milestone = 'MILESTONE';
    case NoAggregation = 'NO_AGGREGATION';
    case CustomFormula = 'CUSTOM_FORMULA';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
