# ADR 0013: Integration with court-ms, the tribunal case system

Status: accepted (technical decisions). The owner decisions below are taken as
defaults so the integration can run; each stays open for the System Owner and the
tribunal to confirm or change.
Date: 2026-10-27.

## Context

court-ms (github.com/erkihun/court-ms) is the live tribunal case system: Laravel 12,
MySQL, its own users and roles. EUISIS keeps the city's organizations and employees
and, in Grievance Management, an Administrative Tribunal register for grievances routed
to the tribunal. The two systems did not know each other: court-ms typed respondent
organizations as free text, and a grievance sent to the tribunal had to be re-entered
there by hand, with no way back for the outcome.

The design was agreed in the integration design document (Claude Doc "EUISIS ↔ court-ms
integration design", 2026-10-27). This ADR records what was built and the decisions
taken while building it.

## Decisions

1. **API to API, never a shared database.** Each system keeps its database, users and
   roles. Nothing reads the other's tables.
2. **EUISIS owns organizations and people; court-ms owns cases.** court-ms mirrors
   EUISIS organizations, units and (on demand) positions and the employees who are
   parties to a case, from the EUISIS partner API and change feed (Flow 1). EUISIS keeps
   only a reference to the court-ms case and its latest states.
3. **court-ms is a registered EUISIS application** with read-only scopes
   `organizations.read`, `organization_units.read`, `positions.read`,
   `employees.basic_read`, `employee_assignments.read`, `changes.read`. Nothing new was
   built in EUISIS for Flow 1.
4. **A person refers; the send is queued** (Flow 2). On the tribunal case page an
   officer who handles the case chooses the court-ms case type and confirms the title,
   summary, respondent, contact details and the PDFs to attach. `SendCourtMsReferral`
   sends it on the `integrations` queue, eight attempts over about four hours; a refusal
   of the content (4xx) marks it failed at once for the officer to correct and send
   again. A queued or sent referral cannot be sent twice.
5. **The grievance UUID is the idempotency key.** court-ms returns the same case for the
   same key, so a retry never opens a second court case.
6. **EUISIS pulls status** (Flow 3). `court-ms:poll` runs every ten minutes and reads
   `GET /api/v1/integration/referrals/updates?since=<cursor>`. Review state and reason,
   case status, hearing date, decision date and summary are written to the tribunal
   register and the grievance history; returns, hearings and decisions notify the
   people on the tribunal case (`GrievanceNotifier`, which carries no case content).
7. **The register status follows court-ms, never backwards**: open → hearing (accepted
   with a hearing date) → decided (a published decision) → closed (court case closed or
   dismissed). A manual edit on the register still works.
8. **The register keeps its own TRB- number**; the court-ms number sits beside it.
9. **Inbound credentials in court-ms are not Sanctum tokens** (a change from the design
   document). Every court-ms staff token carries the `*` ability and the existing case
   API accepts any staff token, so a Sanctum token for an integration user would also
   open `/api/v1/cases`. court-ms instead keeps a hashed token per integration client
   (`integration_clients`), checked only on `/api/v1/integration/*`, with abilities
   `referrals:create` and `referrals:read` and an optional IP allowlist.
10. **court-ms records changes by comparing state**, not by model events: its admin
    screens write cases with query-builder updates, which fire no events. Before every
    poll (and every five minutes) it compares each referred case with what it last
    reported and appends a row to `euisis_case_updates`.
11. **The route name avoids the word "court"** in `routes/grievances.php`
    (`tribunal-cases.referral`): the Court Cases placeholder test keeps Grievance
    Management from routing into the internal Court Cases module, and this integration
    does not touch that module.

### Owner decisions taken as defaults

| # | Question (design document) | Default now |
| --- | --- | --- |
| 1 | Does the tribunal accept EUISIS referrals, and for which case types? | Yes, through its existing intake review; every court-ms case type is offered and the officer picks. A category → case type map (`COURT_MS_CASE_TYPE_MAP`) pre-selects one. |
| 2 | A referred complainant has no court-ms account | No applicant record is created. The complainant's name, EUISIS employee id and the contact details the officer sends are kept on the court-ms referral and shown to staff. |
| 3 | Which documents may leave EUISIS; is consent needed? | Only PDFs the officer ticks; none are ticked by default. Whether the complainant must consent is **not decided here** (a legal question) and stays open. |
| 4 | "Returned by the tribunal" as its own register status? | No. The register stays open; the court-ms review state and reason are shown and the officer is notified. |
| 5 | Who owns the integration account? | The court-ms system administrator issues and rotates it (`php artisan euisis:integration-token`); the EUISIS System Admin registers court-ms as an application. |
| 6 | Network path | HTTPS with certificate checks on both sides; IP allowlists recommended on both. |
| 7 | Does EUISIS keep its own tribunal number? | Yes (decision 8). |
| 8 | Where do employees file, and who may be a party? (2026-10-28) | Employees file from My Portal (Flow 5), not in the court portal. Only an employee with an EUISIS record may sue, and only an active institution registered here may be sued; court-ms enforces the same with `EUISIS_PARTIES_ONLY` (referrals must name both). |
| 9 | What of the grievance record goes with a filing? (2026-10-28) | Only what the employee already sees in My Grievances: the grievance's facts, the letters issued to them, the issued decisions, and the PDFs (those letters and the evidence they submitted). Nothing from the committee's internal file. The employee chooses the grievances. |
| 10 | Who receives the tribunal's letters to an institution? (2026-10-28) | The institution's Administrative Tribunal Officers (`grievances.tribunal` within that institution) are notified and read them under Tribunal Cases > Court letters; `court_cases.view` and city-wide scopes read all. No new permission. |
| 11 | How long do the tokens of connected systems live? (2026-10-28) | Each EUISIS token carries its own expiry (TD-16). The court-ms and cafeteria-platform applications get a lifetime in days (90 suggested) and a new token before it ends; any other token keeps the 8-hour default. The global Sanctum expiry had cut both systems off 8 hours after every token. |
| 12 | Where do employees follow and answer their cases; what of the court portal? (2026-10-28, owner) | Everything is in My Portal (Flow 6): case details, hearings, documents, messages, replies to respondent responses, corrections, letters, decisions and notices. court-ms's applicant portal, with its sign-in and registration, is gone from the web (`EUISIS_PORTAL_IN_EUISIS`: its URLs are not found), and so is the respondent portal: respondents can no longer answer in court-ms. The one-time sign-in link of Flow 4 is gone. court-ms runs its own portal actions for the employee's linked account, call by call, so its rules, audit and notifications are unchanged. |

## Alternatives considered

- **Shared database or database replication**: rejected; it couples two releases and
  two security models, and court-ms is live with real data.
- **court-ms pushing webhooks to EUISIS**: rejected for now; EUISIS asks its own
  partners to treat pull as the record, and court-ms has no outbound retry machinery.
  An optional "something changed" nudge can be added later; the poll stays the record.
- **Sanctum token for an integration user in court-ms**: rejected (decision 9).
- **Creating a court-ms applicant for every referred complainant**: rejected; it needs
  a password, a unique email and national id, and gives the complainant a portal
  account nobody asked for.

## Consequences

- Tribunal staff pick city organizations from a list instead of typing them; old cases
  keep the text they were filed with.
- A referral reaches the tribunal without re-typing, and the outcome comes back.
- Two schedules must run: `court-ms:poll` in EUISIS, `euisis:sync` in court-ms.
- A court-ms outage delays referrals and status; it never loses or duplicates them.

## Migration impact

- EUISIS: `2026_10_27_210000_add_court_ms_link_to_administrative_tribunal_cases`
  (nullable columns, `court_ms_sync_state` table). Additive and re-runnable.
- court-ms: three additive migrations (cache tables, nullable EUISIS ids on
  respondents, applicants and court_cases, referral tables). All have `down()`.
- Both sides are switched off until configured (`COURT_MS_ENABLED`, `EUISIS_ENABLED`).

## Security impact

- Each direction uses its own machine token, never a person's password. EUISIS logs
  every court-ms call in `api_request_logs`; court-ms records `last_used_at` and IP.
- Read-only scopes for court-ms in EUISIS; `referrals:*` only for EUISIS in court-ms.
- Only what the officer confirms leaves EUISIS. The court-ms update list carries no
  grievance content back; EUISIS notifications carry only the case number.
- Tokens live in the environment files only.
- Flow 6 acts as the employee only through `people:act`, naming them by EUISIS UUID in the
  body. court-ms signs the linked applicant account in for that call alone, with its own
  ownership checks, and EUISIS sends nothing the employee's own session did not submit.

## Operational impact

- Runbook: [docs/integration/court-ms.md](../../integration/court-ms.md); court-ms side:
  `docs/euisis-integration.md` in that repository.
- The `integrations` queue must have a worker (it already carries webhook deliveries).
- Monitoring: the tribunal case page shows the last poll and its error; the court-ms
  case page shows the last sync and its error.
