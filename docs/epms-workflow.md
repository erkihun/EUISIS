# EPMS — Workflow

The status machines below are enforced in the services. Every transition locks the row, rejects stale changes and is audited.

## 1. Cycle (`PerformanceCycleService`)

```
DRAFT → PLANNING → CASCADED → AGREEMENT → ACTIVE ⇄ MID_YEAR_REVIEW → YEAR_END_REVIEW → (CALIBRATION) → FINALIZED → CLOSED
  └─────────┴──────────┴───────────┴── CANCELLED
```

- **Moving from AGREEMENT to ACTIVE:**
  - the cycle becomes the *current* cycle for its scope (organization, or global);
  - the previous current cycle is released;
  - AGREED agreements become ACTIVE.
- Cycles cannot overlap in the same scope.
- CLOSED and CANCELLED cycles are read-only.
- The mid-year and year-end windows limit when employees can submit self-assessments.
- **Correcting a cycle** (`performance_cycles.update`, `PerformanceCycleService::update`):
  - the code, period and planning window change only while the cycle is DRAFT or PLANNING, because plans, targets and agreements are dated inside it;
  - names and review windows can change until the cycle is closed or cancelled (for example, to extend a year-end review);
  - the organization never changes, and every change is audited as `performance_cycle_updated`.

## 2. Plan (`PerformancePlanService`)

```
DRAFT --submit--> UNDER_REVIEW --approve--> APPROVED --publish--> PUBLISHED --new version--> (new DRAFT) … → previous SUPERSEDED
          ^            |
          └──return────┘  (reason required)
```

- **Submit:** requires the plan's validation list to be empty (see the [cascade rules](epms-cascade-rules.md) §3).
- **Approve:** must be done by someone other than the submitter.
- **Publish:** makes the plan the live version; child plans and agreements can then use it.

## 3. Employee agreement (`EmployeeAgreementService`)

```
DRAFT/RETURNED --send--> PENDING_EMPLOYEE_REVIEW --acknowledge--> PENDING_MANAGER_APPROVAL --approve--> AGREED (→ ACTIVE when the cycle is ACTIVE)
                                   └──return (reason)──> RETURNED <──return (reason)──┘
ACTIVE → UNDER_REVIEW (first result calculated) → FINALIZED (result finalized)
AGREED/ACTIVE/UNDER_REVIEW --close (end date, reason)--> CLOSED
```

- Items can be edited only in DRAFT or RETURNED, and must total 100% before sending.
- **Transfer:**
  - the current agreement closes the day before the new assignment starts;
  - a new DRAFT is created for the new position;
  - a *temporary* (acting) assignment keeps the original agreement open;
  - actuals stay with the agreement and unit they were recorded under, so a unit total never counts them twice.

## 4. During the year

| Step | Who | Notes |
|---|---|---|
| KPI actuals | covering manager (`kpi_actuals.enter`); daily activity or system sync | only the item's configured source counts; verify by a different person |
| Evidence | employee (own) or manager | private file store; verification by a manager |
| Daily activity link | employee, on the daily log | "Related KPI" picks one of the employee's live agreement items; approved quantities feed DAILY_ACTIVITY KPIs as evidence |
| Check-ins | manager (`performance_checkins.manage`); employee adds their note | frequency setting monthly or quarterly; private note never shown to the employee |
| Target amendment | manager or HR | approval creates a new version effective from the chosen date |

## 5. Reviews (`PerformanceReviewService`)

```
DRAFT → EMPLOYEE_SUBMITTED → COMPLETED
            └──return (reason)──> RETURNED → EMPLOYEE_SUBMITTED …
```

- Mid-year review: required only when `require_midyear_review` is on.
- The employee writes the self-assessment, achievements, challenges, contributions, development needs and competency self-ratings. **The employee never picks a score.**
- The manager completes the review with a comment, a private note, improvement actions and at-risk items; at year-end also the competency ratings.
- Ratings (the manager's and the employee's self-ratings) are whole numbers from 1 to the highest level of the active competency scale (5 by default). The forms offer exactly that range and the server refuses anything else, because the competency score is rating ÷ that level × 100. A refused rating leaves the review as it was.
- When `require_yearend_self_assessment` is off, the manager can complete the review without an employee submission.

## 6. Result (`PerformanceResultService`)

```
calculate → CALCULATED (or PENDING_CALIBRATION when require_calibration)
          → finalize → PENDING_RELEASE (or RELEASED when require_result_release is off)
          → release → RELEASED → (appeal)
```

- **Calculate:** allowed on ACTIVE, UNDER_REVIEW or CLOSED agreements, and repeatable until the result is final. The trace is stored with every calculation.
- **Finalize requires:**
  - a completed year-end review (and a completed mid-year review when required);
  - rated competencies;
  - no pending adjustment.
- After finalization the result is immutable.
- **Adjustment** (only when `allow_score_adjustment`): the manager requests it with a reason, a different approver decides, and the calculated score is kept.
- **PIP:** recommended below `pip_threshold` (60); created by the manager and hidden from the employee. The employee drafts IDPs; the manager's IDPs start ACTIVE.

## 7. Calibration (`PerformanceCalibrationService`)

1. Create a session (cycle, organization, optional unit, optional `performance_calibration` committee). The cycle must be open and belong to the organization (or be city-wide), the unit must be one of the organization's, and the committee must be the organization's own calibration committee.
2. Add CALCULATED or PENDING_CALIBRATION results in scope.
3. Committee members decide a calibrated score with a reason for each item. Without a committee, a scoped user with `performance_calibration.manage` decides.
4. Finalizing the session (`performance_calibration.finalize`, in scope) writes CALIBRATION adjustments, sets `calibrated_score` and freezes every result.

Before and after values and reasons are kept, and no forced distribution is applied.

## 8. Appeals (`PerformanceAppealService`)

- **Filing:**
  - own RELEASED result only;
  - within `appeal_window_days` (15) of release;
  - one open appeal per result;
  - reason of at least 20 characters;
  - optional private attachment;
  - number PA-YYYY-00001;
  - routed to the organization's `performance_appeal` committee.
- **Deciding:** only committee members, or a scoped reviewer with `performance_appeals.decide` when there is no committee, can decide UPHELD, PARTIALLY_UPHELD or REJECTED with a reason.
- **Score change:** creates result revision n+1 (APPEAL adjustment). The original row stays for history, and the employee sees the decision in My Portal.

## 9. Notifications

The employee or manager is notified through the existing channels when:

- a plan is assigned to a unit;
- an agreement is ready for the employee, returned or approved;
- an employee submits a self-assessment (to the manager), or a self-assessment is returned;
- a result is released;
- an appeal is decided.

Messages render at read time in the recipient's language (`performance.notifications.*`).

## 10. Where each step happens in the UI

| Screen | Route |
|---|---|
| Performance dashboard | `performance.dashboard` |
| Cycles (create, correct, move) | `performance.cycles.index`, `performance.cycles.update` |
| Strategic goals (return to draft) | `performance.strategic-goals.index`, `performance.strategic-goals.return` |
| KPI library | `performance.kpis.index` |
| Plans and cascading | `performance.plans.index`, `performance.plans.show` |
| Employee agreements | `performance.agreements.index`, `performance.agreements.show` |
| Calibration | `performance.calibration.index`, `performance.calibration.show` |
| Appeals | `performance.appeals.index`, `performance.appeals.show` |
| Reports (CSV export) | `performance.reports.index`, `performance.reports.export` |
| Settings and rating scales | `performance.settings.index` |
| My Portal → My Performance | `employee.performance.index` |
