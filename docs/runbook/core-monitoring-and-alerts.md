# Core platform: external monitoring and alerts

Internal System Health is not enough: it cannot alert when the application is
down. The Core gives an external monitor three probes (ADR 0012). The monitor
itself (the organization's existing tool, for example Zabbix, Prometheus with
blackbox/json exporters, Uptime Kuma or Grafana) is **not yet connected**. The
owner, channel and tool are TBD in the [owner matrix](../go-live/core/core-owner-matrix-and-risks.md).
Until the owner and channel are named, **no alert is active**: an alert that
nobody receives is not an alert.

## Probes

| Probe | Auth | Use |
|---|---|---|
| `GET /health/live` | none | the process answers. Load balancer liveness |
| `GET /health/ready` | none | database, cache, queue store and private storage answer. 503 when not. Load balancer readiness and the availability alert |
| `GET /health/monitor` | `Authorization: Bearer <CORE_MONITOR_TOKEN>` | every System Health status plus the figures below. No personal data, hosts or error text. Throttled to 120 requests a minute. 404 while no token is set |
| `GET /up` | none | Laravel's boot check |

`/health/monitor` → `metrics` (five-minute windows where it says `_5m`):
`api_calls_5m`, `api_server_errors_5m`, `api_p95_ms_5m`, `card_verifications_5m`,
`card_verification_server_errors_5m`, `card_verification_p95_ms_5m`, `queue_waiting`,
`queue_oldest_minutes`, `queue_failed_jobs`, `queue_stuck_jobs`, `scheduler_stale_commands`,
`backup_status`, `backup_age_hours`, `audit_last_verification_ok`, `audit_unsealed_rows`,
`audit_heads_ship_failed`, `webhooks_pending`, `webhooks_failed`, `storage_free_percent`,
plus `release` (the deployed commit) and `checks.<name>` = ok, warning, error or unknown.

Poll `/health/monitor` once a minute from outside the application network, and
`/health/ready` every 15–30 s. The web 5xx rate comes from the Nginx or load
balancer access logs (the monitor's log input). The Core does not count its
own web 5xx.

## Alert rules

Thresholds marked **proposal** need the owner's agreement. Every critical alert
must have an owner, a channel and a runbook before it counts as active.

| Alert | Condition | Severity | Runbook | Owner / channel |
|---|---|---|---|---|
| CoreUnavailable | `/health/ready` not 200 for 2 minutes, from 2 locations | critical | operations § Database / Storage | TBD / TBD |
| CoreDatabaseDown | `checks.database` = error, or ready says database=fail | critical | operations § Database unavailable | TBD / TBD |
| CoreRedisDown | not applicable (no Redis) | — | — | — |
| CoreBackupFailed | `checks.backup` = error (a failure after the last success) | critical | operations § Backup failure | TBD / TBD |
| CoreBackupTooOld | `backup_age_hours` > `CORE_BACKUP_MAX_AGE_HOURS` (26), or `backup_status` unknown in production | critical | operations § Backup failure | TBD / TBD |
| CoreRestoreTestStale | `checks.backup` = warning because no restore test passed | warning | backup-and-restore § Restore drill | TBD / TBD |
| CoreQueueBacklog | `queue_oldest_minutes` > 10 (proposal) | warning; critical above 30 | operations § Queue failure | TBD / TBD |
| CoreFailedJobsSpike | `queue_failed_jobs` rose by more than 5 in 15 minutes (proposal) | warning | operations § Queue failure | TBD / TBD |
| CoreSchedulerStopped | `scheduler_stale_commands` contains `core:deliver-webhooks` or `core:break-glass-expire` (the every-minute tasks: the heartbeat) for 5 minutes. A daily task that has not had its first run since installation also appears in the list, so "list not empty" alone is a false alarm (seen on staging, E§10) | critical | deployment § Scheduled processes | TBD / TBD |
| CoreDailyTaskLate | a daily task (`core:expire-cards`, `core:audit-verify`, `core:prune-*`) in `scheduler_stale_commands` more than 26 h after go-live | warning | deployment § Scheduled processes | TBD / TBD |
| Core5xxSpike | web 5xx above 2% of requests over 5 minutes (access logs, proposal), or `api_server_errors_5m` / `api_calls_5m` above 2% | critical | rollback, if it started with a deploy; otherwise logs by request id | TBD / TBD |
| CoreApiLatency | `api_p95_ms_5m` > 1000 for 10 minutes (proposal; the load-test baseline is in the evidence document) | warning | operations § Database unavailable | TBD / TBD |
| CoreCardVerificationErrors | `card_verification_server_errors_5m` > 0 twice in a row | critical | operations § Card-verification outage | TBD / TBD |
| CoreCardVerificationSlow | `card_verification_p95_ms_5m` > 500 for 10 minutes (proposal) | warning | operations § Card-verification outage | TBD / TBD |
| CoreAuditIntegrityFailed | `audit_last_verification_ok` = false, or `audit_heads_ship_failed` = true | critical (security) | operations § Audit integrity failure | TBD (security) / TBD |
| CoreStorageLow | `storage_free_percent` < 15 (warning) or < 10 (critical) | warning / critical | operations § Storage unavailable | TBD / TBD |
| CoreTlsExpiry | certificate expires within 21 days (the monitor's TLS check) | warning | network/SSL owner | TBD / TBD |

## Setting it up (for the monitoring owner)

1. Generate the token: `openssl rand -hex 32`. Store it in the secret store as
   `CORE_MONITOR_TOKEN`, and in the monitor's credential store. `core:config-audit`
   warns while it is missing or shorter than 32 characters.
2. Allow the monitor's addresses to reach `/health/*`. They are public probes
   without personal data; `/health/monitor` additionally needs the token.
3. Create the checks and alerts above, with their owner and channel.
4. Prove each critical alert fires: stop a worker (CoreQueueBacklog), stop
   cron (CoreSchedulerStopped), point the status file at a failure
   (CoreBackupFailed), stop PostgreSQL on staging (CoreUnavailable). Record
   each alert's arrival in the evidence document.
