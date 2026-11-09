# Assessment dashboard

## Model audit

The existing dashboard is `Assessments/Oversight/Dashboard`, served by
`assessment-oversight.dashboard`. Extend this page rather than creating a second
oversight module. Cycles own explicit institution participation and employee
eligibility snapshots. Records link the employee, cycle and immutable form
version; response rows are evaluator assignments with an optional submission
timestamp. Reviewed/acknowledged records carry the final percentage. The cycle
pins a versioned result-band policy. Institution submissions have a separate
review/sign-off lifecycle.

Reliable metrics come from AssessmentCoverageService: eligible = assessed +
unassessed; exclusions are separate; coverage is assessed / eligible * 100,
or unavailable for an empty denominator. Gender includes other and unknown.
AssessmentOversightQueryService supplies institution status, reasons, form use
and peer completion; AssessmentDataQualityService supplies implemented issues.
Response drafts do not have a reliable start timestamp, so an evaluator
in-progress metric cannot be inferred. Assessment opening/completion deadlines
are absent; only configured cycle/submission/verification dates can be shown.

## Access and queries

The oversight dashboard requires assessment_oversight.view_dashboard. Existing
separate permissions govern demographics, results, employee drill-down and
data quality. Organization/unit scope is applied on the server. Employee and
evaluator record access remains in the participant-authorized records module.
Cycle is selected explicitly, or defaults only when exactly one cycle is active.
Coverage caches include cycle, scope, filters and a changing data version.
The institution table retains server pagination and selected cycle in links.

## Audit findings

The previous dashboard exposed demographic institution fields and data-quality
details to aggregate-only viewers, discarded pagination metadata, and linked
eligible totals to an employee list that also included exclusions. These paths
need explicit payload filtering and consistent drill-down conditions.
Institution completion must also require no blocking issues.

No production-volume query-plan or browser accessibility certification has been
performed. Previous-cycle comparisons are not equivalent to daily progress
history; differences in policy/population must remain visible.

## Changes verified in this iteration

- Removed demographic and data-quality fields from unauthorized dashboard
  payloads, including the paginated institution data.
- Preserved institution pagination; eligible drill-down explicitly selects
  eligible employees. Institution and submission lists accept an authorized
  organization filter.
- Added institution selection and scoped unit summaries, form deployment,
  peer evaluator counts, configured reasons, gender chart and action-required
  links. All data comes from existing domain services.
- Optional operational projections report errors server-side and return an
  unavailable state. No new cache or index was introduced.
- Cycle dates use the centralized calendar formatter.
- Institution completion rejects blocking employee and cycle issues.

## Remaining specification work

The full requested dashboard specification is not yet complete. Personal
employee/evaluator dashboard variants, full form/version/status/gender/band
dashboard filtering, band drill-down, deferred secondary loading, a fully SQL
institution-status aggregation (the existing implementation maps institution
rows), production-scale query plans, and browser verification remain.
Unit-filtered data-quality navigation preserves the authorized unit.
Current evaluator totals describe peer assignments on existing records; they
must not be presented as the total expected evaluations for unassigned employees.
No assessment opening/deadline fields or evaluator draft-start timestamps were
invented.
