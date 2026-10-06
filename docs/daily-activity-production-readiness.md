# Daily Activity production readiness

Assessment date: 2026-10-05. Verdict: **CONDITIONAL READY**. The one condition is leave data; see below.

## Scale (about 180,000 employees)

A city with full compliance writes up to roughly 47 million headers a year (180,000 × 260 working days), plus several items each.

| Concern | Design |
|---|---|
| Missing days | Computed from expected working days minus submissions, leave, holidays and weekends. Never stored as rows. |
| Day calculations over a coverage | `DailyActivityQueryService::eachEmployeeChunk()` walks employees 1,000 at a time in a stable order. Logs, assignments, status history and leave are loaded per batch, so memory and every `IN` list stay bounded. PostgreSQL rejects queries with more than 65,535 parameters, which the earlier single-batch version would have hit at organization or city scale. |
| Row-limited reports | Missing, daily submission and monthly summary stop one row past their limit (500 on screen, 20,000 for export) and are flagged as truncated. Unit activity and the dashboard aggregate every batch. |
| Date ranges | Capped at `MAX_RANGE_DAYS`. |
| Lists | Server-side pagination; coverage applied in SQL. |
| Indexes | `unique(employee_id, activity_date)`; `(activity_date, status)`; `(organization_id, activity_date)`; `(organization_unit_id, activity_date)`; `(reviewed_by, status)`; `(status, submitted_at)`; items by log, position service and EPMS item; reminders unique per employee, date and type. |
| Reminders | Employees in batches of 300 with keyset paging, users resolved per batch in two queries, notifications queued. |

**Not done, and measure first:** city-wide dashboards still evaluate every employee-day in PHP, which takes seconds for 180,000 × 7 days. If dashboards are opened often at city level, add a short-lived cache of the aggregate figures or a nightly summary table, and point reports at a read replica. Partitioning `daily_activity_logs` by year is an option once volume is measured; nothing in the code prevents it.

## Operations

| Requirement | Configuration |
|---|---|
| Scheduler | `* * * * * php artisan schedule:run` (cron). `daily-activities:send-reminders` runs every 15 minutes with `withoutOverlapping()`. Each run is idempotent: the reminder table claims each (employee, date, type) once. |
| Queue worker | `php artisan queue:work --tries=3 --max-time=3600` under a supervisor (see `docs/runbook/deployment-and-rollback.md`). Daily Activity notifications are queued; saving, submitting and reviewing never depend on the queue. |
| Monitoring | Watch `queue:failed`, scheduler output for `daily-activities:send-reminders`, notification-failure warnings in the log (employee id and kind only, never activity text), and slow-query logs for `daily_activity_*` tables. |
| Backup | `daily_activity_*` tables are covered by the PostgreSQL backup and point-in-time recovery strategy. Evidence lives on the private `local` disk under `daily-activity/` and must be in the private-storage backup set. There is no module-specific backup. |
| Retention | No application path deletes a submitted, reviewed or approved log. Drafts are never purged automatically. |

## Migrations

This hardening pass adds no migration and changes no data. The existing schema (`2026_09_23_100000_create_daily_activity_tables`, plus the EPMS link columns added by `2026_09_25_100000_create_performance_management_tables`) already provides the constraints and indexes above. There is no backfill.

## Checklist

| Check | Status |
|---|---|
| One header per employee and date, race-safe | Yes (unique index, insert-or-ignore, locked re-read; tested) |
| Employee identity resolved server-side; IDOR blocked | Yes (tested) |
| Reviewer limited to effective assignments; no self-review | Yes (tested, including expired and future assignments) |
| Organization scope for oversight and exports | Yes (tested) |
| Assignment history preserved across transfers | Yes (tested) |
| Submitted and approved records not silently editable | Yes (tested) |
| Stale review refused | Yes (tested) |
| Evidence private, type- and size-checked, authorized download | Yes (tested). Malware scanning not available in current infrastructure |
| Holidays and weekends excluded from missing | Yes (tested) |
| **Approved leave excluded from missing** | **Logic yes (tested through the provider contract); data no.** EUISIS has no leave module, and the bound `NullEmployeeLeaveProvider` reports nobody on leave |
| No automatic performance scoring | Yes (tested) |
| Exports permission-gated, scoped, audited, formula-safe | Yes (tested) |
| Reminders reach every eligible employee | Yes (offset-paging skip fixed; tested) |
| Calendar-based reports bounded at scale | Yes (chunked; equivalence tested) |
| Localization (English and Amharic, Gregorian and Ethiopian) | Yes |

## The condition: leave

Until a leave source exists, an employee on approved leave appears as **missing** on their leave days and may receive reminders. Before city-wide rollout, do one of the following:

1. Implement `App\Contracts\EmployeeLeaveProvider` against the HR leave system or a leave table, and bind it in `AppServiceProvider` in place of `NullEmployeeLeaveProvider`. Nothing else changes.
2. Until then, roll out per institution with `tracking_start_date` and reviewer guidance that missing days may be leave. Optionally keep `auto_reminder_enabled` off.

## Policy decisions still open

- **Backdating** counts calendar days (`max_backdate_days`). Counting working days instead is a policy choice.
- **Submission deadline** is a time on the activity day. "Next working day" or "N working days" deadlines are not implemented, because no official rule was supplied.
- **Organization-specific rules** are not implemented. All settings are city-wide.
- **Which feedback or activity data counts for EPMS** is governed by EPMS KPI configuration, not by this module.
- **Malware scanning** of evidence depends on hosting infrastructure.
