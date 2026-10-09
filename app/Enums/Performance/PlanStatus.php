<?php

declare(strict_types=1);

namespace App\Enums\Performance;

/**
 * A published plan is never edited in place: changes create a new version and the old one becomes SUPERSEDED.
 */
enum PlanStatus: string
{
    case Draft = 'DRAFT';
    case UnderReview = 'UNDER_REVIEW';
    case Approved = 'APPROVED';
    case Published = 'PUBLISHED';
    case Superseded = 'SUPERSEDED';
    case Closed = 'CLOSED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
