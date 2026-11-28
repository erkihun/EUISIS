# Leave

Employee leave: leave types, the legal figures, requests and approval, balances
and their ledger ([ADR 0039](../../docs/architecture/adr/0039-leave-module.md)).
One module owns all of it; nothing else stores leave data.

Feature flag: `FEATURE_LEAVE` (`config/features.php`, on by default). Off: every
`leave.*` and `employee.leave.*` route answers 404, the menu entries are hidden
and `App\Contracts\EmployeeLeaveProvider` stays on the null provider.

## What it does

| Who | Where | What |
| --- | --- | --- |
| Employee | My Portal > Leave | balances for the budget year, ask for leave, own requests |
| Immediate supervisor | Leave > Requests for my decision | approve or refuse the requests routed to them |
| HR officer | Leave > Leave register, Leave balances, Leave schedule | confirm the requests employees made, end a leave early, opening balances and corrections, approve planned periods |
| Head of institution | an employee's balance page | postpone unused annual leave to a later budget year |
| Reports reader | Leave > Leave reports | who is away on a day, leave starting soon, leave taken by type |
| Policy administrator | Leave > Leave settings | how leave works, the leave types and their switches, and the rule sets holding the figures of the law |

Only the employee asks, signed in with their own account: nobody enters a
leave request for someone else, the HR officer included. A request never starts
before the day it is made, except sick, maternity and paternity leave, which
are reported after they began.

Workflow: employee asks → supervisor decides → HR officer confirms (the balance
is spent here). Sick, maternity and paternity leave skip the supervisor. When no
supervisor can be named the request goes straight to HR. Nobody decides their
own request.

## Settings

Leave > Leave settings (`leave.settings.manage`) controls the module end to
end, in three cards laid out like the module's lists (popups for editing):

| Card | Controls |
| --- | --- |
| How leave works | HR confirmation after the supervisor (off: the supervisor's approval is final), days of notice, the leave schedule on/off, whether a planned period needs approval, whether approved planned leave is counted by the system |
| Leave types | add a type; names, kind, counting unit, pay, who it is open to, order; switches: in use, yearly balance, employees may ask, supervisor first, evidence required, half days, may start in the past |
| Rule sets | the figures of the law per leave type, draft → in force |

The switches live in `leave_settings` (`Application\Services\LeaveSettings`,
defaults in code); a type's switches are columns of `leave_types`.

## Leave schedule

At the start of the budget year each employee states when they mean to take
their annual leave (My leave > My leave schedule). Each planned period
(`leave_plans`) waits for approval: the supervisor who reviews the employee's
work, or an HR officer of the institution, approves it or sends it back with
the reason on Leave > Leave schedule (`leave.plans.index`), which also counts
the periods waiting and the employees who have and have not given a schedule.
A planned period takes no days until its first day. An approved period that
the employee did not take off the schedule before that day is counted as
approved leave by the system (`php artisan leave:start-planned`, scheduled
three times a day and run for the employee when they open My leave): the
days are taken from the balance and no second decision is asked for. A period
that cannot be counted keeps the reason and is tried again on the next run.

## The law is data

Figures live in `leave_rule_sets` / `leave_rules`, never in code. A rule set is
a draft until it is put in force from a date; the set it replaces ends the day
before. A request keeps the rule set it was counted under, and figures that
requests were counted under can no longer be edited: a change of law is a new
rule set.

The seeded rule set holds the figures of Federal Civil Servants Proclamation
No. 1064/2010 E.C. (Articles 37–45) and is marked **not confirmed**. Before
go-live an administrator checks every figure against the law that binds the
institution (for Addis Ababa: Proclamation No. 56/2010 E.C.) and ticks
"Confirmed against the law". Leave without pay and special leave ship without a
limit: the legal text was not available, so none was invented.

| Leave type (code) | Counted in | Seeded figures |
| --- | --- | --- |
| Annual (`annual`) | working days | 20 a year, +1 per year of service, at most 30; none before 11 months of service; unused days carried only when the head of the institution postpones them, for at most 2 years |
| Miscellaneous (`miscellaneous`) | working days | 7 a year; never carried forward |
| Sick (`sick`) | calendar days | at most 8 months in a year and 12 months in 4 years; 6 months at full pay, then half pay; 1 month on probation; certificate from 3 days |
| Maternity (`maternity`) | calendar days | from up to 30 days before the expected date to 90 days after it; 60 days when the pregnancy ends early |
| Paternity (`paternity`) | working days | at most 10 per request |
| Without pay (`unpaid`) | calendar days | no limit set |
| Special, with pay (`special`) | working days | no limit set; evidence required |

Half days: a leave type with "May be taken as a half day" (annual and
miscellaneous leave as seeded) accepts a request for the morning or the
afternoon half, on one day or on each day of a period (five mornings in a
week, for example); every counted day of the period counts 0.5 day.

## Balances

A balance is never stored. It is the sum of `leave_ledger_entries` for an
employee, leave type and entitlement year: the yearly grant, an opening balance,
days taken, days returned, a correction, a postponement, an expiry. Days are
taken from the oldest year that may still be used and returned to the years they
came from.

- The yearly grant is written when a balance is opened or asked against, and by
  `php artisan leave:grant-year` (scheduled nightly). It is idempotent.
- Annual leave needs `employees.service_start_date`; without it no annual leave
  is granted and the pages say why.
- The budget year is Hamle 1 to Sene 30 (Ethiopian calendar). Working days and
  public holidays come from the platform calendar (`App\Backbone\Contracts\WorkingDays`).
- Opening balances: one by one on an employee's balance page, or a CSV file on
  the Leave balances page (`employee_number,leave_type_code,entitlement_year,days`).

## Contracts

| Contract | Returns | Used by |
| --- | --- | --- |
| `App\Contracts\EmployeeLeaveProvider` (bound to `Application\Queries\ApprovedLeaveProvider`) | approved full-day leave dates per employee | the daily-activity calendar, the field-work conflict check |
| `Modules\Leave\Contracts\EmployeeLeaveSummary::forEmployee($employeeId, $today)` | balances, pending requests and the next leave | available to My Portal |
| `Modules\Leave\Contracts\Data\LeaveStatus` | the request statuses | reports outside the module |
| `Modules\Leave\Contracts\Data\LeaveRoles` | permission sets per default role | `DefaultRoleMatrix` |

Outbound: Core models (`Employee`, `EmployeeAssignment`, `Organization`,
`OrganizationUnit`, `User`), `OrganizationScopeService`, the audit action, and
`Modules\DailyWork\Contracts\DailyWorkReviewers` for the employee's manager.

## Privacy

The reason and the evidence of a sick or maternity leave are health data. The
employee and HR officers holding `leave.view_medical` in scope read them; a
supervisor sees the dates and the type only. Evidence is stored on the private
`local` disk and served only through `leave.documents.download`, which re-checks
the reader and writes an audit entry. Notifications never carry a reason.

## Permissions

`leave.view_own`, `leave.request_own`, `leave.cancel_own` (employee);
`leave.view_team`, `leave.approve` (supervisor); `leave.view_org`,
`leave.manage`, `leave.view_medical`, `leave.adjust_balance`,
`leave.reports.view` (HR officer); `leave.postpone` (head of institution);
`leave.settings.manage` (policy administrator). Every action also checks the
record and the organization scope (`Application\Services\LeaveAccess`).

## Not in the module

- Payroll. A sick leave records how many days are at full and at half pay and
  stops there.
- Official letters. An approval letter, when wanted, is issued through
  `Modules\Letters\Contracts\LetterIssuer`.

## Tests

`tests/Feature/Leave/LeaveModuleTest.php`.
