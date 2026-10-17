# Regulation 201/2018 — implementation traceability

Addis Ababa City Administration Regulation No. 201/2018 on the discipline,
grievance and appeal procedure of government employees. In force from Tir 8,
2018 E.C. (16 January 2026, Art. 59). Source file:
`docs/ደንብ_ቁጥር_201_2018_….pdf`.

This note records where each statutory rule is enforced, how it is tested, and
which points are left as explicit decisions rather than guessed. The four
domains stay separate: Employee Discipline, Employee Grievance, Employee Appeal
/ Administrative Court, and Public Service Feedback (which Regulation 201/2018
does **not** govern — see `docs/case-domain-boundaries.md`).

## Shared statutory engine

| Concern | Where |
| --- | --- |
| Every statutory time limit, with its unit and article | `App\Support\Legal\Regulation201` |
| Working-day / calendar / month arithmetic and immutable snapshots | `App\Services\Legal\StatutoryDeadlineService`, `statutory_deadlines` table |
| Working weekdays for statutory periods | `config/civil_service.php` (`CIVIL_SERVICE_WORK_WEEK_DAYS`) |
| Public holidays (shared with Daily Activity and the grievance SLA) | `App\Services\Calendar\PublicHolidayCalendar`; fixed national holidays seeded by `EthiopianPublicHolidaySeeder` |
| Committee composition, eligibility, quorum, tie-break (Arts. 5–8, 30) | `App\Services\Legal\StatutoryCommitteeRules` |
| Alerts for deadlines the institution owes (2 working days before, and when overdue) | `legal:statutory-deadline-alerts` (daily 06:45), `StatutoryDeadlineNotification` |
| Art. 56 transitional rule | `governing_regulation` on `grievances` and `disciplinary_cases`; `26/2002` proceedings get no Regulation 201/2018 deadline |

A snapshot deadline is written once and never recalculated, so a later holiday
or work-week change affects new milestones only.

## Employee Discipline (Parts Two–Three)

Enforced in `App\Services\Discipline\DisciplinaryCaseService` (the only writer of
case state), `DisciplinaryCaseAccess` (record-level authorization) and the
enums under `App\Enums\Discipline`. Tests: `tests/Feature/Discipline`.

| Article | Rule | Enforcement |
| --- | --- | --- |
| 5 | Disciplinary committee composition | `StatutoryCommitteeRules::compositionProblems`; panel refused if unmet |
| 6(2) | No one disciplined in the last two years sits on a case | `assertSeatable` for committee members, expedited investigators and recusal substitutes; a penalty reversed on appeal does not count (`DisciplinarySeatingTest`) |
| 6(1), 6(3) | Good conduct; more than two years of service | Reported as NEEDS_DATA by `StatutoryCommitteeRules::employeeEligibility` — see below |
| 7 | Two-year term, renewable once | `grievance_committee_members.term_number` + effective dates |
| 8 | Quorum, majority, chair tie-break, written dissent | `tallyVotes`, `StatutoryCommitteeRules::quorumMet`/`tally` |
| 9 | Case-only recusal and replacement | `recuse`; the substitute must be seatable (Art. 6) and from the case institution |
| 10–11 | Minor / serious penalties, with caps | `DisciplinaryPenalty`; `validatedPenalty` |
| 12(1) | Suspension by the head, reason in writing, posted 10 days if it cannot be handed over | `suspend` → `disciplinary_suspensions`; the accused sees the reason on My Portal (`DisciplinarySuspensionTest`) |
| 12(2) | Withheld salary paid back without interest unless dismissed | Settled when the case ends (`settleSuspensions`); `recordSuspensionBackPay`; a dismissal reversed on appeal does not count |
| 12(3) | Unrelated rights and duties continue | Stated on the order and on My Portal; the system changes no other entitlement |
| 12(4) | Suspension from work and salary at most three months | End date required and capped; `DISCIPLINE_SUSPENSION_MAX` |
| 13(1)–(4) | Expedited inquiry (minor only, appointed investigator) | `DisciplinaryTrack::Expedited` |
| 13(5) | A serious penalty goes to the committee | Head action `refer_to_committee`: the case returns to draft on the formal track, record kept (`DisciplinaryReferralTest`) |
| 15 | Structured charge content; each charge separate | `disciplinary_charges`, one row per allegation |
| 16 | Amendment within 5 calendar days | `DISCIPLINE_AMENDMENT`; response versions |
| 17 | End of service, resignation, discontinuance, no re-filing | `terminateForEndOfService`, `proceedInAbsentia`, `discontinue` |
| 18(2)/(3) | 10-working-day summons notice; posting | `serveSummons` via `hasMinimumNotice` |
| 19–21 | Objection; a plea on every charge (silence ≠ admission) | `recordResponse`; each response keeps the pleas it gave (`disciplinary_responses.pleas`, `DisciplinaryPleaHistoryTest`) |
| 22–25 | Evidence, witnesses, final statement | `disciplinary_evidence`, `disciplinary_witnesses`, `adoptRecommendation` |
| 26 | Finding separate from penalty; Art. 26(1) factors | `DisciplinaryFinding`; factors required |
| 27(1) | 1-month / 2-month investigation | `DISCIPLINE_INVESTIGATION(_EXTENDED)` |
| 28 | Head decides in 5 working days; delivery in 5 working days; posting | `decide`, `recordDelivery` |
| 29 | Effect date; a pending appeal stays execution | `recordDelivery`; `execution_stayed` while an appeal is pending; the case cannot close until the appeal ends and any ruling is carried out |

## Employee Grievance (Part Four, Arts. 30–35)

Enforced in `GrievanceCaseService` (case state and filing), `GrievanceCommitteeService`
(committee lifecycle and seats), `GrievanceDecisionService` (recommendation vs
head decision), `GrievanceApproverResolver` (who decides) and
`GrievanceCorrespondenceService` (the written decision). Tests:
`tests/Feature/Grievances`.

| Article | Rule | Enforcement |
| --- | --- | --- |
| 6(2) | No one disciplined in the last two years sits on the committee | `addMember` and `appointReplacement` (`GrievanceHeadDecisionTest`) |
| 8(2) | Quorum: the chairperson and two other members | Every committee recommendation needs the chair and two other seated members to have voted, whatever the voting settings (`GrievanceCommitteeVoteTest`) |
| 8(3) | Majority; the chair's side wins a tie | `assertStatutoryVote` via `StatutoryCommitteeRules::tally`; a recommendation that does not carry cannot go forward |
| 8(4) | Dissent states its reason | `castVote` refuses a dissent without a reason |
| 30 | Committee composition | `StatutoryCommitteeRules::compositionProblems` on committee approval |
| 30(1), 34(3), 35(1) | The committee recommends; the head decides | A committee stage always needs approval; no rule (or a rule waiving approval) blocks it with `head_decision_rule_missing` |
| 31 | Matters a grievance committee hears | Eight categories seeded by `GrievanceDefaultsSeeder` (neutral codes such as `LAW_INTERPRETATION`) |
| 32(2) | Application contents: address, job title, supervisor, cause, date, evidence, remedy, dated signature | My Portal form; sending needs all of them (`applicant_address`, position snapshot, `immediate_supervisor_name`, description, `incident_date`, files, `requested_remedy`, `applicant_declared_at`) — `GrievanceFilingWindowTest` |
| 33(1) | Filed within 10 working days of presenting the matter in writing to the supervisor | `written_issue_raised_at` is required on My Portal; `applyFilingWindow` sets `filing_deadline_at` and refuses a late filing |
| 33(2) | Late only on a force-majeure ground, within 10 working days after the cause ends | `late_submission_reason` + `cause_ended_at`; a late filing always goes to a person at intake, whose acceptance is recorded |
| 34(3) | Committee report within 15 working days of filing | `GRIEVANCE_COMMITTEE_REPORT` from `submitted_at`; seeded SLA profile of 15 working days with no auto-escalation |
| 35(1) | Head decides within 10 working days | `GRIEVANCE_HEAD_DECISION` |
| 35(2) | Written decision within 5 working days, with signature and institution seal | `GRIEVANCE_DECISION_DELIVERY`; `issue` refuses a decision letter without the seal |
| 37–38 | Appeal notice, certified record copy, 30 days to the Administrative Court | The decision letter states both; `APPEAL_FILING` snapshotted on issue |

The internal grievance-appeal flow and the Administrative Court record are in
`docs/employee-appeal.md`. The service-layer initial-issue flow
(`raiseInitialIssue` / `submitFormalGrievance`) remains available, but the
employee's own path is the My Portal form above.

## Appeal (Part Five) — disciplinary decisions

`disciplinary_appeals`, written by `DisciplinaryCaseService` (`DisciplinaryAppealTest`):
the notice and record-copy request (Art. 37, 5-working-day copy deadline), the
filing (Art. 38), the court's outcome (upheld, modified, reversed, remanded,
withdrawn), remand reconsideration within 15 working days (Art. 53(2)), and
implementation of a ruling for the employee within 10 days (Art. 54).

## Interface wording

Screens, messages, notifications and letters do not name the regulation or
cite article numbers, so a revision of the regulation does not leave the
interface out of date. The citations live here, in code comments and in each
stored deadline's `legal_reference`, which records the rule a snapshot was
computed under.

## Operating requirements

- **Approval rule for committee decisions.** Each institution needs an active
  grievance approval rule for handler type `committee` naming the head of the
  institution's position. Until it exists, committee decisions stop with
  `head_decision_rule_missing` — by design.
- **Institution seal.** Each institution needs an approved seal
  (Grievance settings → Seals) before a decision letter can be issued.
- **Public holidays.** Run `php artisan db:seed --class=EthiopianPublicHolidaySeeder`
  once on existing installs, and enter the movable holidays (Siklet, Fasika,
  Eid al-Fitr, Eid al-Adha, Mawlid) each year.
- **Scheduler.** `legal:statutory-deadline-alerts` must run (it is in
  `routes/console.php`).

## NEEDS_DECISION (not implemented until the authority decides)

- **Work week.** Statutory periods use `config/civil_service.php`
  (Monday–Friday by default); the grievance SLA uses its own setting. Confirm
  the official civil-service work week with the Public Service and HR
  Development Bureau and keep the two in line.
- **Art. 6(1) good conduct and Art. 6(3) two years of service** have no
  authoritative data source; the check returns NEEDS_DATA for an officer to
  verify and never fabricates eligibility.
- **Qualified electronic signatures (Arts. 15, 28, 35).** Signer, seal and
  hash are recorded; a qualified electronic signature is not claimed.
- **Penalty and suspension execution.** The system records effective dates,
  suspension periods and back pay owed; it does not change salary, grade or
  employment status itself. Those actions belong to the HR/payroll process.
- **Art. 32(3) group grievances** through a representative are not modelled;
  each employee files their own.
- **Art. 33(1) proof standard and fallback authority.** What constitutes valid
  proof of the written presentation, and who accepts a force-majeure ground,
  is left to policy (`config('grievance.initial_resolution.*')`).
