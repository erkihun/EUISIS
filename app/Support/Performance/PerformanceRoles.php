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

    /** City-level assessment oversight: reviews, returns and verifies institutional submissions. */
    public const ASSESSMENT_OVERSIGHT_ROLE = 'Assessment Oversight Officer';

    /** Aggregate assessment figures only: no employee lists, results or responses. */
    public const ASSESSMENT_REPORT_VIEWER_ROLE = 'Assessment Report Viewer';

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
        'assessment_forms.view', 'assessment_forms.create', 'assessment_forms.edit_draft',
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
        'assessment_forms.view', 'assessment_forms.create', 'assessment_forms.edit_draft', 'assessment_forms.publish', 'assessment_forms.archive',
    ];

    /*
     * Assessment Oversight & Compliance grants (docs/assessment-oversight.md).
     * City administrators hold the whole catalog. Finalizing a submission stays
     * with them until a city verification authority is decided (NEEDS_DECISION).
     */

    /** @var list<string> */
    public const ASSESSMENT_OVERSIGHT_PERMISSIONS = [
        'assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_employee_status',
        'assessment_oversight.view_results', 'assessment_oversight.view_demographics', 'assessment_oversight.view_data_quality',
        'assessment_exclusions.approve',
        'assessment_submissions.review', 'assessment_submissions.return', 'assessment_submissions.verify',
        'assessment_reports.view', 'assessment_reports.export',
    ];

    /** @var list<string> */
    public const ASSESSMENT_REPORT_VIEWER_PERMISSIONS = [
        'assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_reports.view',
    ];

    /** Institution side: monitors its own scope, requests exclusions and signs off the summary. */
    public const ASSESSMENT_INSTITUTION_ADMIN_PERMISSIONS = [
        'assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_employee_status',
        'assessment_oversight.view_results', 'assessment_oversight.view_demographics', 'assessment_oversight.view_data_quality',
        'assessment_exclusions.request', 'assessment_submissions.submit',
        'assessment_reports.view', 'assessment_reports.export',
    ];

    /** @var list<string> */
    public const ASSESSMENT_HR_PERMISSIONS = [
        'assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_employee_status',
        'assessment_oversight.view_demographics', 'assessment_oversight.view_data_quality',
        'assessment_exclusions.request', 'assessment_reports.view', 'assessment_reports.export',
    ];

    /** @var list<string> */
    public const ASSESSMENT_OFFICER_PERMISSIONS = [
        'assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_employee_status',
        'assessment_oversight.view_data_quality', 'assessment_exclusions.request', 'assessment_reports.view',
    ];

    /** Line managers: status of their own unit, nothing wider. */
    public const ASSESSMENT_MANAGER_PERMISSIONS = [
        'assessment_oversight.view_unit', 'assessment_oversight.view_employee_status',
    ];

    /*
     * Assessment Execution grants (docs/assessment-execution.md). Holding a
     * permission is never enough to open an assessment: the evaluator
     * assignment, scope and workflow status are checked as well.
     */

    /** Every employee: rate what is assigned to them, see their own results. */
    public const ASSESSMENT_EVALUATOR_PERMISSIONS = [
        'assessments.view_assigned', 'assessments.complete_assigned', 'assessments.submit', 'assessments.view_own_result',
    ];

    /** Institution administration: generate, assign evaluators, review, return, finalize in scope. */
    public const ASSESSMENT_EXECUTION_ADMIN_PERMISSIONS = [
        'assessments.review', 'assessments.return_for_correction', 'assessments.finalize',
        'assessment_assignments.view', 'assessment_assignments.generate', 'assessment_assignments.reassign',
        'assessment_results.view',
    ];

    /** HR: evaluator assignment and review, but not finalization. */
    public const ASSESSMENT_EXECUTION_HR_PERMISSIONS = [
        'assessments.review', 'assessments.return_for_correction',
        'assessment_assignments.view', 'assessment_assignments.reassign', 'assessment_results.view',
    ];
}
