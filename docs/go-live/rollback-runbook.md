# Go-live rollback runbook (Core)

## Decide

Roll back when a Severity 1 appears and cannot be fixed within the window:
wrong access to another organization's data, a valid card refused or an invalid
card accepted, data lost or changed by the deploy (`core:snapshot --compare`
differs), sign-in broken for administrators. The decision owner named in the
go-live runbook decides; record the time and reason.

## Roll back the application (data untouched)

Migrations after the baseline are additive (`MigrationSafetyTest`), so the
previous release runs on the new schema.

1. `php artisan down`.
2. Redeploy the previous release per [../runbook/deployment-and-rollback.md](../runbook/deployment-and-rollback.md); do **not** run `migrate:rollback`.
3. `config:cache`, `route:cache`, restart workers.
4. `php artisan core:snapshot --compare=golive-before.json` and `backbone:audit-integrity --strict`.
5. `php artisan up`; verify sign-in and one card.

## Roll back data (only if the deploy damaged data)

Freeze writes (`CORE_MASTER_DATA_WRITES=frozen`) and follow
[../architecture/core-rollback-runbook.md](../architecture/core-rollback-runbook.md)
and the restore runbooks ([../runbooks/disaster-recovery.md](../runbooks/disaster-recovery.md),
[../runbooks/postgresql-pitr.md](../runbooks/postgresql-pitr.md)). Changes
people made after go-live are re-applied through Core actions, never by SQL.

## Never

`migrate:fresh`, `db:wipe`, `TRUNCATE`, dropped tables, destructive migration
rollbacks, regenerated UUIDs, card numbers or QR references, deleted audit rows.

## Afterwards

Incident record per [../incident-response-plan.md](../incident-response-plan.md);
the defect goes into the acceptance matrix; a new go-live date only after the
fix passes the full matrix again.
