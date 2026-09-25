# EPMS — Architecture

Employee Performance Management System (የሰራተኞች የአፈጻጸም አስተዳደር ሥርዓት).

Related documents: [calculation rules](epms-calculation-rules.md) · [cascade rules](epms-cascade-rules.md) · [permissions](epms-permissions.md) · [workflow](epms-workflow.md)

## 1. What it does

EPMS links the organization's plan to each employee's result through one auditable chain:

```
Organization plan → objectives → KPI targets
  → unit plan → child unit plan → position plan          (cascade, docs/epms-cascade-rules.md)
  → employee agreement (items = position targets)
  → evidence (daily activity, documents) + KPI actuals   (measurement)
  → check-ins → mid-year → year-end review
  → result (KPI achievement + competencies) → calibration → release → appeal
  → roll-up: employee actuals → unit → parent unit → organization
```

These rules hold everywhere:

- **Daily activity is evidence, not a score.** The number of activities logged never changes a score. Only the linked *quantity* of approved activity items can become a KPI actual, and only for KPIs whose data source is `DAILY_ACTIVITY`.
- **Scores compare a target with an actual.** Each KPI declares its direction (higher/lower is better, target is best, binary, milestone) and how it aggregates. Employee percentages are never summed.
- **Every number is reproducible.** Results store a frozen `snapshot_json` trace (inputs, formulas, weights). Finalized results are immutable, and an appeal creates a new revision.
- **Scope is enforced on the server.** Every query and action checks the permission together with the organization scope, the manager's coverage or ownership. Hiding something in the UI is never the control.
- **Policy lives in settings.** Weights, caps, thresholds, required steps and the appeal window are all settings (System Settings group `performance`).

## 2. Reused platform pieces

| Need | Reused from |
|---|---|
| Organizations, units (tree), positions, employees, assignments | core HR models (`Organization`, `OrganizationUnit`, `Position`, `Employee`, `EmployeeAssignment`) |
| "Who manages whom" | Daily Activity reviewer assignments: `DailyActivityReviewerResolver::coverage()` and `DailyActivityCoverage::apply()` |
| Organization scope | `OrganizationScopeService` (`applyOrganizationScope`, `allowedOrganizationIds`, `canExercisePermission`, `isUnrestricted`) |
| Committees (appeal, calibration) | `GrievanceCommittee` / `GrievanceCommitteeMember`, new `CommitteeType` cases `performance_appeal` and `performance_calibration` |
| Audit | `WriteAuditLogAction` via `EpmsAudit`; 23 `AuditEventType::Performance*` cases |
| Notifications | `PerformanceNotification` (module `performance`), rendered by `NotificationPresenter`; channels follow the existing notification settings |
| Settings | `SystemSettingsRegistry::GROUP_PERFORMANCE` (19 fields), typed by `EpmsSettings` |
| Files | private `local` disk under random names; downloads are authorized and sent with `nosniff` and `no-store` |
| Localization | `lang/{en,am}/performance.php` (server) and `resources/js/i18n/{en,am}/performance.ts` (UI); Ethiopian calendar display through `LocalizedDateDisplay` / `LocalizedDatePicker` |

## 3. Code map

```
app/Enums/Performance/                  23 string-backed enums (statuses, directions, aggregations …)
app/Models/                             28 EPMS models (UUIDv7 keys; status fields are not mass-assignable)
app/Casts/DateOnly.php                  period and effective dates stored as Y-m-d on every driver
app/Services/Performance/
  Calculation/                          Dec (BigDecimal wrapper), KpiAchievementCalculator, KpiAggregationService
  EpmsSettings, EpmsAccess, EpmsAudit   policy, authorization helpers, audit
  PerformanceCycleService               cycle lifecycle
  PerformancePlanService                plans, objectives, targets, validation, versions
  PlanCascadeService                    cascade / decline
  EmployeeAgreementService              agreements, items, workflow, transfer
  KpiActualService (+ SystemKpiSourceRegistry)  manual, daily-activity and system actuals, verification
  EmployeeScoreCalculator               the employee score trace
  PerformanceAggregationService         unit/organization roll-up and plan score
  PerformanceReviewService              check-ins, mid-year, year-end
  PerformanceResultService              calculate, adjust, finalize, release, cycle total
  PerformanceCalibrationService, PerformanceAppealService, DevelopmentPlanService,
  TargetAmendmentService, PerformanceEvidenceService, PerformanceNotifier
  PerformancePresenter                  the only place that decides what a viewer may see
app/Http/Controllers/Performance/       thin controllers (validate → one service call → redirect/render)
app/Http/Controllers/Employee/MyPerformanceController.php   My Portal (own records only)
app/Jobs/Performance/RecalculatePlanScore.php               queued, unique per plan and date
routes/performance.php                  76 routes; no API routes
resources/js/Pages/Performance/**       Dashboard, Cycles, KPIs, Plans, Agreements, Calibration, Appeals, Reports, Settings
resources/js/Pages/Employee/MyPerformance.tsx
resources/js/Components/performance/    ui kit, shared agreement views, forms, server-side lookup
```

## 4. Data model (migration `2026_09_25_100000_create_performance_management_tables`)

| Table | Purpose / key constraints |
|---|---|
| `performance_cycles` | Period, planning and review windows, status. `current_key` is unique, so there is one current cycle per scope (organization or global). |
| `kpis` | KPI library: measurement type, direction, aggregation, data source, frequency, cap, tolerance, zero-score deviation, milestones (JSON), system source key. |
| `performance_plans` | ORGANIZATION / UNIT / POSITION plans. `lineage_key` + `version_no`, `supersedes_plan_id`. `live_key` is unique, so there is one published version per lineage. |
| `performance_objectives` | Objectives with weight, mandatory flag, `parent_objective_id` lineage and cascade mode. |
| `performance_cascades` | One row per cascade decision (parent objective → child objective, type). |
| `kpi_targets` | Target per objective and KPI and period. `parent_target_id` is the contribution link; `amended_from_id` and `is_current` give target versions. |
| `employee_performance_agreements` | Per employee, cycle and assignment. `active_key` is unique, so there is one live agreement per assignment. |
| `employee_performance_items` | Agreement KPIs; `position_target_id` links each to the plan. |
| `kpi_actuals` | Measurements. Unique on (`subject_key`, period, `source_key`). `source_key = aggregate` for roll-ups. |
| `kpi_contributions` | Which actual fed which parent target. Unique on (`parent_target_id`, `source_actual_id`), which prevents double counting. |
| `performance_evidence` | Documents and links; private file path is hidden from serialization. |
| `performance_checkins`, `performance_reviews` | Check-ins (private note hidden) and MID_YEAR / YEAR_END reviews. |
| `competency_frameworks`, `competencies`, `position_competencies`, `employee_competency_assessments` | Competency part of the score. |
| `performance_rating_scales`, `performance_rating_bands` | RESULT and COMPETENCY scales (seeded: RESULT-DEFAULT, COMPETENCY-5). |
| `performance_results` | `revision_no`, `is_current`, component scores, `snapshot_json`. The model blocks updates and deletes once final. |
| `performance_score_adjustments` | MANAGER / CALIBRATION / APPEAL changes with the original score, the adjusted score and the reason. |
| `performance_calibration_sessions`, `performance_calibration_items` | Calibration panels. |
| `performance_appeals` | `appeal_no` PA-YYYY-00001, private attachment, decision. |
| `performance_improvement_plans`, `individual_development_plans` | PIP (confidential) and IDP. |
| `performance_target_amendments` | Requested target or weight changes; approval creates a new target or item version. |
| `performance_plan_scores` | Dashboard cache (plan, as-of date, score, trace). **Not the source of truth.** |

The migration also adds `performance_objective_id` and `employee_performance_item_id` to `daily_activity_items`. The CHECK constraints are created only on PostgreSQL:

- date order on cycles, targets and agreements;
- weights 0–100 on objectives, targets and items;
- an actual belongs to exactly one subject (a target or an item);
- a result's final score is ≥ 0, and its component weights total 100;
- competency ratings are 1–10.

## 5. Scale

- Pages paginate (20–50 rows). Employee, unit and position pickers query `/performance/lookups/*` on the server: 2+ characters for employees, at most 20 rows, scoped.
- Organization roll-ups run in `RecalculatePlanScore` (queued, `ShouldBeUnique` per plan and date, rate-limited trigger). Page loads read the cached `performance_plan_scores` row.
- The CSV export streams in chunks of 500, with a UTF-8 BOM and a formula-injection guard, and is audited as `export_performed`.

## 6. Known gaps

- Exports are CSV only; PDF and Excel are not implemented. The CSV opens in Excel.
- 7 of the 15 requested reports are implemented: results, distribution, agreement completion, missing actuals, appeals, calibration, improvement plans.
- There are no browser (Dusk) tests. The UI contract is covered by Inertia page tests.
- The PostgreSQL CHECK constraints are not exercised by the SQLite test suite.
- System KPI sources: `id_cards.issued`, `employee_transfers.completed`, `service_feedback.average_rating`. To add more, register them in `SystemKpiSourceRegistry`.
