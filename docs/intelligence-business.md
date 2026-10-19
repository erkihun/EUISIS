# Addis One Intelligence — business / management areas (phase 2)

The business domain of Addis One Intelligence (`docs/intelligence-architecture.md`,
D-1) is seven analyses in `app/Services/Intelligence/Business/`, each a
`final class … extends IntelligenceAnalysis` registered in
`IntelligenceAreaRegistry::ANALYSES`. They reuse the Situation Room blocks
unchanged — `SituationScope` (WHERE), `SituationPeriod`, `SituationFilters`,
`SituationCache`, `OrganizationRollup`, `SituationPanel`, the React panel kit —
and add insights with evidence. Words come from
`lang/{en,am}/intelligence_business.php` (merged under `intelligence.` by
`IntelligenceLabels`). Metric definitions are in
`docs/intelligence-metrics-catalog.md`.

| Area | Class | Grant |
| --- | --- | --- |
| Overview | `OverviewAnalysis` | `intelligence.view` |
| Executive | `ExecutiveAnalysis` | `intelligence.executive.view` |
| Workforce | `WorkforceAnalysis` | `intelligence.workforce.view` |
| Service | `ServiceAnalysis` | `intelligence.service.view` |
| Public Voice | `PublicVoiceAnalysis` | `intelligence.public-voice.view` |
| Performance | `PerformanceAnalysis` | `intelligence.performance.view` |
| Forecasting | `ForecastingAnalysis` (+ `Forecasting\Forecaster`) | `intelligence.forecasting.view` |
| Data Quality | `DataQualityAnalysis` | `intelligence.data-quality.view` |

Shared helpers live in the trait `BusinessQueries`: a date series bucketed
with `SqlDateBucket` (the Situation Room's own `trend()` uses `SituationSql`,
which has no MySQL branch), the complete-month windows baselines and forecasts
use, a driver-aware id-to-text cast, dictionary label shortcuts, route-guarded
drill-down links and a severity scale by affected-record count.

## Rules every area follows

- **Permission = WHAT, scope = WHERE.** Every query is confined with
  `$context->scope->apply()` (or the `WorkforceQueries` helpers that already
  do) before anything is counted. A sub-city viewer never sees a sibling; the
  bureau sees the roll-up. Nothing is filtered in PHP after loading rows.
- **Aggregates only.** `COUNT / SUM / CASE / GROUP BY`, top-N + other,
  subqueries and joins — no `whereIn` with large PHP arrays, no employee row
  ever loaded. Headcount-sized tables (3 000 000 employees) are never
  materialised.
- **Small-cell protection** (`smallCell()` / `suppressSeries()`) on every
  series that could point to a person: occupation and grade breakdowns, the
  released rating distribution.
- **Never a person.** No name, employee number, national id, phone, narrative
  or submitter detail is in any payload; the tests assert it on the JSON.
- **Honest notices.** `NO_DATA`, `NOT_CONFIGURED`, `NEEDS_DATA`,
  `NO_PERMISSION`, `NO_CURRENT_CYCLE`, `SERVICE_LINKAGE_REQUIRED`,
  `FORECAST_DATA_INSUFFICIENT`, `ALL_CLEAR`. Zero is never shown for an
  unavailable source; a missing policy value counts nothing.
- **Thresholds** come from settings only: `SituationSettings::threshold()`
  (`vacancy_rate_percent`) and `IntelligenceSettings` (`baseline_window_days`,
  `anomaly_sigma`, `forecasting_enabled`). The only constants are the minimum
  counts below which a rule never fires (named `public const` on each class).
- **Every insight is explainable**: summary with the figures (`:current`,
  `:baseline`, `:percent`, `:count`), evidence rows, a `change` block, a
  `source` key and a `metric` key. Severity is assigned by the rule, never by
  a model; `confidence` is set only on correlated or forecast insights.
- **Cross-domain correlation never claims causation.** When a volume drops
  ≥ 40 % the business domain asks `TechnicalSignalSource::failuresFor()`;
  only when it answers `known` with a failure rate ≥ 5 % or an incident does
  the insight say the drop "may be influenced by submission failures", with
  confidence `medium`. Unknown → the plain drop insight with no technical claim.

## What each area shows

### Overview (`OverviewAnalysis`)
One headline KPI panel per business area the viewer may open — the area's
own `headline()` panel (`workforce_headline`, `voice_headline`,
`service_headline`, `epms_headline`, `forecast_headline`, `quality_headline`,
`executive_kpis`). An area the viewer may not open is absent, with no notice.
If `System\SystemHealthAnalysis` exists, exposes `headline()` and the viewer
holds `intelligence.system-health.view`, its headline is added. Insights: the
eight most severe from the business areas the viewer may open (the Executive
area is skipped there because it already aggregates the others).

### Executive (`ExecutiveAnalysis`)
- `executive_kpis`: vacancy rate (with the configured limit as `threshold`),
  open complaints, agreement coverage, data-quality checks needing review.
- `executive_attention`: the roll-up rows (own office + each direct child with
  its subtree) where a measure is abnormal — vacancy rate above the configured
  limit, or a vacancy rate / complaint count that is a robust outlier among
  the rows (`Baseline::isAbnormallyHigh`, minimum 5 complaints). Rows without
  a flag are not listed; no flag → `ALL_CLEAR`. Not a ranking.
- Insights: the Workforce, Public Voice, Performance and Data Quality insights
  under the executive grant alone, ranked by severity, plus a Daily Activity
  check: days reported dropped ≥ 40 % against the previous period (minimum
  10) → `daily_activity_drop`, or `daily_activity_drop_with_failures` when
  the technical domain reports failures on `RouteGroups::DAILY_ACTIVITY`.

### Workforce (`WorkforceAnalysis`)
`workforce_figures` (the figures below), `workforce_by_organization` (roll-up
table), `workforce_occupations` and `workforce_grades` (top-N + other,
suppressed), `workforce_leadership_vacancies` (NEEDS_DATA),
`workforce_anomalies`, `workforce_design_gaps`, `workforce_transfers`,
`workforce_transfers_by_organization`, `workforce_assignment_starts` (last
twelve months by month). Insights: `vacancy_rate_above_threshold` (only when
`situation_room.vacancy_rate_percent` is set; high at ≥ 1.5 × the limit),
`assignment_anomalies` (severity by count), `transfer_outflow_abnormal`
(outbound transfers of the period, normalised to 30 days, against the monthly
outbound counts of the twelve months before the period;
`Baseline::isAbnormallyHigh` with `anomaly_sigma`, minimum 5; never for an
unrestricted viewer, who has no "outside").

#### The staffing figures — exact definitions

| Figure | Definition | Source |
| --- | --- | --- |
| **Required FTE** | `SUM(required_fte)` over `position_requirements` with status `approved` or `implemented` whose plan (`manpower_plans`) has status `approved` and is not deleted. Null when no approved plan is in scope. | manpower plans |
| **Approved Headcount** | `SUM(approved_headcount)` over the same requirements. Null when no approved plan. | manpower plans |
| **Approved Positions** | `COUNT(DISTINCT position)` of active, non-deleted positions having a `position_establishments` row with status `approved`, not deleted, `effective_from ≤ today` and (`effective_to` null or ≥ today). | establishments |
| **Approved Slots** | `SUM(approved_slots)` of those establishments. | establishments |
| **Capacity** | Rule D-2 (`PositionCapacityService` / `WorkforceQueries::capacityByOrganization`): approved slots effective today, plus one slot for each active position without an establishment. | establishments + positions |
| **Occupied Positions** | Per position, `MIN(current active assignments, capacity)`, summed. Occupants above capacity go to the anomaly count instead. | assignments |
| **Vacant Positions** | Per position, `MAX(capacity − occupants, 0)`, summed; never reduced by another position's excess. | establishments + assignments |
| **Employee Count** | `COUNT(*)` of `employee_assignments` with `is_current = true` and `assignment_status = active` in scope (one current assignment per employee is enforced by a partial unique index). | assignments |
| **Vacancy rate** | Vacant / Capacity × 100. | derived |

None of the five (Required FTE, Approved Headcount, Approved Positions,
Occupied Positions, Employee Count) is derived from another; a gap between
them is a finding. The `kpiHint` of each says what it is not.

Anomalies: employees with more than one current active assignment (`GROUP BY
employee_id HAVING COUNT(*) > 1`, normally impossible thanks to the unique
index); active employees with no current active assignment (attributed to the
scope through any assignment they ever had; employees with no assignment at
all are counted only for an unrestricted viewer); positions whose current
occupants exceed capacity.

Design gaps: active positions without a job description in force (status
`published` or `superseded`, effective today — `JobDescriptionService::inForceFor`)
and without any `position_competency_requirements` row.

Transfers: `employee_transfers` with status `completed` and `completed_at` in
the period; inbound = `to` in scope and `from` outside, outbound the reverse,
internal both inside. An unrestricted viewer sees everything as internal.

### Service (`ServiceAnalysis`)
Only where an authoritative link exists: `daily_activity_items.position_service_id`
(items of reported, non-draft days in the period) and
`employee_service_feedback.position_service_id / service_id`. KPIs: active
position services, services with recorded work, register items naming a
service, services named by the public, share of submissions naming a service,
submissions naming no service. Charts: register activity by service, public
submissions by service (top-N + other), a per-service table (feedback count,
average rating of feedback only, complaints, tip-offs). When fewer than 50 %
of the period's submissions carry a link, the page leads with a
`SERVICE_LINKAGE_REQUIRED` notice and raises `service_linkage_low` (info).
A service is never inferred from `service_text`.

### Public Voice (`PublicVoiceAnalysis`)
Feedback, tip-offs (`suggestion`) and complaints are counted apart, and
employee vs organization targets apart (`COALESCE(target_type, 'employee')`,
as the Situation Room does). Which targets are counted follows
`PublicFeedbackVisibility::visibleTargetTypes()` exactly as
`applyTargetScope()` would confine an inbox; a viewer with neither target
grant gets one `NO_PERMISSION` notice and no insight. Panels: `voice_kpis`
(received per type with previous period, open complaints, unrouted share),
`voice_trend` (SqlDateBucket at the period's unit), `voice_targets` (type ×
target), `voice_by_organization` (roll-up, three series), `voice_status`
(open / outcome / closed per type), `complaints_by_category` and
`complaints_by_service` (top-N + other; `SERVICE_LINKAGE_REQUIRED` when no
complaint names a service), `complaints_backlog` (open at period start and
end, change, unrouted count and share).

"Open at T" = submitted by T with `COALESCE(resolved_at, closed_at)` null or
after T. "Unrouted" = status `unrouted` among the period's tip-offs and
complaints (feedback is not routed).

Insights:
- `complaint_spike` (high): complaints of the last seven days of the period
  against the weekly counts of the baseline window before them
  (`baseline_window_days`, stretched to at least five weeks because the robust
  baseline needs five points); `Baseline::isAbnormallyHigh` with
  `anomaly_sigma`, minimum 5.
- `complaint_drop` (info) / `complaint_drop_with_failures` (medium, confidence
  medium): the same week at ≤ 60 % of the baseline median (median ≥ 5), with
  or without a known failure signal on `RouteGroups::PUBLIC_FEEDBACK`.
- `repeated_complaint_category` (medium): one category ≥ 40 % of the period's
  complaints with ≥ 10 complaints in it.
- `complaint_backlog_growth` (medium; high at ≥ 50 %): open at period end
  ≥ 10 and > 25 % above open at period start.
- `unrouted_submissions` (medium): unrouted ≥ 5 and ≥ 20 % of routable.

Complaint counts are never a performance score and no employee is ever named.

### Performance (`PerformanceAnalysis`)
Cycles with `is_current = true` (cycles are policy objects and are not scoped;
everything under them is scoped by `organization_id`). Panels: `epms_kpis`
(plans published, agreements in place = distinct employees with an agreement
in `AGREED / ACTIVE / UNDER_REVIEW / FINALIZED / CLOSED`, coverage against
employee count, reviews completed, results released, calibration sessions in
`DRAFT / IN_PROGRESS`, appeals in `SUBMITTED / UNDER_REVIEW`), `epms_cycles`
(table of current cycles with their phase end dates), `epms_agreements`,
`epms_reviews` (review type × `ReviewStatus`), `epms_results` (current
results by `ResultStatus`), `epms_ratings` (RELEASED only, suppressed),
`epms_overdue` (agreements still `DRAFT / PENDING_* / RETURNED` after
`planning_end_date`; mid-year / year-end reviews not `COMPLETED` after their
review end date; `NEEDS_DATA` when no current cycle carries those dates),
`epms_reporting_gaps` (current `kpi_targets` of `PUBLISHED` plans whose
period overlaps the view's, with no `kpi_actuals` row for the overlap).
Scores are never summed or averaged across people. Insights:
`epms_completion_below_previous` (only once every current cycle's planning
phase has ended, against the most recent earlier cycle with ≥ 10 agreements,
drop ≥ 10 %), `epms_overdue_steps`, `epms_reporting_gaps`.

### Forecasting (`ForecastingAnalysis`, `Forecaster`)
Shown only when `intelligence.forecasting_enabled`; otherwise one
`NOT_CONFIGURED` notice. Two series over the last twelve **complete** months
in scope, starting at the month of the first record (months before the first
record are absence of data, not zeros): complaints received per month, and
vacant slots as of each month end. Each series gets a history chart, a
three-month forecast chart (baseline, lower and upper 95 % band) and a method
table (window, points, method, slope, R², confidence, every limitation).
Under six points → `FORECAST_DATA_INSUFFICIENT`.

`Forecaster::linear()` is ordinary least squares with a residual-based
prediction band (`1.96 · s · sqrt(1 + 1/n + (x − x̄)² / Sxx)`), floored at
zero; confidence `high` at ≥ 12 points and R² ≥ 0.7, `medium` at ≥ 9 and
≥ 0.4, else `low`; limitations `linear_trend_only`, `no_seasonality`,
`floored_at_zero`, plus `short_history` (< 12) and `low_fit` (R² < 0.4).
Insights `forecast_complaints_rising` / `forecast_vacancies_rising` (low) when
the slope is positive, R² ≥ 0.4 and the third month is ≥ 20 % above the last
actual. Snapshot persistence is phase 4.

### Data Quality (`DataQualityAnalysis`)
`quality_summary`, `quality_checks` (check, severity, count, state, impact,
remediation — with `href` to the page where it is fixed when the route
exists), `quality_hierarchy` (`NEEDS_POLICY_DECISION_REPORTING_HIERARCHY`
when no hierarchy is published) and, for an unrestricted viewer only,
`quality_public_feedback` (the FAIL / WARN findings of
`PublicFeedbackDataQuality::run()`, counts only — those checks are
installation-wide and load id samples, so they are not run per scope).

| Check | Severity |
| --- | --- |
| duplicate current active assignments per employee | FAIL |
| active employees without a current assignment | WARN |
| positions above capacity | FAIL |
| approved establishments with no occupant | WARN |
| current assignments whose organization is absent from the published closure | FAIL |
| positions without occupation | WARN |
| organization units without a type (no `organization_unit_type_id` and blank `unit_type`) | WARN |
| positions without a job description in force | WARN |
| positions without competency requirements | WARN |
| unmapped legacy positions (`LegacyMappingService` UNMAPPED_LEGACY rule, in SQL: no occupation, no work link, no published profile, no JD in force) | WARN |
| organizations with an active feedback QR but no `public_contact_phone` | WARN |
| tip-offs / complaints without `category_id` | WARN |
| `effective_to < effective_from` on assignments / positions / establishments | FAIL |

Insights: `data_quality_fail` for every FAIL check with count > 0 (severity by
count). Nothing is corrected.

## Baselines used

| Where | History | Statistic |
| --- | --- | --- |
| Complaint spike / drop | weekly counts, ≥ 5 weeks before the last week of the period (`baseline_window_days`) | `Baseline::zScore` (median / MAD), `anomaly_sigma` |
| Transfer outflow | monthly outbound counts, 12 months before the period | same, current normalised to 30 days |
| Executive attention | the roll-up rows' own values | robust outlier among rows |
| Backlog growth | open at period start | percent change |
| EPMS completion | previous cycle's agreements | percent change |
| Forecasts | 12 complete months | least squares |

## Privacy rules

Aggregates only; small-cell suppression on person-level breakdowns; targets
confined to the viewer's grants; no narrative, submitter, name, employee
number, phone or national id in any payload (asserted by the tests against
the serialised payload); complaint counts are volumes, never scores; cache
keys carry scope, filters and grants fingerprints (`SituationCache`).

## NEEDS_* and assumptions recorded in phase 2

| Code | Where | What |
| --- | --- | --- |
| NEEDS_DATA (leadership vacancies) | Workforce | No authoritative marker of a leadership position: `position_requirements.position_type` is a free employment-type string (default `permanent`); no `positions` column marks leadership. Not inferred from titles. |
| NEEDS_DATA (EPMS overdue) | Performance | Shown when no current cycle records `planning_end_date` / review end dates. |
| NEEDS_DATA_SERVICE_LINKAGE | Service, Public Voice | Submissions without `position_service_id` / `service_id` are reported as unlinked, never inferred from `service_text`. |
| NEEDS_POLICY_DECISION_REPORTING_HIERARCHY | Data Quality | No published hierarchy → the hierarchy reference check cannot run. |
| NOT_CONFIGURED | Forecasting | `intelligence.forecasting_enabled` is off by default (D-2). |
| NOT_CONFIGURED (implicit) | Workforce | No `situation_room.vacancy_rate_percent` → no vacancy-rate insight. |
| Assumption | Forecasting | Monthly vacancies are RECONSTRUCTED as of each month end from establishment and assignment effective windows on positions active today (no snapshots exist); stated as a limitation on the panel. |
| Assumption | Public Voice | "Open at a date" uses `COALESCE(resolved_at, closed_at)`; statuses are not replayed from history. |
| Assumption | Workforce | Employees active but with no current assignment are attributed to the scope through any past assignment. |
| Assumption | Data Quality | `PublicFeedbackDataQuality::run()` is installation-wide and shown only to unrestricted viewers. |
| Observation | Situation Room | Several SR analytics use `CAST(… AS VARCHAR(64))`, which MySQL/MariaDB reject in `CAST`; the business analyses use the driver-aware `BusinessQueries::idText()` instead. Not changed here (other engineer's area). |
| NEEDS_LANGUAGE_REVIEW | labels | Amharic terminology of `intelligence_business.php`. |
