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
| Audit log | Core | `WriteAuditLogAction` only; append-only, removed only by the retention job `data:prune-retention` after the configured audit retention | Audit Center, read-only |

Service modules own their own records (see [database-ownership.md](database-ownership.md)) and may keep UUID references and `*_snapshot` evidence columns, never a second master.

## Enforcement

| Rule | Mechanism | Proof |
| --- | --- | --- |
| No service module writes Golden Path models | static scan | `tests/Unit/BackboneBoundaryTest.php` |
| No machine principal writes master data | API exposes reads and verification only | `tests/Feature/Backbone/MachineWriteBoundaryTest.php` |
| No new direct reads in service modules | per-module ratchet | `tests/Unit/ServiceModuleCoreDependencyTest.php` |
| One writer during cutovers and restores | `CORE_MASTER_DATA_WRITES=frozen` | `tests/Feature/Core/CutoverToolingTest.php` |
| Every change audited with actor, organization and request ID | Core actions | Audit Center, `audit_logs.request_id` |
| Data stays consistent | `backbone:audit-integrity --strict`; `production:readiness` Core section; foreign keys on the assignment pointer and card lineage | `BackboneStageTwoTest`, `CoreOperationalSafeguardsTest` |
| An organization-level role reaches only its scope (its own organization when it has no scope record) | `OrganizationScopeService::requiresExplicitScope` (ADR 0003) | `OrganizationScopeServiceTest`, `AuditSealAndScopeGapsTest` |
| Audit rows cannot be changed unnoticed | daily HMAC seals, `audit:verify` | `AuditSealAndScopeGapsTest` |

## What is not yet enforced

- 210 service-module files still read Golden Path models directly (frozen, not removed). Reads are safe inside one application; they block physical separation only.
- Accounts given permissions directly without any role and without a scope record keep the old city-wide reach; `access:scope-gaps` lists them (DIRECT_PERMISSIONS_UNSCOPED) for an administrator to give them a role and a scope.
