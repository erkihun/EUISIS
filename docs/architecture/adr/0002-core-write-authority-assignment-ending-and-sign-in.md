# ADR 0002: Core write authority, ending an assignment, and the Core sign-in

Status: accepted, 24 October 2026. Decided by the maintainers' delegate on the
project owner's instruction ("apply your decision based on project context").

## Context

The Core platform analysis left three questions open: whether partner systems
may change master data through the API, how an assignment ends when the
employee is not transferred, and whether Core gets its own sign-in screen. The
project rules that bear on them: permission says what and organization scope
says where; Core is the only authoritative writer of organizations, units,
positions, employees, assignments and credentials; history is never deleted or
rewritten; public and partner data is minimised; every sensitive change is
audited; no service sees another's data; and the platform serves a government
administration whose HR acts have legal weight.

## Decision 1: machine clients do not change master data

Partner systems (`ExternalApplication` clients) read master data and verify
credentials. They never create or change an organization, unit, position,
employee, assignment or card, and they cannot suspend, revoke or activate a
card. Those acts are made by a person in Core, through the backbone commands,
with that person in the audit trail.

- A partner that learns of a problem (a card reported stolen at a cafeteria, a
  terminal detecting misuse) reports it to the responsible office, which acts
  in Core; the partner keeps refusing service on its own side meanwhile.
- The API's write routes are limited to recording what a partner did:
  verifications, service transactions, transport trip steps, reconciliation
  requests and webhook replays. `tests/Feature/Api/MachineWriteBoundaryTest.php`
  fails if a write route on master data appears, and lists every write route
  so a new one is reviewed against this decision.
- Revisit only for a named system with a legal mandate to act on credentials
  (for example a national identity authority), with its own scope granted to
  no application by default, an organization bound, an Idempotency-Key and a
  required reason.

Why: an HR or credential act needs an accountable person. A machine token
cannot carry that accountability, can be stolen, and would let a service
module become a shadow writer of the Golden Path, which the backbone exists to
prevent.

## Decision 2: an assignment ends without a transfer only when employment ends

| Change of employment status | Effect on the placement |
| --- | --- |
| to retired, terminated, deceased or transferred (out of the administration) | `EndAssignment`: the current assignment is closed that day and its seat released. It stays the employee's latest record, so their organization is still known for scope and history. |
| back to active or suspended from one of those, on the same day | the same assignment is reopened: the separation was a mistake |
| back to active or suspended later | `StartAssignment`: a new assignment in the employee's last position starts that day, if the position is still active and has a free approved seat; otherwise the change is refused and HR places the employee through a transfer |
| active to suspended, draft to active, or between two separation statuses | none: a suspended employee keeps their position |

- Every status change is also written to the employment status history.
- Moving an employee to another placement is always `TransferAssignment`,
  through the transfer or vacancy workflow. There is no free "end assignment"
  button: a wrong placement is corrected by a transfer, so the correction is
  itself on record.
- Separations recorded before this rule left the seat taken;
  `backbone:audit-integrity` reports them. Each is resolved by HR (saving the
  employee's status again ends the placement), not by a bulk job.
- The separation date is the day the change is saved. A form field for a
  backdated separation date is left for when HR asks for it.

Why: the assignment history is the legal record of who held which position.
Ending it only with the employment keeps that record truthful, frees the seat
for vacancies and transfers, and never rewrites a past period.

## Decision 3: Core has its own sign-in screen over the one authentication

`/core/login` shows the sign-in page titled for Core, without the employee
self-registration link, and lands the person in Core after signing in. The form
posts to the same login route as `/login`, so throttling, MFA, password policy,
idle timeout and session handling are shared, and a person signed in to one is
signed in to the other. `core.access` still decides who may enter.

Why: the brief asks Core to feel like its own product with its own entry
point. A second authentication system would double the attack surface and the
password and MFA administration for no gain.

## Consequences

- Positions held by separated employees now count as vacant.
- `CoreDashboardMetrics` counts only open current assignments.
- `MasterDataEventProjector` does not emit a second `assignment.closed` when an
  already closed assignment stops being the latest record.
- Partners keep reading through the API and events; nothing they use changes.
