# Core platform: deployment runbook

How a Core release reaches a server, and the rules that keep each deployment
reversible. This runbook was rehearsed on 2026-10-26 (results:
[core-go-live-evidence.md](../go-live/core/core-go-live-evidence.md)). The
scripts live in [`core-platform/deploy/release/`](../../core-platform/deploy/release/).
Rollback has its own runbook: [core-rollback.md](core-rollback.md).

## Target topology (production and staging)

Staging must use the same classes as production. **Not yet provisioned** — owner TBD
(see the owner matrix).

| Part | Production | Notes |
|---|---|---|
| OS | Linux (distribution TBD) | |
| Web | Nginx with TLS, then PHP-FPM 8.4 | the document root is `/srv/core/current/backend/public` |
| Database | PostgreSQL 18 | MariaDB/MySQL also migrate and pass the suite (ADR 0012), but PostgreSQL is the engine that has been rehearsed |
| Sessions, cache, queue | the `database` drivers | Redis is optional: the Core has no Redis dependency, and it has not been tested with Redis |
| Workers | `php artisan queue:work database --queue=notifications,default --tries=3 --max-time=3600`, under systemd or supervisor | Horizon is not used: it needs Redis |
| Scheduler | `* * * * * php /srv/core/current/backend/artisan schedule:run` | every task uses `withoutOverlapping()->onOneServer()` |
| Files | private disks under `/srv/core/shared/storage/app/private` (a dedicated volume), or S3 | never under `public/` (`core:config-audit` PRIVATE_STORAGE) |
| Seal heads | `CORE_AUDIT_HEAD_DISK` on another host or in an object-locked bucket | |

## Layout on a server

```
/srv/core/
  releases/<sha8>/        unpacked artifacts: RELEASE + backend/
  shared/.env             rendered from the secret store; mode 0640, owner root:www-data
  shared/storage/         Laravel storage (private disks, logs, framework)
  current -> releases/<sha8>
  CURRENT_RELEASE, PREVIOUS_RELEASE
```

## 0. Before the window

1. The release commit is agreed and tagged (`core-vX.Y.Z`), it is green in CI,
   and its manifest is published ([core-release-manifest.md](../go-live/core/core-release-manifest.md)).
2. The artifact is built once, in CI or on a build host, from that tag:
   `core-platform/deploy/release/build-artifact.sh core-vX.Y.Z /srv/artifacts`.
   The same artifact goes to staging and then to production. Never build on a
   production server.
3. Migration review: every new migration is **backward-compatible with the
   previous release** (see "Migration rules" below). If a migration is not,
   plan a maintenance window instead.
4. A backup newer than the window has succeeded (System Health › Backups, or
   `/health/monitor` `backup_status=ok`).

## 1. Deploy

```bash
CORE_ROOT=/srv/core SMOKE_URL=https://core.example/ \
RELOAD_CMD='sudo systemctl reload php8.4-fpm' \
WORKERS_CMD='sudo systemctl restart core-worker@*' \
CORE_MONITOR_TOKEN="$(cat /etc/core/monitor.token)" \
core-platform/deploy/release/deploy.sh /srv/artifacts/core-<sha8>.tar.gz
```

`deploy.sh` stops at the first failure, and up to step 7 the previous
release keeps serving. It:

1. checks the artifact's SHA-256;
2. unpacks it into `releases/<sha8>`;
3. links `shared/.env` and `shared/storage`;
4. checks that the release boots and runs `migrate --force` while the old
   code still serves (the reason for the migration rules);
5. runs `optimize`, which caches config, events, routes and views;
6. runs `core:config-audit`, which fails the deployment in production on any
   unsafe setting (ADR 0012);
7. switches `current` atomically (rename) and reloads PHP-FPM;
8. runs `queue:restart` and restarts the workers;
9. runs the smoke test:
   - `/up`, `/health/live`, `/health/ready` and `/login` answer 200;
   - `/login` serves this release's built script;
   - `/health/monitor` reports this release's commit;
10. keeps the newest `KEEP` releases, plus current and previous.

## 2. Verify (first 15 minutes)

- `/health/monitor` reports `status: ok`.
- Failed jobs are not rising.
- `scheduler_stale_commands` is empty after 15 minutes.
- Sign in as an administrator, open an employee, and check a card on the
  public check page.
- Watch the 5xx rate and API p95 on the external monitor
  ([core-monitoring-and-alerts.md](core-monitoring-and-alerts.md)).

## Migration rules (zero downtime)

The previous release must run on the new schema, because the migration runs
before the switch and rollback leaves the schema in place. Within a release
line:

- **Expand only.** Add tables, nullable columns or columns with a default, and
  indexes. Never rename or drop a column that the previous release reads.
  Never tighten a constraint that its writes could break.
- **Backfill apart from the schema change** when a table is large: a batched
  command or job after the deploy, not one transaction in the migration.
- **PostgreSQL indexes on large tables:** `CREATE INDEX CONCURRENTLY` in a
  migration with `public $withinTransaction = false;`. For a new constraint,
  use `NOT VALID`, then `VALIDATE CONSTRAINT` in a later step.
- **Contract in a later release:** drop old columns or tables only once no
  deployed release uses them.
- `migrate:rollback` is **not** a production tool: `down()` methods can drop
  data. A schema mistake is fixed forward with a new migration.

### Review of the go-live migrations (2026-10-26)

Measured on 300,000 synthetic employees (PostgreSQL 18.6, developer machine,
2026-10-25):

| Migration | On a populated database | At first go-live |
|---|---|---|
| `2026_10_28_100400` credentials (backfill: 2 min 6 s for 245,000 cards) | the foreign key to `id_cards` holds SHARE ROW EXCLUSIVE until commit, so card writes wait about 2 minutes | none: the schema is migrated on an empty database and `core:import-from-euisis` then runs the same backfill (`LegacyCredentialBackfill`) |
| `2026_10_28_100200` provenance (29 s) | one `UPDATE` touches every placement, so concurrent placement updates wait about 29 s | none (empty database) |
| `2026_10_28_100500` audit integrity (1 s) | triggers and columns on `core_audit_logs` | none |
| `2026_10_29_100100` scale index (under 1 s) | plain `CREATE INDEX`, so employee writes wait about 1 s | none |

**Decision:** the go-live sequence is: migrate an empty database, then
import (`core-backup-and-restore.md` and `core-go-live-evidence.md` §8). These
migrations are safe for it and are not rewritten. If any of them must ever run
on a populated live database (for example an existing pilot database), run
them in a maintenance window: with no traffic their locks are harmless.

## Go-live data migration (EUISIS → Core)

Rehearsed on 2026-10-26 with 28 and 220,000 employees (evidence §8).

1. **Before the window.**
   - EUISIS sets an explicit `NATIONAL_ID_HASH_KEY` and runs its rehash.
   - The Core gets the same value (D-13).
   - A sanitized production copy has been rehearsed with
     `deploy/migration/reconcile.php` (R-09).
2. **Freeze EUISIS writes** to the Golden Path tables, then take its
   fingerprint: `php artisan core:snapshot --output=euisis.json` (in EUISIS).
3. **Deploy the Core release** to the empty production database with
   `deploy.sh`.
4. **Import on the production Core server, with its production `.env`:**
   `php artisan core:import-from-euisis --chunk=500`.
   - The import creates the cards' legacy credentials, hashed with
     `CORE_CREDENTIAL_KEYS` and encrypted with `APP_KEY`.
   - An import run anywhere else (a laptop, another environment) produces
     credentials that cannot verify in production. They cannot be re-keyed
     either, because the token is encrypted with the other APP_KEY. The
     rehearsal hit exactly this and had to regenerate them.
5. **Verify.**
   - `php artisan core:snapshot --compare=euisis.json` must report every
     table identical.
   - `php ../deploy/migration/reconcile.php reconcile.json` must pass:
     0 rejected, 0 duplicates, 0 orphans, 0 live cards without a credential.
   - Keep `reconcile.json` as evidence.
6. **Review the "review" items** (positions without masters, placements of
   unspecified type) with the data owner. They are data quality, not loss.
7. **Sign-off** by the data-migration owner. Only then open the Core to users.

## Scheduled processes and workers

| Process | How | Overlap |
|---|---|---|
| Scheduler | cron every minute: `schedule:run` | each task: `withoutOverlapping()->onOneServer()` (tested: `CoreGoLiveOperationsTest`) |
| Queue workers | `queue:work database --queue=notifications,default --tries=3 --max-time=3600 --memory=256`, at least 2 processes. **Both queues**: notifications go to `notifications` (the rehearsal's workers listened to `default` only and 45 notifications waited, ADR 0012) | jobs are idempotent by design (exports, notifications, imports, webhooks) |
| Tasks | `core:expire-cards` 00:15 · `core:deliver-webhooks` every minute · `core:audit-seal --all` and `core:audit-ship-heads` every 5 minutes · `core:audit-verify` 02:30 · `core:break-glass-expire` every minute · `core:prune-integration-data` 03:15 · `core:notify-expiring` every 5 minutes · `core:data-quality-scan` hourly at :20 · `core:prune-exports` 03:30 | System Health warns when any of them is late (`SystemHealth::SCHEDULED`) |
