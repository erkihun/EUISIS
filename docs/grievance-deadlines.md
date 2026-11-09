# Grievance deadlines

How the Employee Grievance module computes and holds the time limits of
Addis Ababa City Administration Regulation No. 201/2018, Part Four.

## Statutory vs internal

Two kinds of deadline exist and must not be confused:

- **Statutory deadline** — fixed by the regulation. Its length and unit come
  only from `App\Support\Legal\Regulation201`; it is computed only through
  `App\Services\Legal\StatutoryDeadlineService` and stored, once, in the
  `statutory_deadlines` table. It is **immutable**: a holiday or work-week
  change made after the snapshot never moves it.
- **Internal SLA target** — an operational service target configured by
  administrators (`grievance_sla_profiles`, via `GrievanceSlaService`). It
  drives escalation and dashboards, but it can **never** override a statutory
  due date. `Grievance::effectiveDeadline()` always returns the statutory
  value.

## The four grievance deadlines

| Milestone | Rule | Length | Clock starts | Snapshotted in |
| --- | --- | --- | --- | --- |
| Formal filing | Art. 33(1) | 10 working days | The date the matter was presented in writing to the supervisor (`written_issue_raised_at`, required on My Portal) | `GrievanceCaseService::submit` → `applyFilingWindow`; a late filing is refused |
| Force-majeure late filing | Art. 33(2) | 10 working days | When the qualifying cause ends (`cause_ended_at`) | `applyFilingWindow`; always reviewed by a person at intake, never auto-accepted |
| Committee report to head | Art. 34(3) | 15 working days | Filing (`submitted_at`) | `GrievanceCaseService::acceptAndRoute` |
| Head decision | Art. 35(1) | 10 working days | Report submitted to the head (`submitForApproval`) | `GrievanceDecisionService::submitForApproval`; completed at `finalize` |
| Decision delivery | Art. 35(2) | 5 working days | Decision finalized | `GrievanceDecisionService::finalize` |

Working days exclude weekends (the civil-service work week in
`config/civil_service.php`) and public holidays
(`App\Services\Calendar\PublicHolidayCalendar`, shared across modules).

The seeded SLA profile "Regulation 201/2018 Art. 34(3) committee report"
gives committee stages the same 15 working days and never auto-escalates: the
regulation names the committee, so a timer must not move the case away from
it. The older 3-working-day baseline stays for an institution that confirmed
it, ranked below the statutory profile.

`legal:statutory-deadline-alerts` (daily) notifies the stage handlers two
working days before the committee report, head decision and delivery
deadlines, and again once one is overdue.

## Overdue flags

Overdue status is **derived, never stored as a workflow status**
(`Grievance::overdueFlags()` returns `committee_report`, `head_decision`,
`decision_delivery`). A passed deadline is reported for operational attention;
it does not silently advance the legal `GrievanceStatus`.

## Open questions

Clock-start and proof details for the initial-resolution and late-filing rules
are recorded as ND-1…ND-4 in `docs/regulation-201-2018-traceability.md`. The
code uses safe defaults behind config flags until the authority confirms them.
