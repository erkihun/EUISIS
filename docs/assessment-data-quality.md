# Assessment data quality

`AssessmentDataQualityService` evaluates each rule as one SQL query over the
cycle. Each query yields `(organization_id, employee_id, record_id, detail)`
and is restricted to the viewer's scope.

- **Summaries** are grouped counts per rule and institution, cached with the coverage data version.
- **Issue lists** are paginated per rule and returned normalized: `code`, `severity`, `organization`, `employee` (nullable), `details`, `resolution_hint`, `is_blocking`.
- No stack traces or internal errors are exposed.

Only **BLOCKING** issues stop an institution submission. Warnings and
information never do.

## Rules

| Code | Severity | Detects |
|---|---|---|
| `ELIGIBILITY_NOT_FINALIZED` (cycle) | blocking | The snapshot is not finalized |
| `NO_RESULT_BAND_POLICY` (cycle) | info | No band policy pinned |
| `NO_APPLICABLE_FORM` | blocking | Eligible employee whom no published form targets (population rule `all_assigned`) and who has no approved exception |
| `MULTIPLE_APPLICABLE_FORMS` | blocking | Target rules tie between forms |
| `MISSING_ASSESSMENT_ASSIGNMENT` | blocking | Eligible employee with a resolved form but no record and no approved exception |
| `UNFINALIZED_ASSESSMENT` | blocking | Record still `assigned` or `submitted` (not reviewed, no reason recorded) |
| `MISSING_EVALUATOR` | blocking | Fewer evaluators assigned than the version's evaluator schemes require |
| `INVALID_EVALUATOR` | blocking | The employee is one of their own evaluators |
| `EVALUATOR_INACTIVE` | warning | Pending evaluation by an inactive account |
| `EVALUATOR_OUTSIDE_SCOPE` | warning | Evaluator's current assignment is not in the record's institution |
| `EVALUATOR_NOT_COMPLETED` | info | Pending evaluations on open records |
| `MISSING_POSITION` | warning | Eligible employee without a position on the snapshot assignment |
| `INVALID_ASSIGNMENT` | warning | Record under a different institution from the snapshot |
| `RECORD_OUTSIDE_POPULATION` | warning | Record for an employee not in the snapshot |
| `MISSING_GENDER` | warning | Eligible employee with no gender in master data |
| `UNRECOGNIZED_GENDER` | info | Gender value outside the known male and female values |
| `INVALID_SCORE` | blocking | Finalized record with no percentage, or a percentage outside 0–100 |
| `FORM_ASSIGNMENT_MISMATCH` | blocking | Target rules expected form A, but the record used form B |
| `UNAPPROVED_FORM_VERSION` | blocking | Record uses a draft or archived version |
| `FORM_VERSION_MISMATCH` | warning | Same form but a version different from the expected one |
| `DUPLICATE_ASSESSMENT` | blocking | More than one record for an employee in the cycle |
| `RESULT_WITHOUT_REQUIRED_COMPONENT` | blocking | Finalized with fewer submitted evaluations than required |
| `EXCLUSION_WITHOUT_REASON` | blocking | Excluded row without a reason, or a record marked unassessed without a configured reason code |
| `PENDING_EXCLUSION_REQUEST` | blocking | An exclusion request awaits a decision (totals would change) |
| `UNCLASSIFIED_RESULT` | warning | Assessed percentage outside every band of the pinned policy |

Evaluator compliance on the dashboard (missing, invalid, inactive, outside
scope, not completed) comes from the evaluator rules above. Peer completion
(required, assigned, completed, aggregate ready or not) is reported per form
version, only for versions that configure a peer scheme.

## Reconciliation failures

Mismatches between aggregates (see
[coverage](assessment-coverage.md#reconciliation)) are not a rule row. They
appear on the dashboard and in the readiness check `totals_reconcile`, which
blocks submission.

## Adding a rule

1. Add the code and severity to `AssessmentDataQualityService::RULES`.
2. Add a `match` arm in `rule()` returning a query with the four columns.
3. Add `rules.<CODE>` and `hints.<CODE>` to both `resources/js/i18n/{en,am}/assessmentOversight.ts`.
4. Add a test in `tests/Feature/Assessment/AssessmentOversightTest.php`.
