<?php

declare(strict_types=1);

namespace App\Support\Grievances;

/**
 * Grievance permission sets per default role (docs/grievance-management.md §7).
 * Shared by DefaultRoleMatrix and the registration migration so they cannot
 * drift.
 *
 * A role only makes a user ELIGIBLE. Case access still requires ownership,
 * active membership of the stage's committee panel, a case-officer
 * assignment, being the resolved approver, or oversight inside organization
 * scope (GrievanceCaseAccessService). Committee roles (chairperson, writer)
 * come from committee membership, not from these system roles.
 */
final class GrievanceRoles
{
    public const OFFICER_ROLE = 'Grievance Officer';

    public const COMMITTEE_MEMBER_ROLE = 'Grievance Committee Member';

    public const COMMITTEE_WRITER_ROLE = 'Grievance Committee Writer';

    public const COMMITTEE_CHAIR_ROLE = 'Grievance Committee Chairperson';

    public const APPROVER_ROLE = 'Grievance Executive Approver';

    public const ADMINISTRATOR_ROLE = 'Grievance Administrator';

    public const REGISTRY_ROLE = 'Grievance Registry Officer';

    public const OVERSIGHT_ROLE = 'Grievance Oversight';

    public const TRIBUNAL_ROLE = 'Administrative Tribunal Officer';

    /** My Portal: own grievances only. */
    public const EMPLOYEE_PERMISSIONS = [
        'grievances.view_own', 'grievances.create', 'grievances.update_draft', 'grievances.submit', 'grievances.withdraw',
    ];

    /**
     * Intake/secretariat and Team or Directorate case officer: intake review
     * in scope, assigned unit cases, officer assignment, drafting and letters.
     */
    public const OFFICER_PERMISSIONS = [
        'dashboard.view',
        'grievances.view_assigned', 'grievances.intake_review', 'grievances.assign', 'grievances.review',
        'grievances.request_information', 'grievances.escalate', 'grievances.reassign', 'grievances.sla_pause',
        'grievances.close',
        'grievance_committees.view', 'grievance_routes.view', 'grievance_sla.view',
        'grievance_decisions.view', 'grievance_decisions.create', 'grievance_decisions.review',
        'grievance_decisions.submit_for_approval', 'grievance_decisions.finalize',
        'grievance_hearings.view', 'grievance_hearings.manage',
        'grievance_correspondence.view', 'grievance_correspondence.create', 'grievance_correspondence.sign', 'grievance_correspondence.issue',
        'grievance_reports.view',
    ];

    /** Panel member: review and deliberation on the committee's cases. */
    public const COMMITTEE_MEMBER_PERMISSIONS = [
        'dashboard.view',
        'grievances.view_assigned', 'grievances.review', 'grievances.request_information',
        'grievance_decisions.view', 'grievance_decisions.create',
        'grievance_hearings.view',
        'grievance_correspondence.view',
    ];

    /** Committee writer: minutes and draft preparation on top of member work. */
    public const COMMITTEE_WRITER_PERMISSIONS = [
        ...self::COMMITTEE_MEMBER_PERMISSIONS,
        'grievance_hearings.manage',
        'grievance_correspondence.create',
    ];

    /** Committee chairperson: leads review, confirms minutes, submits and finalizes. */
    public const COMMITTEE_CHAIR_PERMISSIONS = [
        ...self::COMMITTEE_WRITER_PERMISSIONS,
        'grievances.escalate', 'grievances.sla_pause', 'grievances.decide_recusal', 'grievances.close',
        'grievance_decisions.review', 'grievance_decisions.submit_for_approval', 'grievance_decisions.finalize',
        'grievance_correspondence.sign', 'grievance_correspondence.issue',
    ];

    /** Institution head / superior official: only decisions routed to them. */
    public const APPROVER_PERMISSIONS = [
        'dashboard.view',
        'grievance_decisions.view', 'grievance_decisions.approve',
        'grievance_decisions.return_for_correction', 'grievance_decisions.reject',
        'grievance_correspondence.view', 'grievance_correspondence.sign',
    ];

    /** Configuration only — no case access. */
    public const ADMINISTRATOR_PERMISSIONS = [
        'dashboard.view',
        'grievance_committees.view', 'grievance_committees.manage', 'grievance_committees.approve',
        'grievance_committee_members.manage',
        'grievance_routes.view', 'grievance_routes.manage', 'grievance_routes.approve',
        'grievance_sla.view', 'grievance_sla.manage',
        'grievance_settings.view', 'grievance_settings.update', 'grievance_settings.manage_delegations',
        'grievance_reports.view',
    ];

    /** Correspondence registry: seal custody and dispatch of letters in scope. */
    public const REGISTRY_PERMISSIONS = [
        'dashboard.view',
        'grievance_correspondence.view', 'grievance_correspondence.apply_seal', 'grievance_correspondence.issue',
        'grievance_settings.manage_seals',
    ];

    /** Read-only oversight within scope; highly restricted cases show metadata only. */
    public const OVERSIGHT_PERMISSIONS = [
        'dashboard.view',
        'grievances.oversight_view', 'grievances.view_audit',
        'grievance_committees.view', 'grievance_routes.view', 'grievance_sla.view',
        'grievance_decisions.view', 'grievance_hearings.view', 'grievance_correspondence.view',
        'grievance_reports.view', 'grievance_reports.export',
    ];

    public const TRIBUNAL_PERMISSIONS = [
        'dashboard.view', 'grievances.tribunal', 'grievances.view_assigned',
        'grievance_decisions.view', 'grievance_correspondence.view',
    ];

    /** Added to the Auditor role (read-only review). */
    public const AUDITOR_PERMISSIONS = ['grievances.oversight_view', 'grievances.view_audit', 'grievance_reports.view'];

    /** Added to the Report Viewer role (aggregates only). */
    public const REPORT_VIEWER_PERMISSIONS = ['grievance_reports.view'];

    /**
     * Case-content permissions City Admin does NOT receive by default: a
     * city-wide operational role must not read every confidential grievance.
     * City Admin keeps configuration and aggregate reports.
     */
    public const CITY_ADMIN_WITHHELD = [
        'grievances.view_assigned', 'grievances.intake_review', 'grievances.assign', 'grievances.review',
        'grievances.request_information', 'grievances.oversight_view', 'grievances.view_audit',
        'grievance_decisions.approve', 'grievance_decisions.create', 'grievance_decisions.review',
        'grievance_decisions.submit_for_approval', 'grievance_decisions.return_for_correction',
        'grievance_decisions.reject', 'grievance_decisions.finalize',
        'grievance_correspondence.sign', 'grievance_correspondence.apply_seal',
        'grievances.view_own', 'grievances.create', 'grievances.update_draft', 'grievances.submit', 'grievances.withdraw',
    ];

    /**
     * First-module permission → the new permissions its holders receive, so
     * custom roles and direct grants keep working after the upgrade.
     *
     * @return array<string, list<string>>
     */
    public static function legacyEquivalents(): array
    {
        return [
            'grievances.view' => ['grievances.view_assigned'],
            'grievances.manage' => array_values(array_unique([...self::OFFICER_PERMISSIONS, ...self::ADMINISTRATOR_PERMISSIONS])),
            'grievances.committee' => self::COMMITTEE_MEMBER_PERMISSIONS,
            'grievances.chairperson' => self::COMMITTEE_CHAIR_PERMISSIONS,
            'grievances.manager' => self::APPROVER_PERMISSIONS,
            'grievances.tribunal' => self::TRIBUNAL_PERMISSIONS,
        ];
    }
}
