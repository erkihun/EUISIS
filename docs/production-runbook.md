# Production runbook

Day-to-day operation of EUISIS in a multi-node production. Deployment steps are
in [deployment and rollback](runbook/deployment-and-rollback.md); backups in
[database backup](runbooks/database-backup.md); incidents in
[incident response](incident-response.md). No command here prints a secret.

## Processes that must be running

| Process | Command | Count |
| --- | --- | --- |
| Web | nginx + PHP-FPM on each app node | N nodes |
| Scheduler | `* * * * * php artisan schedule:run` | exactly one host (tasks are also `onOneServer`) |
| Worker — operational | `php artisan queue:work --queue=default --timeout=120 --tries=3` | ≥ 2 |
| Worker — notifications | `php artisan queue:work --queue=notifications --timeout=120 --tries=3` | ≥ 2 |
| Worker — long jobs | `php artisan queue:work --queue=long --timeout=600 --tries=3` | ≥ 1 |

Run workers under a supervisor (systemd/supervisord) with automatic restart and
`stopwaitsecs` ≥ the worker timeout. `php artisan queue:restart` after each deploy.

## Scheduled tasks (routes/console.php)

| Task | Cadence | Notes |
| --- | --- | --- |
| `api:prune-logs` | daily 02:30 | 90-day API log retention |
| `nfc:prune-challenges` | daily 02:45 | expired NFC nonces |
| `cafeteria:sync-policy-statuses` | daily 00:05 | policy status labels |
| `daily-activities:send-reminders` | every 15 min | each employee evaluated once per date/type |
| `backup:health --monitor` | every 5 min | needs pgBackRest access on the scheduler host |
| `grievances:process-sla` | every 15 min | SLA reminders/escalation (idempotent) |
| `assessments:oversight-monitor` | daily 06:30 | |
| `assessments:evaluator-reminders` | daily 07:00 | |

All run `withoutOverlapping()->onOneServer()` (lock in the shared cache store).

## Configuration check

```bash
php artisan production:readiness --strict   # must end "No blocking items"
php artisan security:production-check
```

Required production values (see [load balancing](load-balancing.md) for why):
`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`,
`APP_TRUSTED_PROXIES=<lb/proxy addresses>`, `SESSION_DRIVER=database|redis`,
`SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `CACHE_STORE` and
`QUEUE_CONNECTION` shared (`database|redis`), `APP_MAINTENANCE_DRIVER=cache`,
`STORAGE_PRIVATE_DRIVER=s3` and `STORAGE_PUBLIC_DRIVER=s3` with buckets,
`DB_CONNECTION=pgsql`, `FEATURE_TRANSPORT=false`.

## Daily checks

- `GET /health/ready` green on every node; 5xx rate and p95 latency normal.
- `php artisan queue:failed` — investigate and retry or forget each job.
- Queue depth per queue (`jobs` table grouped by `queue`, or Redis `LLEN`) not growing.
- Backup health page (`/system/backup-recovery`) green: last full/diff, WAL lag.
- Error log reviewed; audit log sampled for unexpected actors.

## Weekly checks

- `php artisan data:audit-duplicates` and `php artisan structure:audit` — any HIGH
  finding gets an owner; repairs are never automatic (NEEDS_DATA_REVIEW).
- `pg_stat_statements` top queries; tables with growing sequential scans.
- Disk/WAL growth vs. the [capacity plan](capacity-plan.md).

## Common operations

| Task | How |
| --- | --- |
| Maintenance mode (all nodes) | `php artisan down --retry=60` (needs `APP_MAINTENANCE_DRIVER=cache` on a shared store) / `php artisan up` |
| Clear caches after config change | `php artisan optimize:clear && php artisan optimize` on every node |
| Permission cache | `php artisan permission:cache-reset` |
| Retry failed jobs | `php artisan queue:retry all` (after fixing the cause) |
| Enable a future module | set `FEATURE_TRANSPORT=true` only after completion and approval; redeploy config |
| Move files to object storage | copy `storage/app/private` and `storage/app/public` to the buckets preserving paths, then set the `STORAGE_*_DRIVER` variables |

## Degraded modes

| Failure | Effect | Action |
| --- | --- | --- |
| One app node down | LB stops routing after readiness fails | replace the node |
| Database unreachable | `/health/ready` 503 on all nodes; site unavailable | fail over / restore per [disaster recovery](runbooks/disaster-recovery.md) |
| Shared cache/queue store down (Redis) | sessions lost (users re-login), jobs pause, rate limits unavailable | restore the store; jobs resume from the queue |
| Queue workers down | notifications and long jobs pile up; requests still served | restart workers; check `queue:failed` |
| Object storage down | uploads/downloads fail; `/health/ready` 503 (storage check) | restore storage; nothing is lost from the database |
| SMS/mail provider down | queued notifications retry, then fail to `queue:failed` | retry after recovery |
| Scheduler host down | reminders/SLA sweeps paused | start the scheduler on another host (onOneServer prevents duplicates) |

Each mode must be exercised in staging before go-live; record results in the
[go-live checklist](go-live-checklist.md).
