<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Compliance;

use DateTimeImmutable;
use Modules\Reporting\Domain\Enums\Timeliness;

/**
 * Timeliness of the first valid submission against one deadline.
 *
 * - On time: first submission at or before the deadline (the exact deadline
 *   instant is on time).
 * - Late: first submission after the deadline.
 * - Overdue: the deadline has passed and nothing was submitted.
 *
 * Drafts and uploaded files are not submissions; review delay, returns and
 * resubmissions never enter this rule because only the FIRST submission does.
 */
final class TimelinessRule
{
    public static function of(?DateTimeImmutable $firstSubmittedAt, DateTimeImmutable $dueAt, DateTimeImmutable $asOf): Timeliness
    {
        if ($firstSubmittedAt !== null && $firstSubmittedAt > $asOf) {
            $firstSubmittedAt = null;
        }
        if ($firstSubmittedAt !== null) {
            if ($firstSubmittedAt > $dueAt) {
                return Timeliness::Late;
            }

            return $dueAt > $asOf ? Timeliness::Early : Timeliness::OnTime;
        }

        return $dueAt > $asOf ? Timeliness::NotDue : Timeliness::Overdue;
    }

    /** Whole days past the deadline: until the first submission, or until as-of when there is none. Zero when not late. */
    public static function daysOverdue(?DateTimeImmutable $firstSubmittedAt, DateTimeImmutable $dueAt, DateTimeImmutable $asOf): int
    {
        $end = $firstSubmittedAt !== null && $firstSubmittedAt <= $asOf ? $firstSubmittedAt : $asOf;
        $seconds = $end->getTimestamp() - $dueAt->getTimestamp();

        return $seconds <= 0 ? 0 : (int) ceil($seconds / 86400);
    }
}
