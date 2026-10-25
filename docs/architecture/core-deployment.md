# Core deployment

Core ships inside the EUISIS application today, so it is deployed by the
existing process: [../runbook/deployment-and-rollback.md](../runbook/deployment-and-rollback.md),
[../production-runbook.md](../production-runbook.md) and the production branch
built by `scripts/release/build-production-branch.sh`. This page lists what is specific
to Core.

## Configuration

| Setting | Default | Purpose |
| --- | --- | --- |
| `CORE_MASTER_DATA_WRITES` | `core` | Write mode (`AppnumsMasterDataWriteMode`): `core` or `legacy` write; `cutover` and `rollback` (and the old `frozen`) refuse every master-data change (organizations, units, positions, employees, cards, code rules…) with a clear message; reads keep working. Used during cutovers and restores. |
| `INTEGRATION_EVENTS_ENABLED` | `true` | Outbox recording |
| `INTEGRATION_DISABLED_EVENT_TYPES` | empty | Switch off individual event types (for example the new `position.*` and `id_card.*` types) without a release |
| `INTEGRATION_CHANGE_FEED_ENABLED`, `INTEGRATION_WEBHOOKS_ENABLED` | `false` | Partner delivery channels; switch on per environment |

Configuration is cached in production: run `php artisan config:cache` after changing any of them.

## Release steps for a Core change

1. CI green (tests, translation audit, build).
2. Backup confirmed.
3. Migrations are additive (new tables, nullable columns, indexes); anything else needs its own reviewed plan. Never `migrate:fresh`, `db:wipe` or destructive rollbacks on a shared database.
4. Deploy, `php artisan migrate --force`, `config:cache`, `route:cache`, `view:cache`, restart workers.
5. `php artisan backbone:audit-integrity` and the Core smoke checks (`php artisan list core` for what the release provides).
6. Watch the items in [core-observability.md](core-observability.md) for an hour.

## Workers and schedule Core relies on

- Queue `integrations` (webhook deliveries) and the default queue (projections, notifications).
- Scheduler: `integrations:publish`, `integrations:deliver`, `integrations:purge` as registered in `routes/console.php`.

## As a separate application (later)

Separate build and deploy pipeline in the same monorepo, its own `.env` and
secrets, its own database credentials (services never receive them), and the
service applications configured with a Core base URL and a scoped token
([core-service-auth.md](core-service-auth.md)). Not built; see
[core-physical-separation.md](core-physical-separation.md).
