# Reporting lines and supervisor resolution

How EUISIS knows who supervises whom, what a supervisor may do, and how a deployment turns it on.
Decision record: [ADR 0025](architecture/adr/0025-reporting-hierarchy-and-supervisor-resolution.md).

## 1. The model

| Question | Answered by | Rule |
|---|---|---|
| Which position supervises position P? | `App\Services\ReportingLines\PositionReportingGraph` | 1. published profile in force says "reports to"; 2. unit member → unit head; 3. unit head → parent unit head, top-level unit head → organization head; 4. organization-level staff → organization head; 5. organization head → parent organization head in the published hierarchy, else **top of hierarchy** |
| Who holds that position on the date? | `OccupantResolver` | acting appointment in force first; else the assignment in force (any status but pending transfer, dates covering the date); the employee must be active on the date |
| Which placement is the employee's? | `ReportingLineResolver::assignmentOn` | the one assignment covering the date, or the one marked current among several; otherwise ambiguous |
| Where does it stop? | `ReportingOutcome` | every gap is its own outcome (see below); only `top_of_hierarchy` is a legitimate "nobody" |

Outcomes: `resolved`, `top_of_hierarchy`, `no_assignment`, `ambiguous_assignment`, `no_position`,
`unit_head_not_configured`, `organization_head_not_configured`, `head_vacant`, `ambiguous_occupants`,
`explicit_target_invalid`, `supervisor_position_inactive`, `unit_inactive`, `self_reporting`, `cycle`,
`invalid_hierarchy`. A vacant or ambiguous level ends the chain; authority never jumps over it.

Configuration lives where it already did: the **head of a unit** is the position's "Head of this
unit" mark (Positions › Edit; one per unit, and one head position with no unit per organization);
the **explicit line** is the position profile's "reports to" (Organization Design › Positions),
effective once published; the **organization hierarchy** is the published hierarchy version.

## 2. Authorization

A permission says *what*, the organization scope says *where*, the resolved relationship says
*for whom*. Holding a head position grants nothing by itself.

| Permission | Default roles | Grants |
|---|---|---|
| `reporting-lines.view_team` | Supervisor (new, assigned by an administrator) | My Team; the lines of people under the holder (direct and indirect) |
| `reporting-lines.delegate` | Supervisor | lending one's own supervisor actions |
| `reporting-lines.view_any` | HR Officer, Organizational Admin, Auditor (+ city-wide admins) | any employee's line in scope, the overview and the integrity report |
| `reporting-lines.manage` | Organizational Admin (+ city-wide admins) | acting appointments and delegations in scope |

One's own supervisor and chain need no permission (My Portal › My Supervisor). Nobody acts on
their own record: self-approval is refused for the requester, for a head who is the requester,
and for a delegate acting on the delegator's own record. `ReportingAuthority::decide()` returns
the basis (`direct`, `indirect`, `delegated` with the delegator) that workflows record.

Supervisor actions a delegation can carry (`SupervisorActions`): `view_team`, `field_work.decide`,
`daily_activities.review`, `performance.manage`, `assessments.evaluate`. Only `view_team` is also
granted to indirect (higher) supervisors.

## 3. Workflows

| Workflow | Who reviews / approves | Permission and scope | Supervisor unresolved | Reporting changes while pending |
|---|---|---|---|---|
| Field work request | the direct supervisor resolved for the requester's **snapshot placement**; a delegate holding `field_work.decide` from that supervisor | `field_work.approve` / `field_work.reject` / `field_work.return` + `FieldWorkRequestPolicy` + live `authorityOf()` | request waits with `supervisor_not_resolved`; HR oversight sees it; nobody is auto-approved | authority is re-checked at decision time: the successor decides, the predecessor cannot; `supervisor_basis` and `decided_for_user_id` record what was used |
| Daily activity review | reviewer-assignment coverage (unchanged) | `daily_activities.review*` | unchanged | unchanged |
| Assessment "direct manager" evaluator, EPMS line manager | the Daily Work contract's direct manager (structure first when the flag is on, reviewer assignment as fallback) | the modules' own permissions and coverage | the module's existing "nobody" handling | the module's existing rule |

With `FEATURE_STRUCTURAL_REPORTING=false` (default) every workflow behaves exactly as before.
Completed decisions keep their actor and basis whatever happens to the authority afterwards.

## 4. Temporary authority

- **Acting appointment** (`acting_appointments`): one employee fills one position for a period.
  Rules: active position of the organization, active employee of the same organization, not the
  substantive holder, no overlapping active appointment on that position, end not before start.
  The reporting line resolves to the acting person while it runs; expiry is by date, revocation
  keeps the row with actor and reason. HR with `reporting-lines.manage`.
- **Delegation** (`supervisor_delegations`): a supervisor lends listed actions to a colleague of the
  same organization for a period. Rules: not to oneself, actions from the catalog, the delegator
  must supervise someone on the start date, no overlapping delegation of the same action to the
  same person. Checked live: the delegate may act only where the delegator is the direct supervisor
  that day, never on the delegator's record, and cannot delegate further. A supervisor delegates
  their own (`reporting-lines.delegate`, My Team); HR records one for anyone in scope.

Both write `reporting_lines.*` audit events.

## 5. Screens

| Screen | Route | Who |
|---|---|---|
| Reporting Hierarchy overview + integrity report | `/reporting-lines/overview` | `reporting-lines.view_any` in scope |
| An employee's line, chain, reports, temporary authority | `/reporting-lines/employees/{employee}` | the employee, their supervisors with `view_team`, readers in scope |
| Acting appointments | `/reporting-lines/acting` | `view_any` / `manage` |
| Supervisor delegations | `/reporting-lines/delegations` | `view_any` / `manage` |
| Employee record › Reporting line card | `/employees/{employee}` | readers of the line |
| My Supervisor | `/my-portal/reporting-line` | the employee |
| My Team (+ own delegations) | `/my-portal/my-team` | `reporting-lines.view_team` |

Every list is paginated; employee rows carry number, name, position and unit only.

## 6. Rollout

1. **Preflight (read-only):** `php artisan reporting:diagnose --preflight` — per organization:
   units without a head, vacant or doubled heads, invalid profile lines, loops, overlapping acting
   appointments, assignment gaps; plus the one-head index status and any duplicates blocking it.
   `--json` for a file, `--organization=CODE` to narrow, `--as-of=Y-m-d` for a date.
2. **Migrate:** `php artisan migrate`. Adds `acting_appointments`, `supervisor_delegations`,
   `field_work_requests.supervisor_basis` / `decided_for_user_id`, registers the four permissions,
   adds the matrix permissions to the existing HR Officer, Organizational Admin and Auditor roles,
   and installs the one-head indexes **where the data allows** (otherwise skipped and reported,
   never failed). No role is created and no data is backfilled. Then `php artisan db:seed
   --class=RoleSeeder` (safe to repeat) creates the unassigned `Supervisor` role, or create it on
   the Roles page with `reporting-lines.view_team` and `reporting-lines.delegate`.
3. **Configure:** mark unit heads (Positions › Edit, or `reporting:apply-unit-heads file.csv
   --dry-run` then `--actor=admin@example`), one organization-head position per organization,
   publish profile lines where a unit's inference is wrong. Re-run `reporting:diagnose` until the
   errors are gone; `reporting:ensure-head-indexes` once duplicates are resolved.
4. **Assign:** give the `Supervisor` role to the people holding head positions who should see
   their teams and delegate. Nothing is assigned automatically.
5. **Enable:** `FEATURE_STRUCTURAL_REPORTING=true`, then `php artisan config:clear`. Field work
   submissions from then on name the structural supervisor; reviewer assignments stay as fallback.
6. **Disable / roll back:** set the flag to `false` (workflows return to reviewer assignments at the
   next request; nothing recorded changes). `php artisan migrate:rollback --step=2` drops the two
   tables, the two field-work columns, the indexes and the permissions; completed decisions keep
   `decided_by`. Caching: none across requests, so nothing to invalidate.

MySQL: the indexes use a VIRTUAL generated column (`unit_head_key`, `organization_head_key`);
verify on MariaDB with the usual overrides before pushing.

## 7. Verification

`tests/Feature/ReportingLines/`: resolver rules (member → leader → director → sector → bureau → top;
organization-level staff; parent organization; explicit override; ignored profiles; vacant,
inactive, future, expired, ambiguous holders; acting precedence, expiry and revocation; self and
loops; historical dates; reports and supervises; query bound on a 60-person unit), integrity
(second head refused by action and index, profile and head loops refused, diagnostics, CSV
remediation), temporary authority (appointment and delegation rules, direct / indirect / delegated /
self / out-of-scope decisions), pages (permission and scope on every page, pagination, acting and
delegation flows, employee record card, self-service untouched) and field work (flag off unchanged,
flag on with basis recorded, fallback, successor after a head change, delegate deciding for the
supervisor with history preserved, no self-approval).
