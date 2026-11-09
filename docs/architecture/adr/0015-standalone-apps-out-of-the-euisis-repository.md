# ADR 0015 — Standalone apps live outside the EUISIS repository

- Status: accepted
- Date: 2026-10-29
- Deciders: product owner (asked for EUISIS to hold only EUISIS)
- Related: [ADR 0006](0006-standalone-core-platform.md) (the Core platform), [ADR 0013](0013-court-ms-integration.md) (court-ms), [ADR 0014](0014-cafeteria-platform.md) (the cafeteria platform, already in its own repository)

## Context

Two standalone applications were still inside the EUISIS folder and repository:

- `core-platform/`: the standalone Core (ADR 0006), with its own backend, frontend, database and CI workflow
  (`.github/workflows/core-platform.yml`), plus its runbooks (`docs/runbook/core-*.md`) and go-live pack
  (`docs/go-live/core/`) under the EUISIS docs.
- `external-apps/cafeteria-system/`: the first, separate cafeteria app, superseded by the cafeteria platform
  (ADR 0014).

court-ms and the cafeteria platform already live in their own repositories beside EUISIS. The owner wants
the EUISIS repository to be EUISIS alone.

## Decision

1. **Each standalone app is its own repository, checked out beside EUISIS** (`C:\Users\Erkis\Documents\GitHub\…`):
   - `core-platform/`, with its full history (split from this repository with `git subtree split`), its CI
     workflow (`.github/workflows/ci.yml`, which checks out EUISIS beside it for the drift check, as the
     cafeteria platform does; secret `EUISIS_REPO_TOKEN`), and the Core runbooks and go-live pack under its
     own `docs/runbook/` and `docs/go-live/core/`.
   - `cafeteria-system-legacy/`, with its history, kept for reference only. The cafeteria platform replaces it.
2. **The Core still copies its screens from EUISIS.** `frontend/scripts/sync-from-euisis.mjs` now reads the
   sibling `../EUISIS` (or `$EUISIS_PATH`, or its argument) instead of the parent folder.
3. **EUISIS keeps what EUISIS itself uses.** `packages/euisis-ui` stays: EUISIS imports it (`@euisis/ui`), and the
   satellite apps copy it from here. `loadtest/` stays: it is the EUISIS load-test rig.
4. ADRs 0006–0012 stay here as the record of how the Core was decided. Where they name `core-platform/…` or the moved
   runbooks, read that as a path in the core-platform repository. The dated backbone audit
   (`docs/architecture/addis-one-master-backbone-audit.*`) still names `external-apps/cafeteria-system/…`; it is a snapshot and is not rewritten.

## Consequences

- EUISIS CI no longer runs the Core suite. The Core repository runs it, and its drift job fails when the copied
  screens no longer match EUISIS.
- The core-platform repository has no remote yet. Pushing it to GitHub, and adding `EUISIS_REPO_TOKEN` there,
  is a step for the owner.
- An older loose copy of the Core that already sat beside EUISIS (not a repository, from 2026-10-26) was kept as
  `core-platform-old-snapshot-2026-10-26/` and is not used.
