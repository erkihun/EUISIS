<?php

declare(strict_types=1);

namespace App\Enums\Performance;

enum StrategicGoalStatus: string
{
    case Draft = 'DRAFT';
    case UnderReview = 'UNDER_REVIEW';
    case Approved = 'APPROVED';
    case Published = 'PUBLISHED';
    case Superseded = 'SUPERSEDED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
