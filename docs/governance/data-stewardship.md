# Data stewardship

Who looks after the Golden Path data and how quality is kept. Ownership and
classification: [data-ownership.md](data-ownership.md).

## Stewards

The Data Steward role answers for Golden Path quality overall; organization
administrators answer for the records of their own organizations within
their scope. Per domain (holders named through the
[roles register](roles-register.md); none named yet):

| Domain | Steward (role) | Responsible for |
| --- | --- | --- |
| Organizations | Data Steward | organization records, types, lifecycle (merge, dissolve) |
| Hierarchy | Data Steward with the organization-design office | hierarchy versions, edges, restructuring publication |
| Positions | organization HR under the Data Steward | positions, establishments, seat occupancy |
| Employees | organization HR under the Data Steward | employee records, duplicate review, corrections |
| Assignments | organization HR under the Data Steward | placements, transfers, assignment endings |
| ID cards | the ID office under the Data Steward | card requests, issue, replacement, revocation |

Stewards handle quality findings, duplicate review, corrections, master-data
approval and exceptions. They do not correct data by hand in the database.

## Quality rules

All checks are read-only and never correct anything by themselves.

| Rule | Check | Severity | Who acts |
| --- | --- | --- | --- |
| Duplicate employee risk (shared national ID) | `data:audit-duplicates`; employee duplicate flags | HIGH | organization HR |
| Employee without an active assignment | `structure:audit` (`employees.active_without_assignment`) | MEDIUM | organization HR |
| More than one current assignment | `data:audit-duplicates` | HIGH | organization HR |
| Current-assignment pointer wrong | `data:audit-duplicates`, `backbone:audit-integrity` (`employees_bad_pointer`, `assignments_unpointed`) | HIGH | Data Steward |
| Occupied seat whose assignment ended | `structure:audit` (`position_occupancies.without_active_assignment`) | MEDIUM | organization HR |
| More occupants than approved slots | `data:audit-duplicates` | HIGH | organization HR |
| Separated employee still placed or holding an active card | `backbone:audit-integrity` (`separated_still_placed`, `separated_card_active`) | HIGH | ID office, HR |
| More than one live or current card | `data:audit-duplicates` | HIGH | ID office |
| Replacement card not linked | `backbone:audit-integrity`, `cards:audit-replacement-links` | MEDIUM | ID office |
| Invalid hierarchy (unit cycle, parent in another organization) | `structure:audit` | HIGH | Data Steward |
| Position in a missing or foreign organization or unit | `structure:audit`, `backbone:audit-integrity` | HIGH | Data Steward |
| Current assignment in an inactive organization | `structure:audit` | MEDIUM | Data Steward |
| Missing mandatory organization data (Amharic name) | `structure:audit` (`organizations.missing_amharic_name`) | LOW | Data Steward |
| Legacy masters still used | `backbone:duplicate-masters` | falling to 0 | Data Steward |

## Dashboard

The Core dashboard shows the Golden Path integrity counts
(`BackboneIntegrityChecks`, city-wide) and `production:readiness` reports them
as a gate. Drill-down to the affected records is through the command-line
checks, which list the record ids (`structure:audit`, `data:audit-duplicates`;
`--json` for a machine-readable summary). The KPIs reviewed monthly are the
counts per rule above: completeness (employees without assignment, missing
Amharic names), duplicates, invalid assignments, position inconsistencies,
card inconsistencies and hierarchy issues. A dashboard page with every rule
and a link to each affected record is [technical-debt.md](technical-debt.md)
TD-20.

## Correcting data

- Through Core actions only (forms, change requests, employee corrections, card actions), so every correction has a reason, is authorised by permission and scope, is audited and is published as an event.
- Corrections to sensitive master data (identity, employment status, placement, card state) need a second person where the workflow has one (card approval, change-request approval, transfer approval).
- Never by SQL on production. Never by regenerating UUIDs, card numbers or QR references. Checks report; people decide.
- History is kept: status changes, assignment ends and card replacements add records; nothing is deleted.

## Duplicate employees

A duplicate is never resolved by deleting one record. Today duplicates are
flagged and reviewed; there is no merge workflow yet ([technical-debt.md](technical-debt.md) TD-14).
Until there is, the steward keeps both records, marks the surplus one inactive
through the normal status change with the reason, and records the survivor in
the reason text. The future merge must move assignments, cards, documents and
service references to the survivor, keep the audit trail, and record the merge
history.

## Restructuring

Major restructuring follows the organizational-change workflow: proposal with
its legal or administrative basis, draft hierarchy, impact analysis
(`ChangeRequestImpactAnalyzer`), approval, effective date, publication of a new
hierarchy version, then `structure:audit` and `backbone:audit-integrity`
afterwards. No direct hierarchy edits in production outside that workflow.

## Privacy

Employee data is shared by purpose: public QR shows the minimum; partners get
what their scope allows within their organization bounds; national ID is
encrypted and stored with a keyed hash; sensitive fields are redacted in audit
rows. Privacy requests are handled without deleting legally required
government records: corrections go through the correction path above;
erasure of authoritative history is not done ad hoc.

Retention follows the [retention matrix](retention-matrix.md): every record
class (employee master, assignment and card history, cases, performance,
transactions, documents, audit, verification and API logs) is kept until HR,
Legal, Records Management, Audit and Data Protection approve its period;
only then can a log class be archived or purged, with the approval
reference. Archive is not deletion: archived records stay complete,
retrievable and auditable. No period is invented here. Operational
housekeeping (notifications, reminders) keeps its automatic windows.
