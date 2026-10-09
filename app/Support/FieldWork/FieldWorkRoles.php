<?php

declare(strict_types=1);

namespace App\Support\FieldWork;

/**
 * Permission sets for the default roles that carry Field Work duties.
 *
 * Shared by DefaultRoleMatrix and the permission-registration migration so
 * the two cannot drift apart. Holding a supervisor permission is necessary
 * but not sufficient: the user must also be the resolved immediate
 * supervisor of the request (FieldWorkSupervisorResolver).
 *
 * Exact GPS coordinates are granted to no default operational role; see
 * docs/field-work-security.md (NEEDS_DECISION: who may see them).
 */
final class FieldWorkRoles
{
    /** @var array<int, string> */
    public const EMPLOYEE_PERMISSIONS = [
        'field_work.view_own',
        'field_work.create_own',
        'field_work.create_team',
        'field_work.edit_own_draft',
        'field_work.submit_own',
        'field_work.cancel_own',
        'field_work.check_in',
        'field_work.check_out',
        'field_work.complete',
        'field_work.location.view_status',
    ];

    /** Line managers: the Daily Activity Reviewer and EPMS Manager roles. */
    public const SUPERVISOR_PERMISSIONS = [
        'field_work.view_team',
        'field_work.approve',
        'field_work.return',
        'field_work.reject',
        'field_work.location.view_status',
    ];

    /** @var array<int, string> */
    public const HR_OVERSIGHT_PERMISSIONS = [
        'field_work.view_org',
        'field_work.location.view_status',
    ];

    /** @var array<int, string> */
    public const ORGANIZATIONAL_ADMIN_PERMISSIONS = [
        'field_work.view_org',
        'field_work.location.view_status',
    ];

    /**
     * Withheld from City Admin / Public Service Bureau Admin: exact employee
     * location is personal data, not an operational necessity.
     *
     * @var array<int, string>
     */
    public const CITY_ADMIN_WITHHELD = [
        'field_work.location.view_precise',
        // GPS enforcement policy is a security decision (System Settings).
        'system-settings.manageFieldWorkGps',
    ];
}
