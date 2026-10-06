# Daily Activity Register

The Daily Activity Register (ዕለታዊ የሥራ እንቅስቃሴ) records the work an employee did on a working day: what they worked on, the output, progress, blockers and optional evidence.

It is **not** attendance, a timesheet, payroll input, GPS tracking or a performance score. Nothing in EUISIS derives an appraisal score from the number, length or timing of daily activities. Related documents: [workflow](daily-activity-workflow.md), [security](daily-activity-security.md), [production readiness](daily-activity-production-readiness.md).

## Data model

| Table | Holds |
|---|---|
| `daily_activity_logs` | One header per employee per date (`unique(employee_id, activity_date)`), with the work context that applied on that date: `employee_assignment_id`, `organization_id`, `organization_unit_id`, `position_id`. Status, submission count, lateness, review and reopen fields. |
| `daily_activity_items` | The work items of a day (up to 30): title, description, output/result, progress status, optional quantity and unit, optional time spent, challenge, next action, optional `position_service_id`, optional EPMS link (`employee_performance_item_id`, with `kpi_id` and `performance_objective_id` copied from the verified agreement item). |
| `daily_activity_attachments` | Evidence on the private disk (up to 10 per log). |
| `daily_activity_histories` | Every workflow action, with a full item snapshot on each submission. |
| `daily_activity_reviewer_assignments` | Who reviews whom: organization, unit (optionally with sub-units) or named employee, effective-dated. |
| `daily_activity_reminders` | One row per reminder sent (`unique(employee_id, activity_date, reminder_type)`), which makes the scheduler idempotent. |

Dates are stored as Gregorian `date` values. English screens show Gregorian dates and Amharic screens show Ethiopian dates, through the shared calendar services. Reports and exports follow the reader's language.

## Work context

The employee is always the signed-in user's own record; no employee-facing route accepts an employee id. The assignment is resolved for the activity date from assignment history (`EmployeeWorkContextResolver`): current assignment first, then the latest start date, then the id, so a transfer day always resolves the same way. Pending-transfer assignments are not places of work. Without an assignment on the date, entry is refused (`not_assigned`); the system never attaches work to the employee's current placement.

The snapshot on the header is never recalculated. Work done before a transfer stays under the old organization, unit and position.

## Position services

An item may name a service of the position the log is recorded under. The server checks that the service belongs to that position and organization and is active. A service already linked on the log stays valid after deactivation, so a returned day can still be corrected. Position services describe responsibility; items describe work actually done.

## EPMS link

Where the employee has an agreed, active or under-review performance agreement covering the date, an item may link to one of their own current agreement items. The KPI and objective are copied from the verified agreement item, never from the browser. This makes the trace possible:

```
Daily activity item → agreement item → position plan item → unit plan item → strategic goal
```

The link is evidence. Daily quantities reach a KPI actual only through the EPMS `DAILY_ACTIVITY` measurement source, when a manager synchronizes the item, and appraisal scores come only from EPMS calculation rules ([epms-calculation-rules.md](epms-calculation-rules.md)).

## Day states and missing activity

`DailyActivityCalendarService` classifies every employee-day in one place, used by the employee calendar, the manager dashboard, reports and reminders:

```
not employed (status history)        → not employed
no assignment on the date            → not assigned
not a configured work weekday        → weekend
public holiday (incl. recurring)     → holiday
approved full-day leave              → leave
before tracking start / not required → not tracked
otherwise                            → required: draft, submitted, returned, approved, or missing
```

**Missing is computed, never stored.** A required past day with no submission, or with only a draft, is missing. Today is "not yet submitted" until it ends. No placeholder rows are created.

Leave comes through the `EmployeeLeaveProvider` contract. EUISIS has no leave module yet, so the bound `NullEmployeeLeaveProvider` reports no leave; see the production readiness document.

## Settings (System Settings › Daily Activity)

| Setting | Effect |
|---|---|
| `enabled` | Module on or off |
| `require_daily_submission` | Whether working days are expected at all (missing and reminders) |
| `tracking_start_date` | No day before this date is expected |
| `work_week_days` | ISO weekdays that are working days (default Mon–Fri) |
| `timezone` | The day boundary (default Africa/Addis_Ababa) |
| `allow_backdated_submission`, `max_backdate_days` | Backdated entry, counted in calendar days |
| `submission_deadline`, `require_late_reason`, `reject_late_submission` | Deadline time on the activity day; late submissions need a reason, and are refused only if explicitly configured |
| `require_output_result` | Output required on submission |
| `manager_review_required` | Whether review is part of the workflow |
| `evidence_attachments_enabled`, `max_attachment_size_kb` | Evidence uploads |
| `auto_reminder_enabled`, `reminder_time` | Scheduled reminders |
| `notify_on_approval` | Notify employees when approved |

The settings are city-wide. Organization-specific policy is not implemented, because no institution has asked for different rules; it is listed as a policy decision.

## Reports

All reports use the same coverage and day calculation as the screens, so an export can never show a figure the screen does not.

| Report | Formula |
|---|---|
| Daily submission | Per employee for one date: day state, submission time, lateness, item count |
| Unit activity | Per unit: employees, required, submitted (incl. approved), approved, returned, missing, late, leave, items |
| Missing | Required past days without a submission (drafts included, labelled as drafts) |
| Monthly summary | Per employee for a month: required, submitted, approved, missing, leave, holiday, late, items |
| Late, review status | Submitted logs by lateness, and by review state with waiting days |
| By task | Items grouped by position service or category, with progress |
| Employee activity | Per employee volume of items |

Submission rate is `submitted ÷ required`. There are no rankings of employees by activity count, because activity counts do not measure performance.
