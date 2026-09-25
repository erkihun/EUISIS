# EPMS — Permissions and Data Visibility

Permissions are registered by migration `2026_09_25_100100_register_performance_permissions` (catalog: `database/seeders/data/performance-permissions.php`). The role sets live in `App\Support\Performance\PerformanceRoles`.

## 1. How access is decided

A permission alone is never enough. Every action checks it **together with** one of the following:

| Check | Used for | Implementation |
|---|---|---|
| Organization scope | plans, cycles, KPIs, reports, calibration, oversight | `EpmsAccess::inScope()` → `OrganizationScopeService::canExercisePermission()` |
| Manager coverage | agreements, actuals, check-ins, reviews | `EpmsAccess::isManagerOf()`: the named manager on the agreement, or a Daily Activity reviewer assignment that covers the employee |
| Ownership | My Performance | `EpmsAccess::isOwn()`: the signed-in user's employee record; no employee ID is accepted from the browser |
| Committee membership | appeals, committee calibration | an active `GrievanceCommitteeMember` of the right `CommitteeType` |
| Separation of duties | approve a plan, verify an actual, decide a score adjustment, decide a target amendment | `EpmsAccess::assertSeparated()`: the actor must differ from the submitter, unless `allow_self_approval` is on or the actor is Super Admin |

A user never manages their own agreement, even with every permission; lists exclude it (`constrainAgreements`).

## 2. Permission catalog

| Area | Permissions |
|---|---|
| Cycles | `performance_cycles.view`, `.create`, `.update`, `.activate`, `.close` |
| Plans | `performance_plans.view`, `.create`, `.update`, `.review`, `.approve`, `.publish`; `performance_objectives.manage`; `unit_performance_plans.manage`; `position_performance_plans.manage` |
| KPIs | `kpis.view`, `.create`, `.update`; `kpi_targets.manage`; `kpi_actuals.enter`, `.verify` |
| Agreements | `employee_performance_agreements.view_own`, `.manage`, `.approve` |
| Check-ins and reviews | `performance_checkins.view_own`, `.manage`; `performance_reviews.self_assess`, `.manage`, `.finalize` |
| Calibration | `performance_calibration.view`, `.manage`, `.finalize` |
| Appeals | `performance_appeals.create`, `.view_own`, `.review`, `.decide` |
| Reports | `performance_reports.view`, `.export` |
| Settings | `performance_settings.view`, `.update` |

## 3. Default roles

| Role | Grant |
|---|---|
| Super Admin, System Admin, City Admin, PSB Admin | all EPMS permissions (unrestricted scope) |
| Organizational Admin | `ORGANIZATIONAL_ADMIN_PERMISSIONS`: cycles, full plan workflow, KPIs, actuals, agreements, reviews including finalize, calibration, appeal review, reports, settings view (scoped) |
| HR Officer | `HR_PERMISSIONS`: plans (no approve or publish), KPIs, targets, agreements, reports, settings view (scoped) |
| Performance Manager | `MANAGER_PERMISSIONS`: view plans and KPIs, enter and verify actuals, manage and approve covered agreements, check-ins, reviews |
| Performance Appeal Committee | `performance_appeals.review`, `.decide` (decisions still require committee membership) |
| Employee | own agreement, check-ins, self-assessment, create and view own appeals (plus Daily Activity own permissions) |

Special rules:

- A **global** cycle (no organization) can only be created by Super, City or System Admin.
- A **global** KPI (city-wide) can only be created or edited with unrestricted scope.

## 4. What each viewer sees (`PerformancePresenter`)

| Data | Employee (own) | Covering manager or HR in scope |
|---|---|---|
| Agreement, items, targets, own actuals and evidence | yes | yes |
| Manager comment and improvement actions of a review | only once that review is COMPLETED | yes |
| Manager private notes (check-ins, reviews) | **never** | yes |
| Performance improvement plans | **never** | yes |
| Manager competency ratings | only once the result is RELEASED | yes |
| Result and trace | only when RELEASED (`result_hidden` otherwise) | yes, including working results |
| Adjustment history | no | yes |

Files (evidence, appeal attachments) sit on the private disk. Download routes re-check ownership, coverage or scope, and respond with `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`.

## 5. Other protections

- **No API:** there are no API routes for performance data (a test enforces this).
- **Mass assignment:** workflow and score fields are not in `$fillable`; services set them with `forceFill`.
- **Lookups:** server-side, scoped, limited to 20 rows, 2+ characters for employees, throttled.
- **Rate limits:** recalculation, sync, evidence upload, export and appeal filing are rate-limited.
- **Audit:** every state change is audited, with before and after values and the reason where one applies.
