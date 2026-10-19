# Addis One Intelligence — system health and diagnostics

The technical domain of Addis One Intelligence (`docs/intelligence-architecture.md`):
what the platform measures about itself, how incidents are opened from those
measurements, and what a technical user sees. Nothing here reads an HR figure,
a person or a log line; nothing here is estimated.

## Telemetry

| Signal | How it is collected | Component / dimension |
| --- | --- | --- |
| `request.total/s2xx/s3xx/s4xx/s5xx/s401/s403/s429/slow`, `request.latency_avg_ms`, `request.latency_p95_ms`, `request.error_rate_percent` | `RecordRequestTelemetry` (global, terminable) counts each response per minute in the cache (`RequestCounters`); the collector flushes completed minutes into five-minute buckets. Failures and slow responses (≥ 1 s) are exact; on a database or file cache store totals and latency buckets are **sampled one request in twenty** and scaled, so a database-backed cache sees one write per twenty requests. p95 is the upper bound of the histogram bucket (100 / 300 / 1000 / 3000 ms) that reaches 95 %. | `application` / route group (`RouteGroups`: public_feedback, daily_activity, cafeteria, performance, field_work, transfers, employee_portal, auth, api, admin, other) |
| `db.ping_ms` | time of `select 1` | `database` |
| `db.connections_active/max/percent`, `db.long_running_queries`, `db.waiting_locks`, `db.deadlocks_total` | `pg_stat_activity`, `show max_connections`, `pg_locks`, `pg_stat_database` on PostgreSQL; `SHOW STATUS/VARIABLES`, `information_schema.processlist` on MySQL/MariaDB; nothing on SQLite | `database` |
| `cache.available`, `cache.ping_ms` | put/get/forget of a probe key | `cache` |
| `redis.available`, `redis.ping_ms` | `PING`, only when cache, queue, session or broadcast use Redis | `redis` |
| `queue.pending`, `queue.reserved`, `queue.oldest_age_seconds` | `jobs` table per queue (or `Queue::size()` on Redis) | `queue` / queue name |
| `queue.failed_5m`, `queue.failed_total` | `failed_jobs` | `queue` |
| `scheduler.heartbeat` | the collector itself ran | `scheduler` |
| `storage.writable`, `storage.free_percent` | write/delete a probe file on the local disk, `disk_free_space` | `storage` |
| `backup.health` (0 healthy, 1 degraded, 2 unhealthy, 3 unknown), `backup.age_hours` | `BackupStatusService::health()` when backups are enabled | `backups` |
| `integration.calls/failures` | `external_service_usages` per provider (SMS and other budgeted services) | `integrations` / provider |
| `integration.api_requests/api_failures` | `api_request_logs` | `integrations` |
| `integration.broadcast_failures_total` | `CommunicationBroadcaster` counter | `integrations` |
| `auth.mfa_failures` | `audit_logs` `mfa.challenge_failed` | `security` |
| `sessions.active` | `sessions` table (database driver), active in the last 15 min | `application` |

Not collected, therefore UNKNOWN on the pages: e-mail delivery outcomes
(nothing records them), login failures (not audited — 401/403 come from the
request counters), anything Redis on an installation without Redis, database
statistics on SQLite.

Schedule: `intelligence:collect --detect` every five minutes
(`withoutOverlapping`, `onOneServer`); `intelligence:prune` daily. Switch:
System Settings › Intelligence › Platform Telemetry.

## Error fingerprints

`ErrorFingerprinter` is called from `ErrorLoggingService::log()` for every
unhandled exception. Fingerprint = sha1(exception class | normalised message |
route group | first application frame file:line). The message normaliser
replaces UUIDs, e-mail-like tokens, paths, quoted values and numbers. Stored:
class, normalised message, route name and group, the first application area
(`Services/Intelligence`), occurrences, first/last seen, the last error id (to
find the full context in the log) and a sample context of relative file, line
and the top five frame class names. **Never** arguments, input, headers, SQL
parameters or a person.

## Detection rules

Each rule reads signals through `DetectionWindow`, compares with a
**configured limit** (System Settings › Intelligence, null = off) and/or the
**robust baseline** of the same series over the baseline window (median and
MAD, `Baseline`), and needs a minimum count so small numbers never alarm.
Severity: `critical` at twice the limit, `high` at the limit, `medium` for a
baseline-only deviation. A rule that throws is skipped and its incidents are
left alone.

| Rule | Reads | Fires when | Minimum |
| --- | --- | --- | --- |
| http_error_rate | 5xx share per route group, 15 min | ≥ limit `http_5xx_rate_percent` or ≥ sigma above baseline | 20 requests, 5 failures, 2 % |
| http_latency | latest p95 per group | ≥ limit `http_p95_latency_ms` or baseline | 300 ms |
| queue_backlog | pending per queue; oldest age | ≥ limit `queue_backlog_jobs` or baseline; age ≥ `queue_oldest_job_minutes` (high; ×3 critical) | 50 pending |
| failed_jobs | failed per hour / per 5 min | ≥ limit `failed_jobs_per_hour`; 5-min count above baseline | 3 |
| error_repeat | fingerprints seen in 15 min | occurrences ≥ limit `exception_repeat_count` (high; ×2 critical); without a limit, ≥ 10 within an hour of first sighting (medium) | — |
| db_latency | probe time; long-running queries | ≥ limit `db_latency_ms` or baseline; long queries above baseline | 50 ms / 3 |
| db_connections | connections used % | ≥ 90 % high, ≥ 97 % critical (pool facts, not objectives) | — |
| cache_unavailable | cache probe | not available → critical; probe time ≥ limit `cache_latency_ms` or baseline → high/medium | 20 ms |
| redis_unavailable | Redis probe where configured | not available → critical | — |
| scheduler_missed | last heartbeat | ≥ 15 min high, ≥ 60 min critical | — |
| storage | writable, free % | not writable critical; ≤ 10 % high; ≤ 5 % critical | — |
| backup | backup.health | degraded medium, unhealthy high | — |
| integration_failure | calls/failures per provider, 60 min | all calls failed → high; failures above baseline → medium | 5 calls |
| auth_failures | 401+403, 429, MFA failures, 15 min | 5-min total above baseline | 20 |

The handful of absolute numbers above (connection pool 90/97 %, disk 10/5 %,
scheduler 15/60 min, minimum counts) are properties of a pool, a disk or a
schedule, not service objectives; service objectives are
`SLO_CONFIGURATION_REQUIRED` and never invented.

## Incidents

`IncidentManager` keeps **one incident per fingerprint** (rule + dimension):
a repeat refreshes occurrences and evidence, a worse severity escalates (an
`escalated` event), a mitigated incident that fires again reopens
(`recurred`). A condition absent for three consecutive runs mitigates the
incident; mitigated and quiet for 24 hours resolves it with verification
pending; **people close incidents**, the engine never does. Every change is an
immutable `intelligence_incident_events` row. Statuses:
open → investigating → mitigated → resolved → closed. Severities: critical /
high / medium / low / info.

On opening, deterministic recommendations are created from
`RecommendationTemplates` (immediate / short-term / permanent / verification,
each a safe check, a potential fix or a high-risk change), the latest
deployment recorded within 60 minutes before detection is linked as a
correlation, and authorized users (`intelligence.incidents.view`) are notified
when the severity is at or above System Settings › Notify From Severity, once
per hour per incident. Escalation beyond that is
`NEEDS_POLICY_DECISION_ALERT_ESCALATION`.

Reference format: `INC-YYMM-NNNN`.

## Component health

`ComponentHealth` gives each component `healthy / degraded / unhealthy /
unknown` from its freshest sample (none within 15 minutes → unknown), raised
by any active incident on it (critical/high → unhealthy, medium/low →
degraded). Unknown is shown as unknown.

## Pages and grants

| Page | Grant | Shows |
| --- | --- | --- |
| System Health | intelligence.system-health.view | headline (incidents by severity, component states), component table, error-rate trend, failures by route group, incident insights |
| Active Incidents, incident detail | intelligence.incidents.view (+ .manage to transition) | list with counts and filters; summary, deployment correlation, evidence rows, related error signatures (sample context only with diagnostics), recommendations with review, runbook, timeline, manage form |
| Error & Failure Analysis, AI Diagnostics, Predictive Failure Monitor | intelligence.diagnostics.view | fingerprints, trend watch (TrendDetector: sustained rise with confidence from the number of points) |
| Application Performance | intelligence.application-health.view | requests, error rate, p95, slow responses, trends by route group |
| Database Health | intelligence.database-health.view | probe, connections, long queries, locks, deadlocks; UNKNOWN where the driver exposes nothing |
| Redis & Queue Health | intelligence.queue-health.view | pending/oldest/failed per queue, cache and Redis probes |
| Integration Health | intelligence.integration-health.view | per provider calls, failures, rate, last success; external API; e-mail NEEDS_DATA |
| Deployment Analysis | intelligence.deployment-analysis.view | recorded releases with incidents and new signatures within the hour after; NEEDS_DEPLOYMENT_CONFIGURATION until `intelligence:record-deployment` is called |
| Recommendations | intelligence.recommendations.view (+ .review) | every recorded recommendation with its verdict |
| Runbooks | intelligence.runbooks.view | read-only runbooks (`RunbookCatalog`); execution disabled (`intelligence.runbooks.execute` + setting, NEEDS_POLICY_DECISION_RUNBOOK_EXECUTION) |

Telemetry has no organization scope (the platform is one); the business
domain asks it only through `TechnicalSignalSource::failuresFor(routeGroup,
from, to)`, which answers `known = false` when the group was not sampled.

## Retention

`intelligence:prune` deletes samples, quiet fingerprints, closed incidents
and reviewed recommendations older than System Settings › Retention (default
180 days). Active incidents and pending reviews are never pruned.

## Deployment metadata

```
php artisan intelligence:record-deployment --release=v1.9.0 --commit=$(git rev-parse HEAD) --change=CHG-123
```

Run it from the release script after the symlink switch
(`docs/runbook/deployment-and-rollback.md`), or set `APP_RELEASE` /
`APP_COMMIT` and call it without options. Nothing is read from the filesystem.
