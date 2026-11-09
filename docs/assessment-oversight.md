# Assessment Oversight & Compliance

A governance area of the Competency / Behavioural Assessment module, kept
separate from the [Form Builder](assessment-form-builder.md). It answers, for
one **assessment cycle** at a time: which institutions take part, how far they
have got, who is eligible, who is assessed, why others are not, how results
fall into result bands, the gender breakdown, data-quality problems, and which
institutions have signed off their summary.

Related documents:

- [assessment-coverage.md](assessment-coverage.md): eligibility, denominator, assessed and unassessed definitions, gender, result bands.
- [assessment-institution-submission.md](assessment-institution-submission.md): sign-off, city verification, outdated detection.
- [assessment-data-quality.md](assessment-data-quality.md): rules, severities, blocking.

The supplied paper document is a business reference only. Nothing in code
reproduces its layout, figures or score ranges.

## Architecture

```
Configuration      Form Builder (forms, versions, criteria, ratings, target rules)
                   Result-band policies · Unassessed reasons            ← Oversight Setup
Operations         Assessment records + peer responses (assessment_records / assessment_responses)
                   Assessment cycles · participating institutions · eligibility snapshot
Oversight          Dashboard · Institutions · Coverage · Unassessed · Distribution ·
                   Gender · Data quality · Submissions · Reports
```

A record belongs to a cycle when it has the cycle's assessment type and
exactly the cycle's period. `AssessmentRecord` fills `assessment_cycle_id` on
creation, and saving a cycle re-links existing records.

### Services (`app/Services/Assessment/Oversight`)

| Service | Responsibility |
|---|---|
| `OversightAccess` / `OversightScope` | Permission plus organization scope plus (for line managers) unit subtree. Used by every page, drill-down, action and export. |
| `AssessmentEligibilityService` | `resolveEligibility`, `snapshotCycleEligibility`, `finalize`, exclusion request/decide/withdraw, `applyApprovedExclusion`, `restoreEligibility`, `explainEligibility`. |
| `AssessmentCoverageService` | The single definition of population, eligible, assessed, unassessed outcomes, gender buckets and coverage. SQL aggregates only, cached per cycle, scope and data version. |
| `AssessmentResultDistributionService` | Band policy validation, activation and versioning; classification; distribution and breakdowns. |
| `AssessmentDataQualityService` | Rules returning normalized issues (code, severity, organization, employee, details, resolution hint, blocking). |
| `AssessmentInstitutionSubmissionService` | Readiness, submission snapshot, return, reject, verify, finalize, outdated detection. |
| `AssessmentOversightQueryService` | Read model: institution table, employee list, units, form usage, peer completion, reasons, trend. |
| `AssessmentCycleService` | Cycles, participation, unassessed reasons. |

Controllers stay thin: `AssessmentOversightController` (pages),
`AssessmentOversightAdminController` (setup and actions) and
`AssessmentOversightExportController`.

## Pages and routes

All routes are under `/assessments/oversight` (`routes/assessments.php`; the old `/performance/assessment-oversight` URLs redirect) and named
`assessment-oversight.*`. Every page takes `?cycle=`.

| Page | Route | Permission |
|---|---|---|
| City dashboard | `dashboard` | `assessment_oversight.view_dashboard` |
| Institution Monitoring | `institutions` | `assessment_oversight.view_institutions` |
| Institution summary (drill-down) | `institution` | in-scope oversight viewer |
| Coverage / Unassessed employees | `employees` (`?outcome=unassessed`) | `assessment_oversight.view_employee_status` |
| Result Distribution | `distribution` | `assessment_oversight.view_results` |
| Gender Analysis | `gender` | `assessment_oversight.view_demographics` |
| Data Quality | `data-quality` | `assessment_oversight.view_data_quality` |
| Institution Submissions | `submissions` | any `assessment_submissions.*` |
| Reports and exports | `reports`, `export` | `assessment_reports.view` / `.export` |
| Setup (cycles, bands, reasons) | `setup`, `cycles.show` | `assessment_oversight.manage_cycles` / `.manage_policies` |

The sidebar has its own **Assessment Oversight** group, separate from the
Performance Management group.

### Cycle selection

Pages use the requested cycle. Without one, they use the active cycle only
when exactly one cycle is active; otherwise they ask the user to choose.
Totals never mix cycles.

### Drill-down

City → Institution → Organization Unit → Employee. Each level is
authorized on the server:

- City oversight users have city-wide scope.
- Institution users see their scoped organizations (and subtree).
- Line managers with `assessment_oversight.view_unit` see only their own unit and the units below it.

A URL naming an organization or unit outside the scope returns 403.

## Permissions and roles

| Permission | Purpose |
|---|---|
| `assessment_oversight.view_dashboard` | Aggregate dashboard |
| `assessment_oversight.view_institutions` | Institution table and summaries |
| `assessment_oversight.view_unit` | Own unit subtree only (line managers) |
| `assessment_oversight.view_employee_status` | Employee status lists |
| `assessment_oversight.view_results` | Final results and distribution |
| `assessment_oversight.view_responses` | Criterion-level responses (not shown by oversight pages; city administrators only) |
| `assessment_oversight.view_demographics` | Gender analysis |
| `assessment_oversight.view_data_quality` | Data quality |
| `assessment_oversight.manage_cycles` | Cycles, participation, eligibility snapshot |
| `assessment_oversight.manage_policies` | Result bands, unassessed reasons |
| `assessment_exclusions.request` / `.approve` | Exclusion workflow (two people) |
| `assessment_submissions.submit` / `.review` / `.return` / `.verify` / `.finalize` | Submission workflow |
| `assessment_reports.view` / `.export` | Reports and exports |

Role mapping (migration `2026_10_08_100400`, constants in `PerformanceRoles`):

| Role | Grants |
|---|---|
| Super, System, City and Public Service Bureau Admin | All of the above |
| Assessment Oversight Officer (new, narrow) | View, approve exclusions, review, return, verify, reports. **Not** finalize. |
| Assessment Report Viewer (new) | Dashboard, institution figures, reports. No employee lists, results or responses. |
| Organizational Admin | Institution side: view, request exclusions, submit, reports. |
| HR Officer | View (no results), request exclusions, reports. |
| Performance Officer | View status and data quality, request exclusions, view reports. |
| Performance Manager | Own unit: `view_unit` plus `view_employee_status`. |

Aggregate-only users never see employee lists or criterion responses. The
records module (`assessment-records.show`) applies its own participant check.

## Audit

Every change is written to `audit_logs`:

- cycle created or updated, participation changed;
- eligibility snapshot created, eligibility finalized or restored;
- exclusion requested, approved, rejected or withdrawn;
- unassessed reason changed, result-band policy changed;
- submission submitted, returned, rejected, verified, finalized or marked outdated;
- report exported.

## Exports

CSV, Excel and PDF are available for these reports: consolidated institution
report (eligible, assessed, unassessed, reasons, result bands, gender),
unassessed employees, result distribution, gender breakdown, data quality,
and submission status.

- Exports use the selected cycle, active filters and the user's scope, and each export is audited.
- Text cells starting with `= + - @`, tab or CR are neutralized, and Excel cells are written as text.
- No criterion responses or comments are exported.
- Employee-level CSV is streamed in chunks of 1,000 rows. Employee-level Excel/PDF is refused above 5,000 rows, with a pointer to CSV.

## Reports navigator and executive summary

The **Reports** page is the report entry point, not a second operational
dashboard. For the selected cycle and (optionally) one authorized institution,
it shows a compact, drill-down-able summary of eligible, assessed and
unassessed employees, coverage, institution workflow status and—only to users
with `assessment_oversight.view_data_quality`—data-quality severities. Its
consolidated table and exports use the same filter and `OversightScope`.

It links to the existing detailed report pages for coverage/unassessed lists,
result distribution, gender, data quality and submissions. Final result bands
are shown only to users with `assessment_oversight.view_results`; demographic
figures still require `assessment_oversight.view_demographics` and retain
small-group suppression.

The following requested analytics cannot be reported truthfully yet because
their source workflows are not implemented in this module: assessment appeals,
moderation decisions, result corrections, competency gaps and training/IDP
plans. Result-version storage is being introduced separately, but reports must
not treat that foundation as completed governance data. Add those report cards
only when their workflow records, lifecycle states, scope checks and audit
events are live.

## Scheduler

`assessments:oversight-monitor` runs daily at 06:30 (`routes/console.php`).
It:

- marks open submissions whose source data changed as `outdated`;
- reminds institutions about overdue submissions, and about due-soon ones when the cycle configures `reminder_days_before`. Each institution gets at most one reminder per day (`last_reminded_at`);
- sends verifiers one daily digest about pending submissions.

It reuses `PerformanceNotification` (database channel) and supports
`--dry-run`.

## Scale (about 180,000 employees)

- No page loads employee rows to count them. Aggregates are `SUM(CASE …)` over one derived subquery (`AssessmentCoverageService::rows`), grouped in SQL.
- The snapshot is built in chunks of 1,000 employees and inserted in batches of 500.
- Indexes:
  - `acee_cycle_org_status_idx` (cycle, organization, eligibility_status);
  - `acee_cycle_unit_idx`;
  - unique (cycle, employee);
  - `ar_cycle_org_status_idx`, `ar_cycle_employee_idx` on `assessment_records`;
  - `aco_cycle_status_idx`;
  - `ais_cycle_status_idx`;
  - `aer_cycle_org_status_idx`.
- Aggregates are cached for 10 minutes under a key that contains:
  - the cycle;
  - a per-cycle data version (bumped on every record, response, eligibility or exclusion change);
  - a hash of the viewer's scope. City-wide cache entries are never served to scoped users.
- Lists are server-side paginated (25 rows), and a test asserts that the dashboard query count does not grow with the number of employees.
- If query plans on production volumes show the derived subquery is too slow, add a per-cycle summary table refreshed by the monitor command. Do this only after measuring.

## NEEDS_DECISION

These policy questions are not decided in code. Each cycle shows which are
open, and the system applies the stated safe default until someone decides:

| Question | Default until decided |
|---|---|
| Eligible employment statuses | ACTIVE only |
| New hires | Included (no minimum service) |
| Transfers during the cycle | Assignment on the reference date decides; the snapshot does not follow later transfers |
| Long-term leave, suspension | Follows the eligible statuses; otherwise an approved exception |
| Whether approved exclusions reduce the denominator | No |
| Submission completion requirement | Every eligible employee must be assessed, carry an institution-reported reason, or have an approved exception |
| Institution sign-off authority | Permission `assessment_submissions.submit` plus scope (no Position rule yet) |
| City verification and finalization authority | Verify: Oversight Officer. Finalize: city administrators. Submitter cannot verify or finalize, and a verifier cannot finalize the same revision. |
| Result-band ranges | None. A policy must be configured and pinned to the cycle. |
| Small-group privacy threshold | None (no suppression) |
| Deadlines and reminder window | None (no overdue or due-soon monitoring) |

## Seed data

No demo data is seeded. The demo seeder's tests are clock-dependent and
sensitive to new data, so adding synthetic institutions safely needs its own
change. The test suite builds synthetic institutions instead (see
`tests/Feature/Assessment/AssessmentOversightTest.php`).
