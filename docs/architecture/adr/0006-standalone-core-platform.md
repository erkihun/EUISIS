# ADR 0006: The Core as a standalone platform

- Status: Accepted
- Date: 2026-10-25
- Decided by: the platform owner (delegated decision, recorded for review)

## Context
ADR 0002 made the Core (Organization → Unit → Position → Employee → Assignment →
ID Card) the write authority inside EUISIS, and the Core stages 1–8 put
contracts, ratchets and snapshots around it. The Core still shared one
application, one database and one deployment with every service module. The
owner asked for the Core to stand on its own: a separate backend, frontend and
database in its own folder.

## Decision
The Core platform lives in `core-platform/`:

- `core-platform/backend`: a Laravel 13 API (`/api/v1`), token sign-in
  (Sanctum, 8-hour tokens), its own database (`euisis_core`). It owns the 14
  Golden Path tables with the same columns and identifiers as EUISIS, the
  organization scope rule (scope = WHERE over the published hierarchy; fails
  closed), seat capacity, one current placement per employee, card transitions
  and its own audit log carrying the request id.
- `core-platform/frontend`: a Vite + React single-page app (English and
  Amharic) that talks only to the Core API.
- Data enters by copy: `core:import-from-euisis` reads EUISIS (read only),
  keeps every UUID, number and public card reference, and `core:snapshot`
  (same algorithm and table list as EUISIS `core:snapshot`) proves the copy
  matches before anyone relies on it.

EUISIS keeps writing master data until the cutover below. Service modules are
not moved; they keep reading the Core tables inside EUISIS until each one is
switched to the Core API.

## Consequences
- The Core can be deployed, scaled, backed up and secured on its own.
- Two copies of the master data exist during the transition. Only one may be
  the writer at any time (ADR 0002 write modes): EUISIS in `core`/`legacy`
  mode before the cutover; the Core platform after it, with EUISIS put in
  `cutover` (read only) and then fed from the Core.
- Accounts are separate: Core accounts have one of three roles
  (`core_viewer`, `core_officer`, `core_admin`) and an optional scope
  organization. EUISIS roles are not copied.

## Migration impact
1. Expand: deploy the Core platform with an empty database; `migrate`.
2. Copy: `core:import-from-euisis`, then compare `core:snapshot` files from
   both sides; any difference stops the cutover.
3. Switch: set EUISIS `CORE_MASTER_DATA_WRITES=cutover`, re-run the copy with
   `--fresh-tables`, compare again, open the Core platform for writes.
4. Contract (later, per module): service modules read from the Core API
   instead of the shared tables. Not started.
Rollback before step 3 is dropping the Core database. After step 3 it is
setting EUISIS back to `core` mode; changes made in the Core platform in
between must be replayed from its audit log.

## Security impact
Every read and write applies the organization scope. Card verification
returns validity, status, number and name only. Personal fields (national id,
phone, address, date of birth) are hidden from API responses. Writes need an
officer or admin role and a reason for card changes; each one is audited with
the actor, request id, IP and user agent.

## Operational impact
New runbook steps live in `core-platform/README.md`. CI runs the Core backend
tests and frontend build (`.github/workflows/core-platform.yml`). Backups of
`euisis_core` are needed from the moment step 3 is reached.
