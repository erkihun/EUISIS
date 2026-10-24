# Data ownership

The governance register for the Golden Path and the records that hang off it:
who owns each entity, who may write and read it, how other systems get it,
how long it is kept, and how sensitive it is. Write paths in code are in
[../architecture/data-ownership-matrix.md](../architecture/data-ownership-matrix.md)
and [../architecture/core-authority.md](../architecture/core-authority.md);
this page does not repeat them.

Roles are those of the [roles register](roles-register.md); none is named yet
(gate G5), so every "owner" below is a role, not a person. Retention is
NEEDS_POLICY_DECISION_RETENTION for every class until the approvals in the
[retention matrix](retention-matrix.md) exist: records are kept, never deleted.

## Golden Path

| Entity | Authoritative owner | Business owner | Technical owner | Who may write | Who may read | Integration method | Classification |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Organization (and type, hierarchy versions) | Core | Data Owner (structure decisions with the Business Owner) | Technical Custodian | Core organization actions; approved change requests; hierarchy publish | staff by permission and organization scope; partners with `organizations.read` / `organization_structure.read` | Core read contracts, `/api/v1` organizations, `organization.*` and `hierarchy_version.published` events | internal (public-sector structure) |
| Organizational unit | Core | Data Owner | Technical Custodian | Core unit actions within scope | by permission and scope; `organization_units.read` | contracts, API, `organization_unit.*` events | internal |
| Position (and establishment, occupancy) | Core | Data Owner with organization HR | Technical Custodian | Core position actions; organization-design publish; occupancy only through assignment commands | by permission and scope; `positions.read` (occupancy never names the employee) | contracts, API, `position.*` events | internal |
| Employee | Core | Data Owner | Technical Custodian | Core employee actions, imports, self-service change review — within scope | by permission and scope; partners with `employees.basic_read`, minimised | contracts, API, `employee.*` events | personal; national ID and phone encrypted, national ID looked up by keyed hash |
| Assignment | Core | Data Owner with organization HR | Technical Custodian | Core assignment and transfer commands only (`TransferAssignment`, `EndAssignment`, `StartAssignment`) | by permission and scope; `employee_assignments.read` | contracts, API, `assignment.*`, `employee.assignment_changed`, `employee.transferred` events | personal |
| Employee ID / credential | Core | Data Owner with the ID office | Technical Custodian | Core card actions (request, approve, issue, print, replace, revoke) with separation of duties | by permission and scope; verification answers minimised; public QR shows the minimum | `CredentialVerification` contract, `/api/v1` card verification, `id_card.*` events | personal; public card UUID only on the QR |

## Records that depend on the Golden Path

| Record | Owner | Writes | Reads | Classification |
| --- | --- | --- | --- | --- |
| Users, roles, organization scopes | Core (Information Security Lead) | administrators within scope | administrators; `access:review`, `access:scope-gaps` | security-sensitive |
| Audit log | Core (Audit/Compliance Owner) | the application only; sealed daily | auditors through Audit Center | sensitive fields redacted |
| Verification and API logs | Core (Integration/API Owner) | the application only | operators, auditors | personal (identifiers) |
| Integration events and deliveries | Core (Integration/API Owner) | outbox only | partners by scope and bounds | as the event table in [event-lifecycle.md](event-lifecycle.md) |
| Service-module records (cafeteria, transport, performance, cases, feedback, …) | the module (module owner) | the module, referencing Core identities | by module permission and scope | per module; cases are sensitive |
| Uploaded documents | the module that holds them | the module | by permission; private storage | personal or sensitive |

## Rules

- No module keeps an authoritative copy of a Golden Path entity. A module may keep a dated snapshot for a transaction it records (for example the name printed on a receipt), never as a source for other modules.
- Legacy duplicates are listed in the [deprecation register](deprecation-register.md) and measured by `backbone:duplicate-masters`.
- A new entity, or a change of owner or classification, needs an ADR and the Data Owner's approval.
