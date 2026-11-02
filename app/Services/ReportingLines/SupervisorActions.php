<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

/**
 * The supervisor actions a delegation can carry (ADR 0025). Each names a
 * thing a direct supervisor may do over their reports; the workflow that
 * owns the action still checks its own permission, scope and state. A
 * delegation never grants an action that is not on this list.
 */
final class SupervisorActions
{
    /** See the team: direct and indirect reports, their supervisor pages. */
    public const VIEW_TEAM = 'view_team';

    /** Approve, return or reject a field work request of a direct report. */
    public const FIELD_WORK_DECIDE = 'field_work.decide';

    /** Review and return a direct report's daily activity. */
    public const DAILY_ACTIVITY_REVIEW = 'daily_activities.review';

    /** Act as the line manager in EPMS agreements, check-ins and reviews. */
    public const PERFORMANCE_MANAGE = 'performance.manage';

    /** Act as the direct-manager evaluator in assessments. */
    public const ASSESSMENT_EVALUATE = 'assessments.evaluate';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW_TEAM, self::FIELD_WORK_DECIDE, self::DAILY_ACTIVITY_REVIEW, self::PERFORMANCE_MANAGE, self::ASSESSMENT_EVALUATE];
    }

    public static function isKnown(string $action): bool
    {
        return in_array($action, self::all(), true);
    }

    /** Actions that an indirect (higher) supervisor may exercise too; the rest are the direct supervisor's alone. */
    public static function allowsIndirect(string $action): bool
    {
        return $action === self::VIEW_TEAM;
    }

    public static function label(string $action): string
    {
        return (string) __('reporting-lines.actions.'.str_replace('.', '_', $action));
    }
}
