# Grievance Management (የቅሬታ አስተዳደር)

Formal employee grievance case management, integrated with EUISIS HR master data
(organizations, units, employees, assignments, positions, users, scope, calendar,
notifications, audit). This replaces the first grievance module (2026-06-07); its
tables are kept and backfilled (see §3).

## 1. Scope and boundaries

- A grievance is a formal complaint by an employee about a work-related or administrative
  issue, handled through configured stages until a decision is issued, appealed, referred,
  withdrawn or closed.
- The module never duplicates Organization, Employee, Position, User, Calendar,
  Notification, File or Audit data. Permanent Grievance Teams and Directorates are
  existing **organization units**; committees are the only grievance-specific bodies.
- Disciplinary action is a separate process: a grievance can create an explicit, linked
  referral (`grievance_disciplinary_referrals`) but is never converted into one.
- Court Cases is a separate, planned module (`docs/court-cases.md`). A grievance is not a
  court case and is never converted into one. Any future link will be an explicit referral.

## 2. Code map

| Area | Where |
|---|---|
| Schema (expand / backfill / RBAC / defaults) | `database/migrations/2026_09_28_1000*–1003*` |
| Enums | `app/Enums/GrievanceStatus.php`, `app/Enums/Grievance/*` |
| Models | `app/Models/Grievance*.php`, `OrganizationLetterhead`, `OrganizationSeal` |
| Services | `app/Services/Grievances/*` (one responsibility each, below) |
| HTTP | `app/Http/Controllers/Grievances/*`, `routes/grievances.php` |
| Scheduler | `grievances:process-sla` (`app/Console/Commands/ProcessGrievanceSla.php`), every 15 min |
| PDFs | `resources/views/grievances/letter.blade.php`, `report-pdf.blade.php` |
| UI | `resources/js/Pages/Grievances/*`, `resources/js/Components/grievances/*`, types in `resources/js/types/grievances.ts` |
| i18n | `lang/{en,am}/grievances.php`; `resources/js/i18n/{en,am}/grievance*.ts` |
| Tests | `tests/Feature/Grievances/*`, fixture `tests/Support/GrievanceScenario.php` |

Services: `GrievanceCaseAccessService` (who may do what), `GrievanceRoutingService`
(routes, stages), `GrievanceSlaService` (deadlines, pauses), `GrievanceEscalationService`
(timeout/manual escalation, reassign, refer, return), `GrievanceCaseService` (lifecycle),
`GrievanceCommitteeService` (committees, membership, recusal, quorum),
`GrievanceDecisionService` (versions, approval, finalization), `GrievanceApproverResolver`,
`GrievanceEvidenceService`, `GrievanceInformationService`, `GrievanceHearingService`,
`GrievanceAppealService`, `GrievanceCorrespondenceService`, `GrievanceReportService`,
`GrievanceNotifier`, `GrievanceTimeline`, `GrievanceAudit`, `GrievanceSettings`.

## 3. Data model and migration

- `grievances` keeps its columns: `reference_number` **is the case number**,
  `organization_id`/`organization_unit_id` are the complainant's origin, `category_id` the
  category. New columns add assignment snapshot, incident date, priority, confidentiality,
  current stage/handler pointer, intake/withdrawal/closure reasons, retention
  (`record_state`, `legal_hold`, `retention_until`, `archived_at`), respondent, systemic
  tagging and appeal deadline.
- New tables: case stages (+ panel snapshot, case officers, SLA pauses, recusals),
  routes, SLA profiles, approval rules, delegations, external authorities, reason codes,
  decisions (+ approvals, votes), hearings (+ participants), minutes, information
  requests/responses, appeals, evidence (+ custody), notes, tasks, timeline events,
  amendments, letters (+ recipients, attachments, dispatches), letter templates,
  organization letterheads and seals, corrective actions, disciplinary referrals.
- **Migration strategy: expand → backfill → (later) contract.** First-module tables
  (`grievance_assignments`, `_responses`, `_escalations`, `_decision_letters`, `_sla_rules`)
  are read by the backfill and left in place. Dropping them is a separate, deliberate step.
- Integrity guards: `from_stage_id` unique (a stage has one successor → no double
  escalation/appeal); partial unique index "one current stage per case"; `stage_no`
  unique per case; decision `version_no` unique per stage; `appealed_decision_id` unique;
  letter `reference_number` unique; issued letters immutable at model level.
- `grievance_committees` is shared with EPMS (performance appeal/calibration panels):
  existing columns and the values EPMS reads are unchanged. Committee role `secretary`
  was renamed to `writer` by the backfill.

## 4. Lifecycle

```
draft → submitted → [intake review] → accepted/stage 1 → received → under review
  → (information, hearing, minutes) → decision draft → [internal review]
  → [executive approval] → finalized → decision letter issued → decision_issued
  → appeal (next level)  |  close (reason code)  |  auto-close after appeal window (setting)
withdraw: direct before review; after review a request the handler decides (setting)
returned_for_correction → complainant corrects (amendment recorded) → resubmitted
```

- The case number is assigned from Code Rules (`grievance_case`) at first submission and
  never changes. Drafts carry a `DRAFT-…` placeholder that is never shown as a number.
- Only never-submitted drafts can be deleted. Submitted cases are retained; archive
  requires a closed case, a passed `retention_until`, and no legal hold.

## 5. Handlers and routing

- Handler types: `committee`, `organization_unit` (Team/Directorate), `external_authority`
  (e.g. the Administrative Tribunal; routing there keeps the existing tribunal register in step).
- **Administrative hierarchy is not the grievance route.** Every move follows an explicit,
  approved, effective route (`grievance_routes`) for a movement type: initial assignment
  (source = the complainant's organization, optionally "and descendants"), timeout
  escalation, employee appeal, manual escalation, referral, reassignment, return.
  Routes may cross organizations; many sources may share one target.
- A new or edited route is inactive until a **different user** approves it. Existing stages
  keep the route they used; closed cases are never rerouted.
- Initial fallback when no route exists: the complainant organization's single approved
  grievance committee. If none, the case stays at intake with a visible "no handler
  configured" event.
- Appeal and manual escalation fall back to the timeout-escalation route (the "next level")
  when no specific route is configured.

## 6. Deadlines (SLA)

- Profiles per purpose (resolution, approval, appeal filing, information response), handler
  type/handler, category and organization; the most specific active profile wins.
- The profile is **snapshotted** onto the stage (`sla_days`, day type, start point, due date).
  Editing a profile ends it and creates a new version; existing stages are unchanged.
- Clock start: on assignment, on receipt, or on acceptance for review (per profile).
- Working days use the shared public-holiday calendar
  (`App\Services\Calendar\PublicHolidayCalendar`, extracted from Daily Activity) with the
  grievance work week setting. Recurring Ethiopian holidays follow the Ethiopian date.
- Pauses: a member requests; a holder of `grievances.sla_pause` starts/approves. Resuming
  extends the due date by the paused working (or calendar) days and records due-before,
  due-after and paused days; the original due date is kept.
- Executive approval has its own clock (`approval_due_at`); a stage pending approval is
  never timed out against the handler.
- **Automatic escalation**: `grievances:process-sla` (scheduler, `withoutOverlapping`,
  `onOneServer`) sends threshold reminders once each and escalates overdue, unpaused,
  auto-escalating stages. Each escalation locks the case row, re-checks every condition
  and relies on the unique successor constraint, so repeated or concurrent runs escalate
  a stage at most once. No configured route → one "escalation blocked" event + notice.

## 7. Authorization

A permission is necessary, never sufficient. Case access comes from a relationship
(`GrievanceCaseAccessService`):

| Relationship | Grants |
|---|---|
| Complainant (`grievances.view_own`) | own case, safe timeline, issued letters, own files |
| Active, non-recused panel member of the stage's committee | case work per committee role (writer/chair draft; chair leads, confirms minutes, submits, finalizes) |
| Case officer / unit assigning officer (placed in the unit by HR assignment + `grievances.assign`) | unit-stage work; the assigner distributes cases |
| External-authority handler (`grievances.tribunal`) | tribunal stages |
| Resolved approver (position holder or delegate + `grievance_decisions.approve`) | decisions routed to them |
| Intake officer (`grievances.intake_review` in org scope) | cases at intake |
| Oversight (`grievances.oversight_view` in org scope) | read-only; highly restricted = metadata only |

- The complainant and a named respondent never handle the case. Past handlers keep
  read-only history. Routed cases never widen a user's organization scope.
- Separation of duties: preparer ≠ approver, stage handlers ≠ approver (unless the
  `allow_self_approval` setting); route and committee creators cannot approve their own;
  seals need a second approver; nobody records a delegation to or from themselves.
- Roles (`app/Support/Grievances/GrievanceRoles.php`): Grievance Officer, Committee
  Member/Writer/Chairperson, Executive Approver, Administrator (configuration only, no case
  content), Registry Officer (seal/dispatch), Oversight, Administrative Tribunal Officer;
  Employee gains the self-service set; Auditor/Report Viewer gain read/aggregate rights.
  City Admin does **not** receive case-content permissions by default. First-module
  permissions stay in the catalog and their holders were granted the new equivalents.

## 8. Committees, decisions, evidence, correspondence

- **Committees**: composition from settings (default 3–5 members, exactly one Chairperson,
  exactly one Writer). Terms are ended, never deleted. Each stage snapshots its panel;
  membership changes follow open stages. Recusal removes the member from the case panel
  (no view, vote, signature, minutes); a replacement sits on that case only.
  Quorum rule is a setting (`none` until policy defines one).
- **Decisions**: versioned; a return/rejection is corrected by a new version. Approval is
  required only by an approval rule (or a category flag with a rule); the approver is
  resolved from rule → position → current holder → permission (+ delegations).
  Approved ≠ issued: finalize assigns the decision number; issuing the decision letter
  issues the decision and opens the appeal window (appeal-filing SLA profile, if configured).
- **Evidence**: private disk, random names, extension allow-list plus detected-MIME check,
  size limit, SHA-256 at upload and re-checked on download, versioning instead of
  replacement, chain of custody, audited downloads. No antivirus is installed:
  `scan_status` stays `not_scanned`.
- **Correspondence**: template (fixed token whitelist, plain-text replacement, nothing
  executed) → draft → finalize (Code Rules reference number) → sign (signatory = the acting
  user's employee and position; `electronic_approval` or `signature_image`; qualified
  digital signature refused, not available) → seal (approved controlled seal, audited)
  → issue (PDF with letterhead, dual-calendar date, recipients/CC, attachments list, page
  numbers, Abyssinica SIL embedded; SHA-256 stored; immutable) → dispatch (in-app, email
  and SMS carry a notice only; "delivered" only when confirmed). Corrections: void + revise.

## 9. Settings and policy values to confirm

System Settings group `grievances` (Grievance Management → Settings). No official policy
document was found in `docs/`, so these defaults are placeholders the policy owner must
confirm:

| Setting | Default | Source |
|---|---|---|
| Committee size / Chairperson / Writer | 3–5, one each | first module baseline |
| Default committee resolution SLA | 3 working days, on assignment, auto-escalate | first module baseline (seeded profile) |
| Intake review | on | — |
| Rejection at intake | **off** | spec: only where policy allows |
| Withdrawal after review needs approval | on | — |
| Quorum | none | spec: not invented |
| Voting | off; dissent allowed | — |
| Appeal window | **none configured** (appeal open until closure) | spec: not invented |
| Auto-close after appeal window | off | — |
| Retention | 0 = not set (archive impossible) | spec: not invented |
| Report privacy threshold | groups < 5 suppressed | — |
| SMS notices | off | — |
| Work week | Mon–Fri | — |

Reason codes are neutral starter wording; intake-rejection codes exist but are unused
while rejection at intake is off.

## 10. Audit, notifications, reports

- Every state change is audited (`grievance.*` events) with ids, statuses, codes and dates
  only — never the narrative, decision text, notes or file content. Viewing a case's
  content by anyone other than its current handler is audited. The case Audit tab
  (`grievances.view_audit`) lists everything recorded against the case and its records.
- Notifications (`module = grievance`) carry the case number and action kind only;
  rendered at read time in the reader's language.
- Reports are process-focused aggregates, scoped to the viewer, with no employee
  ranking; small groups are suppressed; CSV/Excel/PDF exports are audited and bound as
  text (no spreadsheet formula injection).

## 11. Operations

- Deploy: `php artisan migrate` (four migrations; additive, reversible), rebuild assets,
  ensure the scheduler runs (`schedule:run` every minute) and a cache store with atomic
  locks for `onOneServer`.
- Before go-live: configure routes (and have them approved), SLA profiles per level,
  approval rules with positions, letterheads, seals (upload + second approval), templates,
  and assign the grievance roles. Confirm the §9 values.
- Rollback: `php artisan migrate:rollback --step=4` (the relaxed committee unique
  constraint is intentionally not restored).
