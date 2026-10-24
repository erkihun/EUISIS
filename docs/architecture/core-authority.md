# Core authority

Core is the only writer of Golden Path master data. This page is the source-of-
truth table and lists how the rule is enforced. Decisions behind it: ADR 0001
(backbone separation) and ADR 0002 (write authority, assignment ending, sign-in).

## Source of truth

| Data | Authority | Written only by | Everyone else |
| --- | --- | --- | --- |
| Organizations, types, names history | Core | Organization actions, approved change requests | reads by UUID (`OrganizationDirectory`, `/api/v1/organizations`) |
| Hierarchy versions, edges, closure | Core | Hierarchy version actions, approved change requests | `OrganizationHierarchy` |
| Units, unit types, relationships | Core | Unit actions | `UnitDirectory` |
| Positions, establishments, occupancy | Core | Position actions, `PositionCapacityService` | `PositionDirectory` |
| Employees, employment status | Core | Employee actions, `EmploymentStatusTransition` | `EmployeeDirectory` |
| Assignments | Core | `StartAssignment`, `EndAssignment`, `TransferAssignment` | `AssignmentContextReader`; transfers request a move, Core performs it |
| ID cards, requests, replacements, print | Core | ID-card and print actions | `CredentialVerification`, `ServiceEligibility` |
| Users, roles, permissions, organization scopes | Core | Access administration | Permission = what, organization scope = where |
| Code rules and sequences | Core | Code rule actions; modules register targets | — |
| Partner applications, tokens, webhooks | Core | Integration Center | — |
| Audit log | Core | `WriteAuditLogAction` only (append-only) | Audit Center, read-only |

Service modules own their own records (see [database-ownership.md](database-ownership.md)) and may keep UUID references and `*_snapshot` evidence columns, never a second master.

## Enforcement

| Rule | Mechanism | Proof |
| --- | --- | --- |
| No service module writes Golden Path models | static scan | `tests/Unit/BackboneBoundaryTest.php` |
| No machine principal writes master data | API exposes reads and verification only | `tests/Feature/Backbone/MachineWriteBoundaryTest.php` |
| No new direct reads in service modules | per-module ratchet | `tests/Unit/ServiceModuleCoreDependencyTest.php` |
| One writer during cutovers and restores | `CORE_MASTER_DATA_WRITES=frozen` | `tests/Feature/Core/CutoverToolingTest.php` |
| Every change audited with actor, organization and request ID | Core actions | Audit Center, `audit_logs.request_id` |
| Data stays consistent | `backbone:audit-integrity --strict`; `production:readiness` Core section | `BackboneStageTwoTest`, `CoreOperationalSafeguardsTest` |

## What is not yet enforced

- 210 service-module files still read Golden Path models directly (frozen, not removed). Reads are safe inside one application; they block physical separation only.
- Users with no organization-scope rows are treated as not yet scoped and see every organization (established convention in `OrganizationScopeService::canAccessOrganization`). Tightening it would lock out existing roles; it needs a business decision and a data clean-up first. Listed in [../governance/security-governance.md](../governance/security-governance.md).
