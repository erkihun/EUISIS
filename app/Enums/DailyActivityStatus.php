<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one employee's daily activity log (one row per employee per
 * work date).
 *
 * The status is the single source of truth for both submission and review:
 * there is deliberately no separate review_status column that could drift out
 * of step with it.
 *
 * APPROVED is immutable. The only way back is a formal reopen, which returns
 * the log to RETURNED_FOR_CORRECTION with a recorded reason and actor.
 */
enum DailyActivityStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ReturnedForCorrection = 'returned_for_correction';
    case Resubmitted = 'resubmitted';
    case Approved = 'approved';

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted, self::Resubmitted => [self::UnderReview, self::ReturnedForCorrection, self::Approved],
            self::UnderReview => [self::ReturnedForCorrection, self::Approved],
            self::ReturnedForCorrection => [self::Resubmitted],
            // Reopen is the only exit, and it is a separately authorised act.
            self::Approved => [self::ReturnedForCorrection],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** The employee may still change the activity items. */
    public function isEmployeeEditable(): bool
    {
        return in_array($this, [self::Draft, self::ReturnedForCorrection], true);
    }

    /** Sitting with a reviewer, awaiting a decision. */
    public function isAwaitingReview(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::Resubmitted], true);
    }

    /**
     * Counts as "the employee has submitted this day". A draft never does,
     * and neither does a returned log until it is resubmitted.
     */
    public function countsAsSubmitted(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::Resubmitted, self::Approved], true);
    }

    /** @return array<int, string> */
    public static function submittedValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->countsAsSubmitted()),
        ));
    }

    /** @return array<int, string> */
    public static function awaitingReviewValues(): array
    {
        return [self::Submitted->value, self::UnderReview->value, self::Resubmitted->value];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
