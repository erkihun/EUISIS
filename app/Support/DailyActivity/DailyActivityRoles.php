<?php

declare(strict_types=1);

namespace App\Support\DailyActivity;

/**
 * Permission sets for the Daily Activity roles.
 *
 * Shared by RoleSeeder and the permission-registration migration so the two
 * cannot drift apart.
 */
final class DailyActivityRoles
{
    /** Ordinary employees, who self-register with no role at all. */
    public const EMPLOYEE_ROLE = 'Employee';

    public const REVIEWER_ROLE = 'Daily Activity Reviewer';

    /** @var array<int, string> */
    public const EMPLOYEE_PERMISSIONS = [
        'daily_activities.view_own',
        'daily_activities.create',
        'daily_activities.update_draft',
        'daily_activities.submit',
        'daily_activities.resubmit',
    ];

    /**
     * Review authority. Holding these is necessary but not sufficient: the
     * reviewer must also be assigned to the employee's unit or organization.
     *
     * @var array<int, string>
     */
    public const REVIEWER_PERMISSIONS = [
        'daily_activities.view_team',
        'daily_activities.review',
        'daily_activities.approve',
        'daily_activities.return_for_correction',
    ];

    /** @var array<int, string> */
    public const HR_OVERSIGHT_PERMISSIONS = [
        'daily_activities.view_scoped',
        'daily_activities.view_reports',
        'daily_activities.export',
    ];

    /** @var array<int, string> */
    public const ORGANIZATIONAL_ADMIN_PERMISSIONS = [
        'daily_activities.view_scoped',
        'daily_activities.view_reports',
        'daily_activities.export',
        'daily_activities.reopen',
        'daily_activities.manage_reviewers',
        'daily_activity_settings.view',
    ];
}
