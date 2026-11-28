# ADR 0039: Employee leave as its own module

- Status: accepted; built (Modules/Leave/README.md)
- Date: 2026-11-26
- Owner request: "I want an employee leave module in this system." The owner
  asked for advice first, then for this design, then ordered the build as a
  complete module ("a proper modular monolith, finished, production ready").
- Pilot scope freeze (2026-11-04): overridden for this module by that order,
  as it was for ADR 0037.
- Owner rules given with the order (2026-11-26): leave without pay exists;
  half-day leave exists; besides annual leave there is a yearly seven-day
  "miscellaneous" leave (ልዩ ልዩ ፈቃድ) that is never carried to the next year
  when unused.

## Context

The system has no leave tables and no leave screens. It does have the seam a
leave module plugs into: `App\Contracts\EmployeeLeaveProvider`, bound today to
`NullEmployeeLeaveProvider`, and read by the daily-activity calendar
(`Modules/DailyWork`) and the field-work conflict check (`Modules/FieldWork`).
Both start honouring leave the day a real provider is bound, with no change to
their own code.

Leave has its own law, its own approval path, its own balances and its own
reports. That is the test ADR 0016 uses for a module.

### Legal source and what is confirmed

Figures below come from the text of Federal Civil Servants Proclamation
No. 1064/2010 E.C. (2017), Articles 37–45. That proclamation was repealed by
Proclamation No. 1353 (gazetted January 2025); summaries of the new text show
the same headline figures, but its articles were not read. Addis Ababa city
institutions follow the city's own Proclamation No. 56/2010 E.C., whose text
was not available.

**NEEDS_CONFIRMATION:** every number in the rule table against Proclamation
56/2010 (city) and 1353 (federal) before go-live. For that reason no number is
written in code (decision 3).

| Leave | Rule (1064/2010) | Counted in |
| --- | --- | --- |
| Annual | 20 days in the first year, +1 per further year of service, at most 30 | working days |
| Annual, new employee | none before 11 months of service; then pro rata for the budget year | — |
| Maternity | 30 days before the expected date + 90 days after delivery, paid | consecutive calendar days |
| Paternity | 10 days at the wife's delivery, paid | working days |
| Sick | at most 8 months in one year or 12 months in four years, counted from the first sick day; first 6 months full pay, last 2 half pay | months |
| Sick, on probation | 1 month paid, with a medical certificate | months |
| Personal matters | mourning, wedding, examination: 7 days in a budget year | days (unit to confirm) |

Further rules the design must carry: postponement of annual leave by the head
of the institution for at most two budget years; no payment in place of leave
except unused leave at termination; earlier government service counts toward
the yearly increment; a medical certificate is required for three consecutive
sick days or more than six in a budget year; sickness during annual leave
interrupts it and becomes sick leave; pre-natal days beyond the expected date
are taken from annual leave (this year's, else next year's); a miscarriage
after six months of pregnancy gives 60 days.

## Decision

1. **One owner.** `Modules/Leave` owns leave types, rules, balances, requests,
   approvals and leave documents. Layout follows `Modules/README.md`
   (`Contracts`, `Domain`, `Application`, `Infrastructure`, `Http`,
   `Providers`). No other module stores leave data. Feature flag
   `FEATURE_LEAVE`, on by default like the other modules in scope.

2. **Tables** (one owner each, listed in `Modules/ownership.php`):

   | Table | Holds |
   | --- | --- |
   | `leave_types` | code, names (en/am), counting unit, paid/half-paid, needs certificate, gender limit, whether it draws on a balance |
   | `leave_rule_sets` | a dated version of the legal figures, with its legal reference; one active per date |
   | `leave_rules` | the figures of one rule set, per leave type (base days, yearly increment, cap, waiting months, limits per budget year, pay bands) |
   | `leave_requests` | employee, type, from/to, counted days, status, reason, the rule set it was counted under |
   | `leave_request_days` | one row per day of an approved or pending request, with `is_full_day` |
   | `leave_request_events` | the timeline of a request: who did what, when, with what comment (the decisions themselves are columns of the request) |
   | `leave_ledger_entries` | every change to a balance: yearly grant, carried forward, taken, returned, adjusted, expired, opening balance |
   | `leave_documents` | medical certificates and other evidence, on the private disk |

   A balance is never stored as a number that can drift: it is the sum of an
   employee's ledger entries for a leave type and budget year. Every entry
   names its cause (the request, the grant run or the officer who adjusted).

3. **The law is data, not code.** Figures live in `leave_rule_sets` /
   `leave_rules`, edited on the module's settings page by a user with
   `leave.settings`. The seeder ships the 1064/2010 figures as a rule set
   marked "to be confirmed". A request keeps the rule set it was counted
   under, so a later change of law does not rewrite old requests.

4. **Counting days.** Working-day types use the platform calendar through
   `App\Backbone\Contracts\WorkingDays` (public holidays and the working
   week); calendar-day types count every day; sick leave counts months inside
   its two rolling windows (one year, four years) from the first sick day.
   The module keeps no holiday list of its own.

5. **Budget year.** Balances are per Ethiopian budget year (Hamle 1 to
   Sene 30), computed with the platform `EthiopianCalendarService`. The
   fiscal calendar inside `Modules/ServiceMonitoring` is private to that
   module and is not used. Date casts are `date:Y-m-d` (Addis time zone rule).

6. **Yearly grant.** A console command `leave:grant-year` writes each
   employee's annual grant at the start of the budget year from
   `service_start_date` plus recorded earlier government service, and writes
   the carried-forward and expired entries. It is idempotent (one grant entry
   per employee, type and year). A new employee's pro-rata grant is written
   when the eleventh month completes.

7. **Request and approval.**
   - Annual and personal leave: the employee asks in My Portal → the direct
     supervisor decides (`App\Backbone\Contracts\ReportingLines::directSupervisor`,
     which already honours acting appointments and delegations) → the HR
     officer of the institution confirms the balance and records it.
   - Sick, maternity and paternity leave are entitlements, not favours: the
     HR officer registers them from the certificate; the supervisor is
     informed, not asked.
   - Postponing annual leave to another budget year needs the head of the
     institution (the law names that office).
   - Balance check and approval run inside one database lock on the
     employee's ledger, so two approvals cannot spend the same days.
   - A request that overlaps another pending or approved request of the same
     employee is refused.
   - Cancelling or cutting short an approved leave returns the unused days
     with a ledger entry; nothing is edited in place.

8. **The existing seam gets a real provider.** `LeaveServiceProvider` binds
   `App\Contracts\EmployeeLeaveProvider` to a query over `leave_request_days`
   (approved, `is_full_day`). The contract stays as written: half days are
   never reported. `NullEmployeeLeaveProvider` remains the binding when the
   feature flag is off.

9. **Public contracts** (`Modules\Leave\Contracts`):
   `EmployeeLeaveSummary::forEmployee($employeeId, $today)` for My Portal's
   leave card (balances, next leave, pending requests) and `Data\LeaveStatus`
   for reports that name statuses. Nothing else is published until a caller
   needs it.

10. **Scope and privacy.** Lists and approvals respect organization scope and
    fail closed (ADR 0003). Sick-leave reasons and medical certificates are
    visible only to the employee and HR officers with `leave.manage`; a
    supervisor sees the dates and the type, not the diagnosis (ADR 0027).
    Every decision and every ledger adjustment is written to the audit trail.

11. **Screens** (full pages, not pop-ups; plain top-to-bottom forms):
    My Portal — my balances, ask for leave, my requests. Supervisor — requests
    waiting for me, my team's leave calendar. HR — all requests, register
    sick/maternity/paternity leave, balances and ledger of an employee,
    adjust a balance, opening balances import. Settings — leave types and
    rule sets. Reports — balances by unit, leave taken by type and period,
    unused leave about to expire.

12. **Not in the module.** No payroll: the module records "full pay" or
    "half pay" on a sick-leave period and stops there. Approval letters go
    through `Modules\Letters\Contracts\LetterIssuer` when the owner wants
    them; the module stores no letter of its own.

## Roles and permissions

`leave.request` (own leave, every employee-linked user), `leave.approve`
(supervisors), `leave.manage` (HR officers: register, confirm, adjust),
`leave.postpone` (head of institution), `leave.settings`, `leave.reports`.
Each must be granted in the default role catalog or listed as admin-only
(ADR 0026).

## Delivered and left for later

Delivered: types and rule sets, the yearly grant, opening balances (one by
one and from a file), every leave type with its limits and evidence,
request → supervisor → HR, cancelling and ending early, postponement and
expiry, the reports page, notifications, and the real
`EmployeeLeaveProvider`.

Left for later, each a small addition on this base: a figure for unused
leave when an employee leaves service, reminders (days about to be closed,
requests waiting too long), a leave card on the My Portal home page
(`EmployeeLeaveSummary` is ready for it), and approval letters through
Letters.

## Decisions taken while building (2026-11-26)

- **Leave without pay** is a leave type (`unpaid`): no balance, no salary,
  supervisor then HR. No limit is seeded, because the legal text was not
  read; the rule set carries `max_days_per_request` and `max_days_per_year`
  for the institution to fill in.
- **Half days.** A type flagged "may be taken as a half day" (annual and
  miscellaneous leave as seeded) accepts a request for the morning or the
  afternoon, on one day or on each day of a period (owner, 2026-11-27: a
  half-day leave is not only for a single day; an employee may take half
  days over several days). Each counted day of the period counts 0.5 day
  and, as the platform contract requires, is not reported as a day of
  leave to other modules.
- **The yearly seven days** are the type `miscellaneous` (category
  `personal`): granted whole every budget year, carry-over mode `none`, so
  the unused days are closed when the year ends. Counted in working days;
  the unit is a switch on the type if the institution reads the law
  otherwise.
- **Carry-over of annual leave** follows the law's wording: unused days
  wait, unusable, until the head of the institution postpones them
  (`leave.postpone`), for at most two budget years, then they are closed.
  An institution whose practice is automatic carry-over sets the rule's
  mode to `automatic`.
- **No supervisor found** (no reporting line, a closed account, or the
  employee is their own manager): the request goes straight to HR instead
  of being refused.
- **HR registers, the supervisor is not asked** for leave entered by the HR
  office; registering confirms it at once unless required evidence is
  missing.
- **Sick-leave windows** are measured back from the first day of the new
  leave, with a month taken as thirty days.
- **Special leave with pay** (court summons, elections) is a type with
  evidence required and no limit seeded.

## The settings page controls the module (2026-11-27)

Owner rule: "the leave settings page must control leave end to end."
Leave > Leave settings (`leave.settings.manage`) holds three things:

- **How leave works** (`leave_settings`, read through `LeaveSettings`, each
  with a default that is the behaviour described in this document):
  `hr_confirmation_required` (off: the supervisor's approval is final and
  takes the days), `min_notice_days` (0), `schedule_enabled`,
  `schedule_requires_approval`, `schedule_auto_count`.
- **Leave types.** A type can be added (code, names, kind, counting unit,
  pay, who it is open to) and every switch of a type changed: in use,
  yearly balance, employees may ask, supervisor first, evidence required,
  half days, and "may start before the day it is asked for"
  (`leave_types.allows_past_start`, seeded on for sick, maternity and
  paternity leave; it replaces the list that was written in code). The
  kind and the balance of a type are fixed once requests or ledger lines
  exist for it.
- **Rule sets**, as before: the figures of the law, draft → in force.

Not switches, on purpose: only the employee asks for leave, nobody decides
their own request, organization scope, and the privacy of sick and
maternity leave. Every change on the page is written to the audit trail.

## Only the employee asks (2026-11-27)

Owner rule: "leave is requested by the employee, signed in with their own
account, not by the HR office."

- The HR "register leave for an employee" page, its popups, its routes and
  the employee look-up are removed. `LeaveRequestService::submit` takes the
  employee of the signed-in user only; `registered_by_hr` stays in the
  table for history and is never set.
- Sick, maternity and paternity leave are asked for by the employee too;
  they still skip the supervisor and go to HR for confirmation from the
  evidence. A pregnancy that ended early is asked for without an expected
  date, within the days the rules give.
- `leave.manage` now means: confirm or refuse requests, end a leave early,
  attach evidence, approve planned periods. Its label was changed; its
  holders were not.
- A request never starts before the day it is made (owner, same day). The
  exceptions are what cannot be asked for beforehand: sick, maternity and
  paternity leave.
- Consequence: an employee without a user account cannot be given leave in
  the system. Leave already taken before go-live is brought in as an
  opening balance, not as backdated requests.

## Leave schedule (2026-11-27)

Owner rule: "when the budget year begins, employees tell the schedule of
the days they will go on leave." The law says the same from the office's
side: annual leave is granted under a program that weighs the office's plan
against the employee's wish and is made known to the employee.

- `leave_plans` holds one row per planned period of an employee for a
  budget year (annual leave). The employee adds and removes periods on My
  leave; a period stays inside one budget year and may not overlap another.
- **The institution approves the schedule** (owner, 2026-11-27: "it must
  be approved"). Each planned period starts as waiting and is approved or
  sent back, with the reason, by the supervisor who reviews the employee's
  work (`leave.approve`) or by an HR officer of the institution
  (`leave.manage`); never by the employee. The employee is told either
  way; a period sent back no longer counts as planned and its days may be
  planned again.
- A planned period holds no days, spends no balance and blocks nothing. An
  approved period becomes leave only when the employee asks for it ("ask
  for this leave" on the schedule, which fills the request form with the
  planned dates); the request is then decided as any other, and the period
  remembers the request it became. Leave that was not planned can still be
  asked for directly.
- Supervisors see the schedule of the people whose work they review
  (`leave.view_team`), HR officers that of the institutions in their scope
  (`leave.view_org`), on Leave > Leave schedule, with how many employees
  have given a schedule and, for HR, how many have not.
- My leave reminds an employee who has planned nothing for the year.
- **An approved period is counted by the system** (owner, 2026-11-27: "if
  the schedule was approved and the employee did not ask to change it
  before the day comes, the system counts the leave by itself"). On the
  first day of an approved period `leave:start-planned` (scheduled three
  times a day, and run for the employee when they open My leave) records
  it as approved leave and takes the days from the balance; the approval
  of the schedule is the decision, so no second request or decision is
  asked for. The request's history says it was counted from the schedule
  and the employee is told.
- **Changing the schedule** is done before the first day: the employee
  takes the approved period off the schedule and plans another, which is
  approved again. From its first day an approved period can no longer be
  removed; ending the leave early is the HR officer's action.
- A period that cannot be counted (too few days left, the days already on
  another request, the employee no longer in service) keeps the reason,
  shows it on the schedule pages and is tried again on every run.
- Not built: a notice sent to every employee on Hamle 1, and a deadline
  after which an employee without a schedule is reported.

## Not decided here

- The city proclamation's own figures (56/2010) and the 1353 articles: the
  seeded rule set is marked "not confirmed" until the institution has
  checked them.
- The legal limits of leave without pay and of special leave.
- Opening balances: the cut-off date and who signs the figures off.
- Whether Leave should also refuse dates that overlap approved field work
  (it may read `Modules\FieldWork\Contracts`; no cycle arises). Field work
  already refuses dates that overlap approved leave.
- A leave that crosses the end of a budget year is counted against the
  year it starts in.

## Consequences

- Daily-activity calendars, missing-activity reports and field-work conflict
  checks start honouring leave with no change on their side.
- A change of law is a new rule set on a settings page, not a release.
- Balances are explainable line by line, at the cost of a ledger table that
  grows with every grant and request.
- Go-live in an institution needs its opening balances first; without them
  every balance starts wrong.

## Migration impact

New tables only; no existing table changes. New migrations must run on MySQL
(live) and PostgreSQL/SQLite (tests).

## Sources

- Federal Civil Servants Proclamation No. 1064/2017, Articles 37–45:
  <https://www.lawethiopia.com/images/federal_proclamation/proclamations_by_number/1064.pdf>
- Federal Civil Servants Proclamation No. 1353 (Ministry of Justice):
  <https://justice.gov.et/am/law/federal-civil-servants-proclamation-4/>
