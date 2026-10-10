# Incident response (operational)

The full process — team, severity classes, phases, communication, evidence — is
the [incident response plan](incident-response-plan.md). This page lists the
alerts that should open an incident and the first response for each. Related:
[production runbook](production-runbook.md) · [load balancing](load-balancing.md).

## Alerts (to configure in the monitoring system)

| Alert | Suggested condition | First response |
| --- | --- | --- |
| High 5xx | > 1% of requests for 5 min | check `/health/ready`, recent deploy, error log by request id |
| Latency spike | p95 > 2× baseline for 10 min | `pg_stat_activity` (long queries, locks), queue depth, CPU |
| Node not ready | `/health/ready` 503 on any node | check which check failed in the node log |
| DB connection saturation | > 80% of PgBouncer server pool / `max_connections` | find long transactions; scale workers down if a job storm |
| Replication lag | standby lag > agreed threshold (NEEDS_DECISION) | check WAL shipping / network |
| Cache/queue store failure | Redis/DB store unreachable | restore; users re-login if sessions were lost |
| Queue backlog | oldest job in `default`/`notifications` > 10 min, `long` > 60 min | check workers are running; failed jobs |
| Failed jobs | `failed_jobs` grows | inspect exception; fix; `queue:retry` |
| Scheduler stopped | no `schedule:run` heartbeat for 5 min | restart cron on the scheduler host |
| Storage failure | object storage errors / readiness storage check | provider status; uploads blocked meanwhile |
| Backup failure | backup health alert (backup:health --monitor) | follow [backup troubleshooting](runbooks/backup-health-troubleshooting.md) |
| Security event spike | failed logins / MFA failures / throttles above baseline | [incident response plan](incident-response-plan.md) phase 1 |

Thresholds marked NEEDS_DECISION depend on the approved SLOs, which do not exist yet.

## Correlation

Every request carries a request id (`RequestCorrelationId` middleware) and audit
rows carry their own `request_id`; include it in tickets so logs, audit and
traces can be joined.

## Known open items affecting incidents

See [production-risk-register.md](production-risk-register.md): rotate the
credentials found in git history (SEC-11) — they must be treated as compromised
until rotated.
