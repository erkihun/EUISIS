# Core physical separation

Status on 25 October 2026: **not ready**. Separation is a later decision; this
document records the boundary, the chosen structure, the prerequisites and the
tooling that already exists.

## Readiness check (Stage 3 state)

| Prerequisite | State | Evidence |
| --- | --- | --- |
| No service module writes Golden Path records directly | met | `tests/Unit/BackboneBoundaryTest.php` (only the development load-test seeder is allowed) |
| Downstream reads go through contracts or the API | **not met**: 207 service-module files still import Golden Path models | `tests/Unit/ServiceModuleCoreDependencyTest.php`, [module-integration-matrix.md](module-integration-matrix.md) |
| Event consumers working | met in process (transport and communication listen to backbone events); partners use the outbox, change feed and webhooks | `BackboneStageTwoTest`, `tests/Feature/Integration/*` |
| Core write actions authoritative | met inside the application; machine writes refused (ADR 0002) | `MachineWriteBoundaryTest` |
| Legacy compatibility paths documented | met | [core-workspace.md](core-workspace.md) "Page availability" |
| Critical tests pass | met on SQLite and on MariaDB except failures already present on main | test logs per release |
| Staging that approximates production | **not available to this work** | — |

Physical database separation must not start while the second row is open:
moving the tables would break the remaining direct readers. The 207-file import
count is a migration ratchet, not an exhaustive inventory of relationship or raw
SQL dependencies.

## Boundary

- **Application A, Core:** organizations and their types, names history, hierarchy versions, edges and closure paths, change requests; units, unit types and relationships; grades, occupations, professions, specializations, positions, establishments, occupancies, profiles, job descriptions; employees, status history, documents, duplicate flags, imports, corrections, contact verification; assignments; ID cards, requests, print batches, snapshots, issuances, replacements, verifications, templates, NFC credentials; users, roles, permissions, organization scopes; partner applications, tokens, endpoints, request logs, idempotency keys; integration events and webhooks; audit; code rules; the Core settings groups. Table list: [database-ownership.md](database-ownership.md).
- **Application B, services:** recruitment, transfer workflow, EPMS and assessment, cafeteria, transport, providers, feedback, grievance, discipline and appeal, court cases, daily and field work, organization design and workforce planning, communication, help desk, Situation Room, intelligence.

## Structure decision

**Keep one repository and one application now; when separation is justified, use one monorepo with two Laravel applications** (`core-platform/`, `euisis-services/`). Reasons: one small team, one release train, one CI pipeline, shared UI kit (`packages/euisis-ui`) and shared translation and calendar code. Two repositories would add release coordination without a security gain while the team is the same. Revisit if Core gets its own team or a different hosting owner.

Shared code allowed in a package: the UI kit, calendar and translation helpers, error contracts, correlation-id helpers. Never shared: Eloquent models of Golden Path tables. Services read Core through contracts (in process) or `/api/v1` (out of process).

## Transition modes

1. Separate applications, same database (only reads move to the API; writes already go through Core).
2. Separate applications, separate schemas on one server.
3. Separate database servers.

Each step needs: every downstream read on contracts/API for the module being moved, event-fed projections where a module cannot call the API synchronously, and a rehearsed cutover ([core-cutover-runbook.md](core-cutover-runbook.md)).

## Tooling already in place

| Need | Tool |
| --- | --- |
| Single writer during a cutover or restore | `CORE_MASTER_DATA_WRITES=frozen` (`GuardMasterDataWrites`) |
| Before/after data validation | `php artisan core:snapshot --output=…` / `--compare=…` |
| Referential integrity | `php artisan backbone:audit-integrity --strict` |
| Duplicate-master evidence | `php artisan backbone:duplicate-masters --json` |
| Out-of-process integration | `/api/v1` (scopes and organization bounds), outbox, change feed with cursors, signed webhooks |
| Health | `/up`, `/health/live`, `/health/ready` |

Related: [database-ownership.md](database-ownership.md), [core-service-auth.md](core-service-auth.md), [core-observability.md](core-observability.md), [core-deployment.md](core-deployment.md), [core-rollback-runbook.md](core-rollback-runbook.md).
