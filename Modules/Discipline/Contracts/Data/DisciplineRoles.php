<?php

declare(strict_types=1);

namespace Modules\Discipline\Contracts\Data;

/**
 * Default Employee Discipline roles, shared by DefaultRoleMatrix and the
 * registration migration. A role makes a user eligible; case work still needs
 * a seat on the case or organization scope (DisciplinaryCaseAccess).
 */
final class DisciplineRoles
{
    public const CASE_OFFICER_ROLE = 'Discipline Case Officer';

    public const COMMITTEE_MEMBER_ROLE = 'Disciplinary Committee Member';

    public const DECISION_AUTHORITY_ROLE = 'Disciplinary Decision Authority';

    public const EMPLOYEE_PERMISSIONS = ['discipline.view_own', 'discipline.respond_own'];

    public const CASE_OFFICER_PERMISSIONS = [
        'dashboard.view',
        'discipline.view', 'discipline.create', 'discipline.charge', 'discipline.manage',
        'discipline.investigate', 'discipline.deliver_decision', 'discipline.view_reports',
    ];

    public const COMMITTEE_MEMBER_PERMISSIONS = ['dashboard.view', 'discipline.investigate', 'discipline.recommend'];

    public const DECISION_AUTHORITY_PERMISSIONS = ['dashboard.view', 'discipline.view', 'discipline.decide'];

    /** Aggregates only. */
    public const REPORT_VIEWER_PERMISSIONS = ['discipline.view_reports'];

    /**
     * City-wide roles do not read or act on institution disciplinary cases;
     * they keep aggregate reports only.
     */
    public const CITY_ADMIN_WITHHELD = [
        'discipline.view_own', 'discipline.respond_own', 'discipline.view', 'discipline.create', 'discipline.charge',
        'discipline.manage', 'discipline.investigate', 'discipline.recommend', 'discipline.decide', 'discipline.deliver_decision',
    ];
}
