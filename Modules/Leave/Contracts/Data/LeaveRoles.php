<?php

declare(strict_types=1);

namespace Modules\Leave\Contracts\Data;

/**
 * Permission sets for the default roles that carry leave duties (ADR 0039).
 *
 * Shared by DefaultRoleMatrix and the permission catalog so the two cannot
 * drift apart. A permission alone is never enough: an employee acts on their
 * own leave only, a supervisor on the requests routed to them, and an HR
 * officer inside their organization scope.
 */
final class LeaveRoles
{
    /** @var array<int, string> */
    public const EMPLOYEE_PERMISSIONS = [
        'leave.view_own',
        'leave.request_own',
        'leave.cancel_own',
    ];

    /** Line managers: the Daily Activity Reviewer and EPMS Manager roles. */
    public const SUPERVISOR_PERMISSIONS = [
        'leave.view_team',
        'leave.approve',
    ];

    /** HR officers of an institution: register, confirm, end early, read the evidence. */
    public const HR_PERMISSIONS = [
        'leave.view_org',
        'leave.manage',
        'leave.view_medical',
        'leave.adjust_balance',
        'leave.reports.view',
    ];

    /** Organizational administrators and the head of the institution. */
    public const ORGANIZATIONAL_ADMIN_PERMISSIONS = [
        'leave.view_org',
        'leave.postpone',
        'leave.reports.view',
    ];

    /**
     * Withheld from City Admin: medical evidence is personal data, and a
     * balance is corrected by the institution's own HR office.
     *
     * @var array<int, string>
     */
    public const CITY_ADMIN_WITHHELD = [
        'leave.view_medical',
        'leave.adjust_balance',
    ];
}
