<?php

declare(strict_types=1);

namespace App\Enums\Assessment;

/**
 * Predefined scoring strategies (docs/assessment-scoring.md). There is no
 * administrator-written formula: each method is code, reviewed and tested.
 */
enum ScoringMethod: string
{
    case RawScore = 'raw_score';
    case PercentOfMax = 'percent_of_max';
    case WeightedScore = 'weighted_score';
    case ContributionWeight = 'contribution_weight';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
