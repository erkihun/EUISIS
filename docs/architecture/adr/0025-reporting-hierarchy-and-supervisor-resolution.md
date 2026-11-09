# ADR 0025: Reporting hierarchy and supervisor resolution

- Status: accepted (2026-11-02)
- Context: EUISIS records organization units (`parent_unit_id`), positions (`is_unit_head`), employee
  assignments with effective dates, and versioned position profiles with `reports_to_position_id`,
  but nothing connected them into "who is this employee's supervisor". Field work, daily activity,
  assessments and EPMS each took the line manager from the Daily Work reviewer assignments (a user
  named per organization, unit or employee), which must be maintained by hand and drift from the
  organogram. There was no acting appointment, no general delegation, and the one-head-per-unit
  rule lived only in the position actions. The owner delegated the business-rule choices; this ADR
  records them.

## Decision

1. **One authoritative reporting model, resolved per date.** The supervising POSITION of a position
   is found in this order of precedence (`PositionReportingGraph`):
   1. the published position profile in force on the date (status `published` or `superseded`,
      effective dates covering the date, highest version) with a `reports_to_position_id`;
   2. a member of a unit → the unit's head position (`is_unit_head`);
   3. a unit head → the head of the parent unit; a top-level unit head → the organization's head
      position (a head position placed in no unit);
   4. organization-level staff (no unit) → the organization's head;
   5. the organization's head → the head of the parent organization in the published hierarchy
      version; no parent organization is the one legitimate **top of the hierarchy**.
   Draft, under-review, cancelled, future and expired profiles never change the operational line.
   Explicit lines stay inside the organization; cross-organization reporting exists only at the
   organization-head level and only through the published hierarchy.
2. **The PERSON is whoever holds the supervising position on the date** (`OccupantResolver`):
   assignments in force on the date (any status but `pending_transfer`, dates covering the date)
   plus acting appointments in force. An acting appointment outranks the substantive holder while
   it runs. Employment status is read as of the date through the status history. The subject's own
   placement is the one assignment covering the date, or among several the one marked current;
   anything else is `ambiguous_assignment` (the database already keeps one current row per employee).
3. **Nothing is guessed.** Each of these is its own `ReportingOutcome`, shown and recorded, never
   skipped to a higher level: no assignment, several assignments, no position, unit without a head,
   organization without a head, vacant head, several holders, overlapping acting appointments,
   profile pointing at a missing or inactive position, inactive supervising position, inactive unit,
   self-reporting, loop, structural contradiction (two heads, two parent organizations). Only
   `top_of_hierarchy` is a legitimate "nobody".
4. **Permission AND relationship, never the position alone.** New permissions
   `reporting-lines.view_team`, `reporting-lines.delegate` (the new, unassigned `Supervisor` role),
   `reporting-lines.view_any` (HR Officer, Organizational Admin, Auditor) and
   `reporting-lines.manage` (Organizational Admin) say what; `ReportingLinePolicy` and
   `ReportingAuthority` add the organization scope and the resolved relationship. One's own line
   needs no permission. Nobody acts on their own record by any route. Workflow actions keep their
   own permissions (`field_work.approve` and so on). Nothing is granted to existing users by the
   migration, which creates no role; the Supervisor role is created by the role seeder (or on the
   Roles page) and handed out by an administrator.
5. **Temporary authority is explicit and bounded.** An *acting appointment* fills one position for
   a period (no overlap on the same position, same organization, not the substantive holder, an
   active position). A *delegation* lends named actions from the `SupervisorActions` catalog to a
   colleague of the same organization; it never moves the line, is checked live against the
   delegator's authority on the day (so it can never exceed it), excludes the delegator's own record,
   and cannot be delegated again. Both carry start/end dates, reason, authorising actor, revocation
   with actor and reason, and audit events.
6. **Integrity at the database.** One head position per unit and per organization is a partial
   unique index (PostgreSQL, SQLite) or a VIRTUAL generated column with a unique index (MySQL).
   `EnsureSingleUnitHead` checks under the unit/organization row lock inside the position
   transaction and turns the index's refusal into the form's validation error. Where legacy data
   already holds two heads the migration leaves the index out and reports it;
   `reporting:diagnose --preflight` lists the duplicates and `reporting:ensure-head-indexes`
   installs the index afterwards. Profile lines are refused when they would loop through explicit
   or inferred lines, at draft time and again at publish; marking a head whose profile reports into
   its own unit is refused.
7. **Workflows switch behind a flag.** `FEATURE_STRUCTURAL_REPORTING` (default off) makes the
   Daily Work contract (`DailyWorkReviewers::directManagerResolution`) ask the structure first and
   fall back to the explicit reviewer assignments when the structure names nobody; off, behaviour is
   exactly as before. Field work records which rule named the supervisor (`supervisor_basis`) and,
   when a delegate decides, for whom (`decided_for_user_id`). Authority is re-checked live at
   decision time against the request's snapshot placement, so a replaced head cannot decide and the
   successor can; completed decisions are never rewritten. Daily-activity review coverage, EPMS and
   assessments keep their coverage and committee rules; only the "direct manager" question moves.
8. **Remediation is human.** `reporting:diagnose` is read-only. `reporting:apply-unit-heads` marks
   heads from an HR-written CSV (organization code, unit code, position code) through the position
   update action, with `--dry-run`, and skips any row that is not an exact match. Nothing is inferred
   from titles.

## Consequences

- One service (`ReportingLineResolver`, published as the `App\Backbone\Contracts\ReportingLines`
  contract) answers direct supervisor, chain, direct and indirect reports and "supervises";
  graphs are built once per organization and date for the request (no cross-request cache, so no
  invalidation rules), people are resolved in bulk, reports are query builders that paginate.
- Reviewer assignments remain the fallback and the coverage model; a deployment can leave the flag
  off, configure heads, read the diagnostics, and switch on when the organogram is complete.
- The reporting-line pages work either way, so an administrator sees the structural answer before
  any workflow depends on it.

## Not decided here

- Whether indirect supervisors should ever decide direct-supervisor actions (today only `view_team`
  is allowed indirectly). Owner input is needed before widening.
- Which committees or HR steps, if any, should consult the structural chain beyond the direct
  supervisor. This ADR leaves every HR, committee and final-approval authority as it was.
