<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much of an organization's submitted daily activity needs a reviewer.
 *
 *   inherit    follow the city-wide "Manager Review Required" setting
 *   all        every submitted day waits for review
 *   none       submitted days need no review (reviewers may still act)
 *   late_only  only late days, and days resubmitted after a correction
 */
enum DailyActivityReviewMode: string
{
    case Inherit = 'inherit';
    case All = 'all';
    case None = 'none';
    case LateOnly = 'late_only';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
