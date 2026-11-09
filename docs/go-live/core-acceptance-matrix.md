# Core acceptance matrix

Status on 24 October 2026: **not ready for go-live certification**. Automated
evidence is listed where it exists. Every UAT, security, data and business
sign-off below is **pending**: none has been given, and none may be recorded
here without the signer's name, date and the evidence they reviewed.

## Acceptance criteria

| # | Criterion | Automated evidence | UAT | State |
| --- | --- | --- | --- | --- |
| A1 | Organization, unit, position, employee, assignment and card chain works end to end | `tests/Feature/GoldenPath/*`, `BackboneStage*Test` | pending | automated pass |
| A2 | Core is the only writer of master data | `BackboneBoundaryTest`, `MachineWriteBoundaryTest` | n/a | automated pass |
| A3 | Assignment ends only with employment; same-day undo; history kept | `AssignmentLifecycleTest` | pending | automated pass |
| A4 | Permission = what, organization scope = where, on Core pages and API | `tests/Feature/OrganizationScope/*`, `tests/Feature/Rbac/*`, `CoreWorkspacePagesTest`, `OrganizationEmployeeApiTest` | pending | automated pass; organization-level roles without a scope record confined to their own organization (ADR 0003) |
| A5 | Public QR verification is privacy-minimised and fails closed | `PublicIdCheckerTest`, `IdCardIntegrationApiTest` | pending | automated pass |
| A6 | Core workspace pages (dashboard, assignments, integration, audit, settings) | `CoreWorkspaceTest`, `CoreWorkspacePagesTest`, `CoreDashboardTest` | pending | automated pass |
| A7 | Integration outbox, change feed and webhooks | `tests/Feature/Integration/*` | partner test pending | automated pass |
| A8 | Read contracts for service modules | `CoreReadContractsTest`, `DownstreamMigrationTest` | n/a | automated pass |
| A9 | Amharic and English complete | `npm run i18n:audit` in CI | pending (language review) | automated pass |
| A10 | Ethiopian and Gregorian dates | `EthiopianCalendarTest`, `core:smoke` | pending | automated pass |
| A11 | Cutover tooling (write freeze, snapshot) | `CutoverToolingTest` | rehearsal pending | automated pass |
| A12 | Go-live gate and smoke test | `CoreOperationalSafeguardsTest` | on target environment pending | automated pass |
| A13 | No destructive migration after the baseline | `MigrationSafetyTest` | n/a | automated pass |
| A16 | Audit log tamper evidence (seal, verify, retention) | `AuditSealAndScopeGapsTest` | n/a | automated pass |
| A17 | Provider card checks name their provider; service type matches the path | `MasterBackboneRemediationApiTest` (ADR 0003 case) | partner test pending | automated pass |
| A18 | Partner OpenAPI document matches every `/api/v1` route | `OpenApiContractTest` (empty backlog) | n/a | automated pass |
| A14 | Backups and restore | Backup page, `production:readiness` | restore drill pending | **not ready** |
| A15 | Performance at expected load | local benchmarks only ([../architecture/core-production-baseline.md](../architecture/core-production-baseline.md)) | production-class test pending | **not measured** |

## Go / no-go

Go requires: every row automated pass on the release candidate, every UAT
column signed, A14 and A15 ready, every sign-off below given, and the
conditions in [../architecture/production-cutover.md](../architecture/production-cutover.md) met.
**Today: NO-GO.**

## Sign-offs

| Sign-off | Role | Name | Date | Evidence reviewed |
| --- | --- | --- | --- | --- |
| Business acceptance (UAT, gate G4) | Business Owner | — pending — | | |
| Data acceptance (counts, integrity, migration) | Data Owner, Data Steward | — pending — | | |
| Security acceptance (independent penetration test, access review, gate G4) | Information Security Lead | — pending — | | |
| Operations handover (runbooks, monitoring, on-call) | Technical Custodian, Database/Infrastructure Administrator | — pending — | | |
| Recovery targets validated by drills (gates G1, G2) | DR Coordinator, Business Owner | — pending — | | |
| Governance roles named (gate G5) | Project Sponsor | — pending — | | |

Roles: [../governance/roles-register.md](../governance/roles-register.md). Gates and their evidence: [go-live-gates.md](go-live-gates.md). Go-live is not allowed while any P0/P1 security or business defect is open.

## Defect register

| ID | Severity | Description | State |
| --- | --- | --- | --- |
| D-1 | 3 | `DesignImportTest` fails intermittently in parallel runs (test isolation, not product behaviour) | open |
| D-2 | 3 | Some suites fail only on MariaDB, already failing on `main` before the Core work (field work, grievance report aggregation, vacancy announcement, knowledge pages, organization feedback form, one Golden Path case); PostgreSQL is the production engine | open, outside Core |

Add every UAT finding here with severity 1 (wrong access, wrong card validity,
data loss), 2 (blocks a task), 3 (workaround exists) or 4 (cosmetic).

## Known issues accepted for now

- 210 service-module files read Golden Path models directly (frozen by ratchet); blocks physical separation only.
- The old sidebar entries remain alongside the Core workspace (DUAL AVAILABLE) until the deprecation criteria are met.
