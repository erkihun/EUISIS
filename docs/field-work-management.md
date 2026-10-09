# Field Work Management

Amharic: የመስክ ሥራ አስተዳደር

Official work done away from an employee's duty station: inspection,
supervision, technical support, monitoring, site visits, field verification,
service delivery. A field work request is planned, approved by the immediate
supervisor, checked in to and out of with GPS, and then closed.

Related: [navigation](field-work-navigation.md),
[security and privacy](field-work-security.md),
[attendance integration](field-work-attendance-integration.md).

## Audit baseline (October 2026)

Before this module, EUISIS had no field work implementation at all. The only
match for the module's terms was `DailyActivityCategory::FieldWork`, a
category label for daily activity items. There were no field work tables,
models, routes, pages, permissions, translations or tests, and no sidebar
entry, so there was nothing to fix or merge. The module was built new.

### Reconciliation with the earlier implementation on `main`

While this module was in review, a second, independent Field Work
implementation was committed to `main` (`98ba71b`). The two were reconciled.
This module is the base, and the earlier one's distinctive pieces were ported
into it:

| From the earlier implementation | Here |
|---|---|
| GPS policy in System Settings (`FieldWorkGpsPolicyService`, `field_work_gps` group, `system-settings.manageFieldWorkGps`) | Wired into check-in/out and completion (see [GPS policy](#gps-policy)) |
| `EmployeeAvailabilityService` + `EmployeeAvailabilityStatus` | Rewritten on participant check-ins; **Team Availability** page |
| Attendance reconciliation shape | `FieldWorkAttendanceService::reconcileEmployeeDate()` |
| `FieldWorkTypeSeeder` (10 starter types) | The one starter catalog (seeder and migration) |
| CI workflow, `phpunit.pgsql.xml.dist`, PostgreSQL test-database guard | Kept; the CI workflow fixed (PHP 8.3, `bcmath`, Vite build) |
| Sessions, location service/controller, duplicate pages | Superseded by participant check-in/out and the existing pages |

**Database.** The earlier `2026_10_09_120000_create_field_work_management_tables`
is kept unchanged, because it may have run somewhere. Its next migration
(`120100`) wrote a non-existent `permissions.description` column and failed
on every database, so nothing after `120000` ever ran. Those later files
were replaced. `2026_10_09_122000_create_or_convert_field_work_schema` takes
every database to the current schema by the same path:

- `field_work_types` stays in place, with `sort_order`, `created_by` and
  `updated_by` added.
- `field_work_requests` and `field_work_participants` rows are read, the old
  tables are dropped, the current tables are created, and the rows are
  re-inserted with their ids, references, decisions (approver employees
  mapped to their user accounts), snapshots and team membership.
- `field_work_number_sequences` is dropped.

Each converted request gets a history row recording the conversion. The
conversion is idempotent, and it is tested on SQLite and verified on
PostgreSQL 16, including from a real stuck `120000` state with rows.

The domains it would integrate with do not exist yet either: **attendance,
leave, training, official travel/mission, and an employee-availability
service**. Where a contract already exists (`EmployeeLeaveProvider`), the
module uses it. Everything else is listed under [NEEDS_DECISION](#needs_decision).

## Domain boundary

Field work is **not** an employee transfer, leave, training, a mission,
remote work, an attendance correction, daily work or EPMS.

- **It never creates, changes or closes an `EmployeeAssignment`.** The
  requester's and each participant's placement (organization, unit, position,
  assignment) is read once and snapshotted on the request. The E2E test
  checks that every assignment row is byte-for-byte unchanged.
- It does not create Daily Activity entries, and GPS presence does not
  produce any performance score.
- It writes no attendance or biometric data (see the attendance doc).

## Module map

```
Field Work Management   (admin sidebar group, docs/field-work-navigation.md)
├── Dashboard            field-work.dashboard          /field-work
├── Field Work Requests  field-work.requests.index     /field-work/requests
├── Pending Approvals    field-work.pending            /field-work/pending-approval
├── Team Field Work      field-work.team.index         /field-work/team
├── Team Availability    field-work.availability.index /field-work/availability
├── Overdue / Unclosed   field-work.overdue.index      /field-work/overdue
└── Field Work Types     field-work.types.index        /field-work/types

My Portal               (employee self-service)
├── My Field Work        employee.field-work.index     /my-portal/field-work
├── New Field Work       employee.field-work.create    /my-portal/field-work/create
└── My Field Work History employee.field-work.history  /my-portal/field-work/history
```

GPS policy settings live in **System Settings → Field Work GPS** (shown with
`system-settings.manageFieldWorkGps`). Not built in this release (see
[Roadmap](#roadmap)): the Field Work Calendar, and reports and exports.

## Code map

| Layer | Where |
|---|---|
| Tables | `2026_10_09_120000_create_field_work_management_tables.php` (earlier schema, kept) then `2026_10_09_122000_create_or_convert_field_work_schema.php`: `field_work_types`, `field_work_requests`, `field_work_participants`, `field_work_location_events`, `field_work_histories` |
| Permissions and starter types | `2026_10_09_122100_register_field_work_permissions.php`, `database/seeders/data/field-work-permissions.php`, `App\Support\FieldWork\FieldWorkRoles`, `database/seeders/FieldWorkTypeSeeder.php` |
| Enums | `App\Enums\FieldWork*` (status, destination type, schedule type, GPS event type, GPS validation, participant role, supervisor resolution, history action, monitoring flag) |
| Models | `FieldWorkType`, `FieldWorkRequest`, `FieldWorkParticipant`, `FieldWorkLocationEvent` (immutable), `FieldWorkHistory` (append-only) |
| Workflow | `App\Services\FieldWork\FieldWorkService`: every state change, row-locked |
| Supervisor | `FieldWorkSupervisorResolver` |
| Authorization | `FieldWorkAccess`, `App\Policies\FieldWorkRequestPolicy` |
| GPS | `FieldWorkGeofence` (server-side haversine), `FieldWorkGpsPolicyService` (System Settings policy) |
| Conflicts | `FieldWorkConflictDetector` |
| Attendance read model | `FieldWorkAttendanceService` |
| Availability | `EmployeeAvailabilityService` (docs/employee-availability.md) |
| Lists and figures | `FieldWorkQueryService` |
| Presentation | `FieldWorkPresenter` |
| Notifications | `FieldWorkNotifier`, `App\Notifications\FieldWorkNotification` |
| HTTP | `routes/field-work.php`, `Employee\FieldWorkController`, `FieldWork\FieldWorkManagementController`, `FieldWork\FieldWorkTypeController`, `App\Http\Requests\FieldWork\*` |
| UI | `resources/js/Pages/Employee/FieldWork/*`, `resources/js/Pages/FieldWork/*`, `resources/js/Components/fieldWork/*` |
| Strings | `resources/js/i18n/{en,am}/fieldWork.ts`, `nav.*` keys, `lang/{en,am}/field-work.php` |
| Config | `config/field-work.php` |
| Tests | `tests/Feature/FieldWork/FieldWorkModuleTest.php` |

## Workflow

```
draft ─submit→ pending_supervisor_approval ─approve→ approved ─first GPS check-in→ in_field ─complete→ completed
                 │            │                        │
                 │            ├─return (reason)→ returned_for_correction ─correct + submit→ pending…
                 │            └─reject (reason)→ rejected
                 └─cancel (requester)           └─cancel (requester, only before anyone checks in)
```

- **Submitted** is not a resting state. Submitting resolves the supervisor
  and puts the request in front of them in the same step.
- **Approved and Completed stay separate.** Approval authorises the work;
  completion records that it ended.
- Every transition runs in a transaction on a `lockForUpdate()` row and
  re-checks the current status (`FieldWorkStatus::canTransitionTo`). In a
  race (double submit, approve vs. reject, double approval, double check-in or
  check-out, double completion), exactly one action commits. The other sees
  the new state and is refused.
- Every transition writes a `field_work_histories` row and an audit log
  entry.

### Request content

The requester comes from the signed-in user (`User::employee`). The placement
is their assignment on the current local day. The form has no employee,
assignment, status or supervisor field, and the model does not mass-assign
any of them.

| Field | Rule |
|---|---|
| Type | Must be an active `field_work_types` row. A type deactivated after drafting stays valid for that draft. |
| Destination type | `registered_organization`, `external_organization`, `field_site` or `other_location` |
| Registered | `destination_organization_id` is required. The optional unit must belong to that organization. Any registered organization may be visited: hosting a visit grants the host nothing. |
| External | Name and address are required. Stored as text only; never added to the Organization master data. |
| Site / other | Site name and address are required. |
| Expected point | Optional latitude, longitude and radius (25 m to 50 km), all three or none. Enables the geofence. |
| Schedule | Local date and time from the shared Ethiopian/Gregorian pickers. The return must be after the start, the start no more than 30 days in the past, and the whole span no longer than 90 days. The schedule type (partial day, full day, multi-day) is derived on the server. |
| Team | Optional, needs `field_work.create_team`. Members must be active employees placed today in the requester's organization, at most 30, with no duplicates. Stored in `field_work_participants`, never as JSON. |

### Supervisor resolution

EUISIS records no supervisor on positions or units. The immediate supervisor
is the user named by the explicit **line-manager (reviewer) assignments**
(`daily_activity_reviewer_assignments`). These are the same assignments that
define line-manager authority for Daily Activity, EPMS and assessments, and
resolution reuses `AssessmentEvaluatorResolver::directManager()`:

1. an assignment naming the employee, else
2. the nearest unit up the tree (including sub-units), else
3. the whole organization.

A tie at the deciding level, or no assignment at all, resolves to nobody. The
request is then flagged `supervisor_not_resolved` and waits; it is never
approved automatically. Resolution uses the request's **snapshot** placement,
so a later transfer does not move it to someone else's queue. Authority is
re-checked **live** at decision time: a supervisor who has been replaced can
no longer decide, and their successor can. Nobody decides a request they take
part in. Super Admin has no implicit approval right either: the service checks
identity directly, so the `Gate::before` bypass does not reach it.

### Completion and monitoring

Only the requester completes, and only while the work is `in_field`. They
enter the actual return (it must not be in the future and not before the
actual start) and a completion note, plus an optional outcome and follow-up.
Participants who never checked out are left as they are: no check-out is
fabricated for them.

**Overdue** (open work past its expected return, which is the "not closed"
case) and **check-in missing** (approved work past its start with nobody
checked in) are *derived at read time* by the `overdue()` and
`checkInMissing()` scopes. They are never stored, so no job is needed to keep
them current, and no page visit is needed to detect them. No return time is
invented.

## GPS policy

**System Settings → Field Work GPS** holds the policy. By default nothing is
configured, and an unconfigured policy enforces nothing.

| Setting | Effect |
|---|---|
| `max_accuracy_meters` + `low_accuracy_action` | `record_only`, `require_review` (stored with `review_state = pending_supervisor_review`), or `block` (refused: nothing is stored, so a corrected retry still works) |
| `outside_geofence_action` | The same three choices, for readings outside a request's expected point and radius |
| `require_check_in` / `require_check_out` | Whether the requester's GPS check-in and/or check-out must exist before completion |
| `location_retention_days` | Recorded policy. There is no automatic purge yet. |
| `offline_capture_policy`, `team_capture_policy` | Documented policy state only. Captures are live and per participant. |

Readings that need a human look count toward the dashboard's **GPS
verification issues** figure and its `gps_issues` filter: outside the area,
low accuracy, or pending review.

## Dashboard figures

All figures are scoped SQL `COUNT`s over team coverage plus organization
scope: team members, pending approval, approved today, currently in field,
returning today, check-in missing, check-out overdue, supervisor not
resolved, and completed today. "Attendance conflicts" is not shown because
there is no attendance module to compare against (NEEDS_DECISION).

## Performance (designed for ~180,000 employees)

- No `::all()` and no full lists. Colleague, destination organization and
  destination unit pickers are server-side searches (2+ characters, 20 or 500
  rows at most, throttled).
- Every list is paginated (10–100 rows). Date filters are bounded to 366 days.
- The detail page eager-loads one request's participants and events. Lists
  never load GPS history.
- Indexes: the requester and start; organization, status and start; unit and
  start; supervisor and status; status and expected return; the schedule
  window; type; destination type; destination organization; assignment; and
  decision time. Participants are unique per (request, employee) and indexed
  by employee and by organization. GPS events are unique per (participant,
  event type) and indexed by request and event type, by employee and capture
  time, and by event type and capture time.
- PostgreSQL CHECK constraints guard the coordinate ranges and require the
  return to be after the start.

## NEEDS_DECISION

| # | Decision | Current behaviour |
|---|---|---|
| 1 | Who may see exact GPS coordinates (`field_work.location.view_precise`) | Super Admin and System Admin only. It is withheld from City Admin and Public Service Bureau Admin. |
| 2 | GPS data retention period | Now a **setting** (`location_retention_days`). The policy is recorded, but there is no purge job yet. Data is kept until one is approved. |
| 3 | Refuse an OUTSIDE_EXPECTED_AREA check-in, or record and flag it | Now a **setting** (`outside_geofence_action`). Unconfigured records the reading without enforcing anything. |
| 4 | Accuracy threshold for LOW_ACCURACY | Now a **setting** (`max_accuracy_meters`, with `low_accuracy_action`). The fallback for classification only is 100 m (`FIELD_WORK_GPS_MAX_ACCURACY_M`). |
| 5 | Acting / delegated supervisors | Not supported. There is no general delegation model. |
| 6 | Whether completion needs a supervisor completion review | No review. Completion is final. |
| 7 | Whether field work can be completed without any GPS check-in (GPS unavailable) | Now a **setting** (`require_check_in`, `require_check_out`). Unconfigured allows completing approved work without GPS. A missing check-out is never fabricated. |
| 8 | Conflict checks against training and official travel/mission | Not possible: those modules do not exist. Leave uses `EmployeeLeaveProvider`, whose current binding returns no leave. |
| 9 | Attendance status name and reconciliation | `OFFICIAL_FIELD_WORK` intervals are exposed. Nothing is written until an attendance module exists. |
| 10 | Line managers without `dashboard.view` | They use the My Portal sidebar, so the admin group is hidden from them. See [navigation](field-work-navigation.md#known-gap). |
| 11 | Overdue / check-in reminders and escalation | Not sent. Only the workflow notices exist. |

## Roadmap

These were left out of this release on purpose, so it stays reviewable. Each
needs either its own design or a module that does not exist yet:

- **More availability statuses.** Statuses such as IN_OFFICE, LEAVE,
  TRAINING and OFFICIAL_TRAVEL need those modules to exist first.
- **Location retention job.** It should enforce `location_retention_days`
  once a retention period is approved.
- **Field Work Calendar.** A bounded day, week or month view over
  `FieldWorkRequest::overlapping()`, with no coordinates in the payload.
- **Reports and exports.** The register by employee, organization, unit, type
  and destination; status breakdowns; overdue; GPS verification; duration.
  Exports should use queued jobs, private storage, `csv_safe_row()` and
  separate `field_work.view_reports`, `field_work.export` and
  `field_work.location.export` permissions. Normal exports must not contain
  coordinates.
- **Scheduled reminders** for check-in due, check-out due and overdue. These
  should be idempotent, with one notice per participant per event.
- **Daily Work Register link.** An optional `field_work_request_id` on a
  daily activity, with work location FIELD. It is never created
  automatically.
