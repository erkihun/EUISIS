<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** Moderation status of a report. */
enum ReportStatus: string
{
    case Open = 'OPEN';
    case UnderReview = 'UNDER_REVIEW';
    case Actioned = 'ACTIONED';
    case Dismissed = 'DISMISSED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
