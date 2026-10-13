# Employee discipline

Employee discipline under Addis Ababa City Administration Regulation No.
201/2018 (Parts Two–Three). It is its own domain: never a grievance, never a
public service complaint. See `docs/case-domain-boundaries.md` and
`docs/regulation-201-2018-traceability.md` (article-by-article map).

## Model

- `disciplinary_cases` with `disciplinary_charges`, `disciplinary_responses`,
  `disciplinary_evidence`, `disciplinary_witnesses`,
  `disciplinary_recommendations` (+ votes), `disciplinary_decisions`,
  `disciplinary_case_members` and an append-only `disciplinary_status_histories`.
- `App\Services\Discipline\DisciplinaryCaseService` is the only writer of case
  state. Each step enforces the matching article, records a status-history row
  and an audit event, and snapshots statutory deadlines.
- `DisciplinaryCaseAccess` is record-level authorization: permission +
  `OrganizationScopeService` scope + the user's seat on the case, with the
  accused-party and recusal conflict rules.
- Statutory time limits, committee rules and working-day arithmetic are the
  shared legal engine (`App\Support\Legal\Regulation201`,
  `StatutoryDeadlineService`, `StatutoryCommitteeRules`).

## Two procedures

- **Expedited inquiry (Art. 13):** minor violations only, one or more appointed
  investigators, only Art. 10 penalties. A serious penalty is refused on this
  track.
- **Formal committee (Art. 14):** the institution's disciplinary committee
  (Art. 5 composition enforced), summons and hearing, Art. 8 quorum/vote,
  Art. 27 investigation deadline, Art. 28 head decision.

## Safeguards

- No automatic charge from a complaint, no automatic penalty, no automatic
  suspension, and no machine-generated finding or penalty. A person holding the
  right permission decides every outcome. A public complaint or grievance
  referral may be the recorded `source` of a case but is never converted in
  place.
- The accused answers through **My Portal → My Disciplinary Cases**: plea on
  every charge (silence is not an admission), defence evidence, final statement.
  They never see the committee's deliberation, votes or draft recommendation,
  and cannot act on their own case otherwise.
- Penalties are limited to Arts. 10–11 with their statutory caps. The head's
  decision references, never overwrites, the committee recommendation. Delivery
  is tracked apart from the decision; the Art. 29 effective date is set only on
  real delivery.
- Evidence is stored on the private disk and served only through a verified,
  audited download. Legal case history is never hard-deleted; a case is
  discontinued, terminated or closed.

## Permissions and roles

Granular `discipline.*` permissions (`docs/../database/seeders/data/discipline-permissions.php`),
never one broad grant. Default roles: Discipline Case Officer, Disciplinary
Committee Member, Disciplinary Decision Authority, plus the employee
self-service permissions. City-wide roles keep aggregate reports only.

## NEEDS_DECISION / NEEDS_DATA

See `docs/regulation-201-2018-traceability.md`: the official work week, Art. 6
eligibility evidence, qualified signatures/seals, penalty execution against
payroll, the Tribunal appeal binding, and scheduled deadline notifications.
