# Regulation 201/2018 — implementation traceability

Addis Ababa City Administration Regulation No. 201/2018 on the discipline,
grievance and appeal procedure of government employees. In force from Tir 8,
2018 E.C. (16 January 2026). Source file:
`docs/ደንብ_ቁጥር_201_2018_….pdf`.

This note records where each statutory rule is enforced and which points are
left as explicit decisions rather than guessed. The four domains stay separate:
Employee Discipline, Employee Grievance, Employee Appeal / Administrative
Tribunal, and Public Service Feedback (which Regulation 201/2018 does **not**
govern — see `docs/case-domain-boundaries.md`).

## Shared statutory engine

| Concern | Where |
| --- | --- |
| Every statutory time limit, with its unit | `App\Support\Legal\Regulation201` |
| Working-day / calendar / month arithmetic and immutable snapshots | `App\Services\Legal\StatutoryDeadlineService`, `statutory_deadlines` table |
| Working weekdays | `config/civil_service.php` (`CIVIL_SERVICE_WORK_WEEK_DAYS`) |
| Public holidays | `App\Services\Calendar\PublicHolidayCalculator` (shared) |
| Committee composition, eligibility, quorum, tie-break (Arts. 5–8, 30) | `App\Services\Legal\StatutoryCommitteeRules` |

A snapshot deadline is written once and never recalculated, so a later holiday
or work-week change affects new milestones only.

## Employee Discipline (Parts Two–Three)

Enforced in `App\Services\Discipline\DisciplinaryCaseService` (the only writer of
case state), `DisciplinaryCaseAccess` (record-level authorization) and the
enums under `App\Enums\Discipline`.

| Article | Rule | Enforcement |
| --- | --- | --- |
| 5 | Disciplinary committee composition | `StatutoryCommitteeRules::compositionProblems`; panel refused if unmet |
| 6 | Member eligibility | `StatutoryCommitteeRules::eligibility` (good-conduct and >2-year service return NEEDS_DATA) |
| 7 | Two-year term, renewable once | `grievance_committee_members.term_number` + effective dates |
| 8 | Quorum, majority, chair tie-break, written dissent | `tallyVotes`, `StatutoryCommitteeRules::quorumMet`/`tally` |
| 9 | Case-only recusal and replacement | `recuse`; committee membership untouched |
| 10–11 | Minor / serious penalties, with caps | `DisciplinaryPenalty`; `validatedPenalty` enforces caps and the committee route for serious penalties |
| 13 | Expedited inquiry (minor only, appointed investigator) | `DisciplinaryTrack::Expedited`; serious penalty refused |
| 15 | Structured charge content; each charge separate (15(2)) | `disciplinary_charges`, one row per allegation |
| 16 | Amendment within 5 calendar days | `DISCIPLINE_AMENDMENT`; response versions |
| 17(1)/(2) | End of service vs resignation | `terminateForEndOfService` / `proceedInAbsentia` |
| 17(4)/(5) | Discontinuance; no re-filing on the same matter | `discontinue`; `open` blocks a discontinued source matter |
| 18(2)/(3) | 10-working-day summons notice; posting period | `serveSummons` via `hasMinimumNotice`; posting snapshot |
| 19–21 | Preliminary objection, plea on every charge (silence ≠ admission) | `recordResponse` requires an explicit plea per charge |
| 22–24 | Evidence and witness handling, Art. 24(4) particulars | `disciplinary_evidence`, `disciplinary_witnesses` |
| 25 | Final statement before closing | `adoptRecommendation` requires it (formal, non-absentia) |
| 26 | Finding separate from penalty; Art. 26(1) factors | `DisciplinaryFinding`; factors required for a responsibility finding |
| 27(1) | 1-month / 2-month investigation | `DISCIPLINE_INVESTIGATION(_EXTENDED)`; `requireAdditionalEvidence` |
| 28(1) | Head decides in 5 working days; approve/modify/overturn/re-examine | `decide`; recommendation never overwritten |
| 28(3)/(5) | Written decision delivered in 5 working days; posting | `recordDelivery`; delivery tracked apart from the decision |
| 29 | Effect date: fines/demotion +30 days, dismissal on delivery | `DisciplinaryPenalty::takesEffectOnDelivery`, `recordDelivery` |

## Employee Grievance (Part Four, Arts. 30–35)

Enforced in `App\Services\Grievances\GrievanceCaseService` (case state and the
pre-grievance initial-resolution stage), `GrievanceCommitteeService` (committee
lifecycle) and `GrievanceDecisionService` (recommendation vs head decision).
The initial-resolution stage, the filing window and all four statutory
deadlines are pinned to `Regulation201` and computed only through
`StatutoryDeadlineService`; the admin SLA profiles are separate *internal*
targets that can never override a statutory due date.

| Article | Rule | Enforcement |
| --- | --- | --- |
| 30 | Grievance committee composition: 5 voting members, 1 chair appointed by the head, 3 elected (≥1 woman), 1 HR professional as secretary | `StatutoryCommitteeRules::compositionProblems`, wired into `GrievanceCommitteeService::approve` for `CommitteeType::Grievance`; approval refused if unmet. Selection sources: `GrievanceCommitteeSelectionSource` |
| 33(1) | Written issue to the supervisor / responsible officer, discussion attempted, before a formal grievance | `GrievanceInitialIssue` + `raiseInitialIssue` / `recordInitialResolution`; `submitFormalGrievance` refuses unless the initial outcome is `Failed` (unresolved / no response) |
| 33(1) | Formal grievance filed within 10 working days of the unresolved submission | `filing_deadline_at` from `GRIEVANCE_COMMITTEE_FILING`, clock starts at the recorded initial-resolution outcome; `is_late_submission` flagged, snapshot in `statutory_deadlines` |
| 33(2) | Late grievance within 10 working days after a force-majeure cause ends | `late_submission_reason` / `cause_started_at` / `cause_ended_at`; `reviewLateSubmission` (permission `grievances.intake_review`) never auto-accepts |
| 34(3) | Committee reports to the head within 15 working days of receipt | `GRIEVANCE_COMMITTEE_REPORT` snapshotted on acceptance (`acceptAndRoute`) |
| 35(1) | Head decides within 10 working days of receiving the report | `GRIEVANCE_HEAD_DECISION` snapshotted on `submitForApproval`, completed on `finalize`; recommendation and final decision are separate `GrievanceDecision` records |
| 35(2) | Written decision delivered within 5 working days | `GRIEVANCE_DECISION_DELIVERY` snapshotted on `finalize`; delivery tracked apart from issuance |
| §66 | Immutable status history | `grievance_status_histories` (append-only, no `updated_at`) |
| §69 | Operational overdue flags, not status changes | `Grievance::overdueFlags()` derives committee-report / head-decision / delivery overdue from open deadlines without touching `GrievanceStatus` |

## NEEDS_DECISION (not implemented until the authority decides)

- **Work week (`config/civil_service.php`).** Monday–Friday is the starting
  value; confirm the official civil-service work week with the Public Service
  and HR Development Bureau.
- **Art. 6(1) good conduct / commended performance** and **Art. 6(3) more than
  two years of government service** have no authoritative data source; the
  eligibility check returns NEEDS_DATA for an officer to verify, and never
  fabricates eligibility.
- **Signatures and seals (Arts. 15, 28).** The charge and decision record a
  signer and a typed acknowledgement only; a qualified electronic signature is
  not claimed. A controlled signed document / seal workflow is a separate
  decision.
- **Penalty execution.** The system records the Art. 29 effective date; it does
  not itself change salary, grade or employment status. Those actions belong to
  the HR/payroll process.
- **Appeal linkage (Arts. 36–54).** A delivered disciplinary decision is the
  lawful basis of an appeal; the appeal / Administrative Tribunal side is
  tracked in the grievance-appeal domain (`docs/employee-appeal.md`). Binding a
  disciplinary decision to a Tribunal case is a later, separate piece of work
  and is not auto-created here.
- **SLA notifications.** Deadlines are snapshotted and shown; scheduled
  approaching/overdue notifications are not yet wired.

### Employee Grievance

- **ND-1 Initial-resolution proof standard (Art. 33(1)).** What electronically
  constitutes valid proof of the written submission and discussion is not
  settled. Default: the in-system record suffices
  (`config('grievance.initial_resolution.proof') = 'in_system'`). Change the
  config value when the legal standard is confirmed.
- **ND-2 Filing-clock trigger (Art. 33(1)).** The 10-working-day window starts
  at the recorded initial-resolution outcome (`Unresolved` / `NoResponse`)
  timestamp. Confirm the exact statutory trigger; the clock start is the only
  thing that would change.
- **ND-3 Responsible-officer fallback.** When no immediate supervisor resolves,
  a responsible officer may be recorded; if neither resolves, the issue is
  flagged rather than auto-resolved. The precise fallback authority is open.
- **ND-4 Force-majeure acceptance authority (Art. 33(2)).** A late filing is
  captured and reviewed by a holder of `grievances.intake_review`; it is never
  auto-accepted. The exact accepting authority is left to policy.
- **Grievance withdrawal, cross-institution jurisdiction, delegated head
  authority, and corrective-action-before-closure** remain as noted in
  `docs/employee-grievance.md`.
