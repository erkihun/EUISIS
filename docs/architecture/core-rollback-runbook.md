# Core rollback runbook

Companion to [core-cutover-runbook.md](core-cutover-runbook.md). Not executed
yet; rehearse together with the cutover on staging.

## Rollback triggers

Any one of these during or after a cutover window:

- `core:snapshot --compare` reports a difference that is not explained by the plan;
- `backbone:audit-integrity --strict` reports a new finding;
- `/health/ready` fails for more than five minutes after the switch;
- ID-card verification (`/id-checker/{cardUuid}`, `/api/v1/id-cards/verify/{token}`) fails for valid cards;
- sign-in or permission checks fail for administrators of a checked organization;
- the integration outbox stops draining (Integration Center → Events waiting keeps rising) or webhook failures jump;
- the release manager calls it.

## While writes are still frozen (preferred)

Nothing has been written to the target, so the source is still the truth.

1. Set `CORE_MASTER_DATA_WRITES=rollback` (read-only, like `cutover`).
2. Point the application back at the source database (restore the previous `.env` database settings), `php artisan config:cache`.
3. `php artisan core:snapshot --compare=before.json` against the source must exit 0.
4. Open writes (`CORE_MASTER_DATA_WRITES=core`, `config:cache`), start workers, run smoke tests.
5. Record the incident and keep the target for analysis; do not drop it.

## After writes were reopened on the target

Changes made on the target after the switch must not be lost.

1. Freeze writes again.
2. Export what changed on the target since the switch: audit log rows from the switch time onward (`/audit-logs?date_from=…`), integration events from the switch cursor (change feed), and a fresh `core:snapshot`.
3. Decide (release manager with the Core data steward) whether to stay and fix forward, or to re-apply those changes on the source by hand through the normal Core actions. Never copy rows back with SQL.
4. Then follow the frozen-writes steps above.

## Never during a rollback

- No `migrate:fresh`, `db:wipe`, `TRUNCATE`, dropped databases or destructive migration rollbacks.
- No regenerated UUIDs, card numbers or QR references.
- No deleted audit rows or integration events.
- No secrets in tickets, chat or this document.

Database restore procedures: [../runbooks/disaster-recovery.md](../runbooks/disaster-recovery.md), [../runbooks/postgresql-pitr.md](../runbooks/postgresql-pitr.md), [../runbooks/restore-validation.md](../runbooks/restore-validation.md). Application deployment rollback: [../runbook/deployment-and-rollback.md](../runbook/deployment-and-rollback.md).
