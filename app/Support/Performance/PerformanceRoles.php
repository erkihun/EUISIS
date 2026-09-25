<?php

declare(strict_types=1);

namespace App\Support\Performance;

/**
 * EPMS permission sets per role (docs/epms-permissions.md). Shared by
 * RoleSeeder and the permission-registration migration so they cannot drift.
 *
 * Holding a manager permission is necessary but not sufficient: line-manager
 * authority also needs a reviewer assignment covering the employee (or being
 * the agreement's named manager). Oversight permissions work only inside
 * organization scope.
 */
final class PerformanceRoles
{
    public const MANAGER_ROLE = 'Performance Manager';

    public const APPEAL_COMMITTEE_ROLE = 'Performance Appeal Committee';

    public const OFFICER_ROLE = 'Performance Officer';

    public const REVIEWER_ROLE = 'Performance Reviewer';

    public const CALIBRATOR_ROLE = 'Performance Calibrator';

    /**
     * Runs the EPMS year inside the assigned scope: cycles, strategic goals,
     * KPIs, plans and agreements. It does not approve its own plans
     * (REVIEWER), calibrate (CALIBRATOR) or decide appeals.
     */
    public const OFFICER_PERMISSIONS = [
        'performance_cycles.view', 'performance_cycles.create', 'performance_cycles.update',
        'strategic_goals.view', 'strategic_goals.create', 'strategic_goals.update', 'strategic_goals.delete_draft',
        'strategic_goal_allocations.view', 'strategic_goal_allocations.manage',
        'performance_plans.view', 'performance_plans.create', 'performance_plans.update',
        'performance_objectives.view', 'performance_objectives.manage',
        'kpis.view', 'kpis.create', 'kpis.update',
        'kpi_targets.view', 'kpi_targets.manage', 'kpi_actuals.enter',
        'unit_performance_plans.manage', 'position_performance_plans.manage',
        'employee_performance_agreements.manage',
        'performance_calibration.view',
        'performance_reports.view', 'performance_reports.export',
        'performance_settings.view',
    ];

    /** Reviews and approves what officers prepare; verifies reported actuals. No publishing, no calibration. */
    public const REVIEWER_PERMISSIONS = [
        'performance_cycles.view',
        'strategic_goals.view', 'strategic_goals.approve', 'strategic_goal_allocations.view',
        'performance_plans.view', 'performance_plans.review', 'performance_plans.approve',
        'performance_objectives.view',
        'kpis.view', 'kpi_targets.view', 'kpi_actuals.verify',
        'performance_reports.view',
    ];

    /** Calibration panel work only; no plan, agreement or HR record rights. */
    public const CALIBRATOR_PERMISSIONS = [
        'performance_cycles.view',
        'performance_plans.view',
        'performance_calibration.view', 'performance_calibration.manage',
        'performance_reports.view',
    ];

    /** @var list<string> */
    public const EMPLOYEE_PERMISSIONS = [
        'employee_performance_agreements.view_own',
        'performance_checkins.view_own',
        'performance_reviews.self_assess',
        'performance_appeals.create',
        'performance_appeals.view_own',
    ];

    /** @var list<string> */
    public const MANAGER_PERMISSIONS = [
        'strategic_goals.view', 'strategic_goal_allocations.view',
        'performance_plans.view',
        'performance_objectives.view',
        'kpis.view',
        'kpi_actuals.enter',
        'kpi_actuals.verify',
        'employee_performance_agreements.manage',
        'employee_performance_agreements.approve',
        'performance_checkins.manage',
        'performance_reviews.manage',
        'performance_calibration.view',
    ];

    /** @var list<string> */
    public const APPEAL_COMMITTEE_PERMISSIONS = [
        'performance_appeals.review',
        'performance_appeals.decide',
    ];

    /** @var list<string> */
    public const HR_PERMISSIONS = [
        'performance_cycles.view',
        'strategic_goals.view', 'strategic_goals.create', 'strategic_goals.update',
        'strategic_goal_allocations.view', 'strategic_goal_allocations.manage',
        'performance_plans.view', 'performance_plans.create', 'performance_plans.update',
        'performance_objectives.view', 'performance_objectives.manage',
        'kpis.view', 'kpis.create', 'kpis.update',
        'kpi_targets.view', 'kpi_targets.manage',
        'kpi_actuals.enter',
        'unit_performance_plans.manage', 'position_performance_plans.manage',
        'employee_performance_agreements.manage',
        'performance_calibration.view',
        'performance_reports.view', 'performance_reports.export',
        'performance_settings.view',
    ];

    /** @var list<string> */
    public const ORGANIZATIONAL_ADMIN_PERMISSIONS = [
        'performance_cycles.view', 'performance_cycles.create', 'performance_cycles.update', 'performance_cycles.activate', 'performance_cycles.close', 'performance_cycles.approve', 'performance_cycles.publish',
        'strategic_goals.view', 'strategic_goals.create', 'strategic_goals.update', 'strategic_goals.delete_draft', 'strategic_goals.approve', 'strategic_goals.publish',
        'strategic_goal_allocations.view', 'strategic_goal_allocations.manage',
        'performance_plans.view', 'performance_plans.create', 'performance_plans.update', 'performance_plans.review', 'performance_plans.approve', 'performance_plans.publish',
        'performance_objectives.view', 'performance_objectives.manage',
        'kpis.view', 'kpis.create', 'kpis.update',
        'kpi_targets.view', 'kpi_targets.manage', 'kpi_actuals.enter', 'kpi_actuals.verify',
        'unit_performance_plans.manage', 'position_performance_plans.manage',
        'employee_performance_agreements.manage', 'employee_performance_agreements.approve',
        'performance_checkins.manage',
        'performance_reviews.manage', 'performance_reviews.finalize',
        'performance_calibration.view', 'performance_calibration.manage', 'performance_calibration.finalize',
        'performance_appeals.review',
        'performance_reports.view', 'performance_reports.export',
        'performance_settings.view',
    ];
}
