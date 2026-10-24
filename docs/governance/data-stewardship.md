# Data stewardship

Who looks after the Golden Path data and how quality is kept.

## Ownership

Core owns organizations, units, positions, employees, assignments, ID cards,
users and access ([../architecture/core-authority.md](../architecture/core-authority.md)).
The Core data steward answers for its quality; organization administrators
answer for the records of their own organizations within their scope.

## Quality checks

| Check | Tool | Expected | Who acts |
| --- | --- | --- | --- |
| Broken links in the organization → position → employee → assignment → card chain, separated employees still placed or holding an active card | `backbone:audit-integrity`, Core dashboard data-quality figure, Audit Center integrity view | 0 | data steward |
| Duplicate people | `data:audit-duplicates`, employee duplicate flags | reviewed | organization HR |
| Structure problems | `structure:audit` | reviewed | data steward |
| Legacy masters still used | `backbone:duplicate-masters` | falling to 0 | data steward |
| Change requests waiting | Core dashboard | within agreed time | approvers |

## Correcting data

- Through Core actions only (forms, change requests, employee corrections), so every correction is audited and published as an event.
- Never by SQL on production. Never by regenerating UUIDs, card numbers or QR references.
- History is kept: status changes, assignment ends and card replacements add records; nothing is deleted.

## Privacy

Employee data is shared by purpose: public QR shows the minimum; partners get
what their scope allows within their organization bounds; national ID is
encrypted and stored with a keyed hash; sensitive fields are redacted in audit
rows. Retention periods for personal data are **not decided** and are not
invented here; until they are, data is kept.
