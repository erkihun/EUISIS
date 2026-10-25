# Core Golden Path: go-live package

| Document | For |
|---|---|
| [core-go-live-evidence.md](core-go-live-evidence.md) | what was executed on 2026-10-26, with results |
| [core-release-manifest.md](core-release-manifest.md) | the release candidate: commit, versions, migrations, environment, schedule, workers (generated) |
| [core-owner-matrix-and-risks.md](core-owner-matrix-and-risks.md) | owners (BLOCKED until assigned), the decision register, the risk register |
| [core-go-live-checklist.md](core-go-live-checklist.md) | the 19 go-live gates and the external evidence each still needs |
| [core-uat-package.md](core-uat-package.md), [core-uat-signoff.csv](core-uat-signoff.csv) | business UAT scenarios and the blank sign-off record |
| [core-pentest-pack.md](core-pentest-pack.md) | handing the release to the independent penetration testers |
| [core-amharic-review.md](core-amharic-review.md), [core-amharic-review.csv](core-amharic-review.csv) | the native-speaker review |

Runbooks (in `docs/runbook/`):

- [deployment](../../runbook/core-deployment.md)
- [rollback](../../runbook/core-rollback.md)
- [backup and restore](../../runbook/core-backup-and-restore.md)
- [operations incidents](../../runbook/core-operations-runbooks.md)
- [monitoring and alerts](../../runbook/core-monitoring-and-alerts.md)
- [staging rehearsal](../../runbook/core-staging-rehearsal.md)
- [readiness checklist](../../runbook/core-readiness-checklist.md)

Decisions: [ADR 0012](../../architecture/adr/0012-core-go-live-operations.md).

Tooling (in `core-platform/`):

- `deploy/release/`: build an artifact, deploy, roll back, generate the manifest.
- `deploy/backup/`: back up, run a restore drill, check a restore; pgBackRest, cron and environment templates.
- `deploy/migration/reconcile.php`: reconcile a migration.
- `deploy/staging/golden-path-smoke.php`: run the Golden Path smoke test.
- `loadtest/`: k6 scripts and the workstation rig.
