# Production Gate 2: capacity and resilience

2026-10-20. Scope: can Addis One / AA-IEMSS safely serve 3,000,000 employees
with high daily activity and integration traffic? Every number below is
**measured** on the isolated synthetic benchmark (`aaemployeedb_scale3m`,
3,000,004 employees, 13 GB) unless it is marked as an assumption or an
extrapolation. No production system was touched. No destructive command
(`migrate:fresh`, `db:wipe`, `truncate`, `drop`) ran against anything but the
gate's own throw-away restore copy.

## Verdicts

| Question | Verdict |
| --- | --- |
| **3,000,000-employee scale** | **PARTIAL** ([3m-employee-readiness.md](3m-employee-readiness.md)) |
| **Production Gate 2** | **CONDITIONAL_PRODUCTION_GATE_2_READY** |

**Conditional means the application is ready; the production environment is not.**

The code, schema and queries hold 3M employees and keep their invariants under concurrency.
No P0 remains in the code. What blocks go-live is infrastructure and policy, listed below as
conditions:

- PostgreSQL in production with PITR and a verified restore;
- a standby;
- shared object storage;
- the proxy, pool and timeout settings;
- a staging load test on the real topology;
- approved SLA, RPO/RTO and retention.

**Go-live must not happen before conditions C1–C6 are met.** If any of them cannot be met, the
verdict is PRODUCTION_GATE_2_NOT_READY.

## Gate conditions (blocking for go-live)

| # | Condition | Why | Owner |
| --- | --- | --- | --- |
| C1 | Production runs PostgreSQL (D-5), WAL archived to 2 repositories, `backup:health` HEALTHY, and an isolated **PITR restore from each repository** recorded with its duration | No production backup has ever been restored. The live Plesk host is MySQL/MariaDB with no backup design ([backup-pitr-restore.md](backup-pitr-restore.md)). | infrastructure |
| C2 | Hot standby with a documented, tested failover | PostgreSQL holds data, sessions, cache, locks and queues: the single point of failure ([deployment-topology.md](deployment-topology.md)) | infrastructure |
| C3 | Shared object storage for private and public files (`STORAGE_PRIVATE_DRIVER` / `STORAGE_PUBLIC_DRIVER=s3`) before a second web node | Otherwise photos, evidence and exports exist on one node only. That becomes a P0 in a multi-node deployment. | infrastructure |
| C4 | `APP_TRUSTED_PROXIES` (LB only, never `*`), Host validation, `APP_MAINTENANCE_DRIVER=cache`, central logs, `DB_SSLMODE=require`, PgBouncer once workers exceed about 80, per-role `statement_timeout` / `lock_timeout` / `idle_in_transaction_session_timeout`, an `integrations` worker pool | [load-balancer-readiness.md](load-balancer-readiness.md), [database-capacity.md](database-capacity.md), [queue-readiness.md](queue-readiness.md) | infrastructure |
| C5 | Staging load test on production-class, separate hosts (PHP-FPM, dedicated PostgreSQL, PgBouncer, TLS, many client IPs) with the 3M dataset, re-running `loadtest/k6/partner-api.js` and `loadtest/rig/run.sh` | Throughput was measured only on a shared workstation with `php -S` ([load-test-results.md](load-test-results.md)) | engineering + infrastructure |
| C6 | Approved SLA (latency, availability), RPO/RTO, retention for audit, transaction and API logs, and a usage forecast | Every threshold here is an engineering placeholder ([capacity-model.md](capacity-model.md)) | business owner |

## Handoff (items 1–56)

1. **Environment inspected:**
   - Windows 11 workstation, 32 logical CPUs, 16 GB RAM;
   - PostgreSQL 18.6 (stock); PHP 8.4.25; Laravel 12.69.2; k6;
   - no Redis, no PgBouncer, no pgBackRest;
   - config, deployment, scheduler, queue, health and backup code reviewed.
2. **Current employee count:**
   - development: 27;
   - previous benchmark: 220,000 (confirmed);
   - this benchmark: **3,000,004**.
3. **Maximum safe dataset tested:** 3,000,004 employees, 3.75M cards, 4.0M assignments,
   3.6M service transactions, 6.0M audit rows. 13 GB.
4. **Synthetic 3M dataset:** built in 2 h 9 min with `scale:generate`. Now realistic:
   - varied names, in English and Ge'ez;
   - suspended staff, closed assignments, replaced cards, vacant posts, entitlements.
   - Guarded: it refuses production, and refuses databases holding real employees.
5. **PostgreSQL configuration:** 18.6, stock settings, `max_connections` 100, no timeouts, no
   `pg_stat_statements`, `archive_mode` off, no replicas ([scale-baseline.md](scale-baseline.md)).
6. **Largest tables:**

   | Table | Size |
   | --- | ---: |
   | employees | 2.6 GB |
   | id_cards | 2.1 GB |
   | audit_logs | 1.8 GB |
   | cafeteria_transactions | 1.6 GB |
   | daily_activity_logs | 1.2 GB |
   | employee_assignments | 1.2 GB |
   | positions | 1.1 GB |

7. **Index findings:**
   - 4 indexes were missing on hot paths:
     - `entitlements(employee_id, service_type_id)`;
     - `service_transactions(employee_id, occurred_at)`;
     - `card_verifications(id_card_id)`;
     - the outbox pending partial index.
   - 2 case-insensitive code indexes and 1 e-mail trigram index were added for search.
   - Total added: +558 MB (+4 %).
   - Low-value and duplicate indexes are listed for review.
8. **Hot-query EXPLAIN:** all canonical lookups are index probes taking 0.1–0.7 ms. Before → after
   ([database-capacity.md](database-capacity.md)):

   | Query | Before | After |
   | --- | ---: | ---: |
   | entitlement check | 439 ms | 0.4 ms |
   | API code filter | 729 ms | 0.3 ms |
   | registry search | 2,652 ms | 33 ms |
   | number search | 1,534 ms | 0.6 ms |
   | employee transaction history | 782 ms | 0.3 ms |
   | outbox claim (100k pending) | 65 ms | 0.4 ms |

9. **Lookup performance at 3M:**

   | Lookup | Time |
   | --- | ---: |
   | employee by UUID | 0.27 ms |
   | employee by number | 0.27 ms |
   | card by QR UUID | 0.4–0.6 ms |
   | current assignment | 0.26 ms |
   | position occupancy | 0.24 ms |

10. **Pagination:** page 1 takes 0.09 ms. OFFSET 1.5M takes 3.5–4.7 s, so the web registry is
    capped at page 200 with a notice. Keyset anywhere takes 0.24 ms. The partner list API
    should get a keyset cursor (P1).
11. **Search:**

    | Search | Time |
    | --- | ---: |
    | Amharic name | 19 ms |
    | English name | 33 ms |
    | employee number (prefix) | 0.6 ms |
    | e-mail | 50 ms |
    | single common given name | 1.2 s (P2) |

12. **DB connection budget:** about one connection per busy PHP worker. Measured at most 16 (all
    nodes busy at once) and under 10 active. Formula and examples in
    [database-capacity.md](database-capacity.md#connection-budget).
13. **PgBouncer:** not installed. **Recommended** in transaction mode once workers plus queue
    workers approach `max_connections` (≈ the EXPECTED scenario). Migrations connect directly.
    Emulated prepares are **not** safe today, because they break boolean bindings.
14. **Load-balancer readiness:**
    - **Pass.** No affinity needed. The node-kill test lost 1 in-flight POST out of 4,800
      requests.
    - Needs proxies, Host validation, maintenance driver and log shipping configured (C4).
15. **Sessions:** `database` driver, shared across nodes, MFA state in the session. Pass. Redis
    is recommended at scale.
16. **Cache:** `database` store, shared. Rate limits and locks are distributed. Keys are scoped
    by organization or permission fingerprint. Revocation is never cached.
17. **File storage:** node-local by default. S3 switches exist. One code path fixed for object
    storage (design imports); thumbnails degrade on S3 (P2). Condition C3.
18. **Redis architecture:** not used by the shipped configuration ([redis-readiness.md](redis-readiness.md)).
19. **Redis failure result:** N/A, **not measured**: no Redis on any path or on this machine.
20. **Queue architecture:**
    - Queues: `default`, `notifications`, `long`, `integrations`.
    - `retry_after` 960 s, above every job timeout.
    - Failed jobs are stored and retryable.
21. **Queue isolation:**
    - The `integrations` worker was missing from every documented worker list; now documented.
    - Exports and imports share `default`; a separate pool is P2.
22. **Worker sizing:** about 5–6 API req/s, about 2.2 scans/s and about 4 pages/s per PHP worker
    (floor). 33–72 MB per worker. FPM sizing method in [capacity-model.md](capacity-model.md).
23. **Scheduler safety:**
    - All 23 tasks run `onOneServer` plus `withoutOverlapping`.
    - The 10-second integration tasks' 24-hour stale lock is fixed (5 min).
24. **Outbox throughput:**
    - Publishing: about **480 events/s**, single-writer by design. 4 publishers: 469/s, with no
      duplicates and no gaps.
    - Write overhead: +0.9 ms per business write.
25. **Change-feed throughput:** about **3,000 events/s** served at 30 req/s; p95 184 ms.
26. **Webhook throughput:**
    - **104/s** with 1 dispatcher, **175/s** with 4.
    - Queued mode is capped at 5/s per subscriber (P1 for the HIGH scenario).
27. **API load profiles:** NORMAL 20/s, BUSY 50/s, 80/s, PEAK 100/s, steps to 200/s,
    card-scan BURST to 20 and 40 scans/s, 15-minute SOAK at 40/s, plus DEGRADED (node loss,
    database outage).
28. **RPS / concurrency tested:**
    - up to 200 offered and 80 sustained req/s on 16 PHP workers;
    - up to 2,000 virtual users at saturation.
29. **p50 / p95 / p99:**

    | Run | p50 | p95 | p99 |
    | --- | ---: | ---: | ---: |
    | NORMAL | 83 ms | 198 ms | 231 ms |
    | BUSY | 98 ms | 237 ms | 351 ms |
    | 80/s | 162 ms | 443 ms | 626 ms |
    | SOAK | 93 ms | 215 ms | 293 ms |
    | PEAK (saturated) | 2.3 s | 12.9 s | 27.9 s |

30. **Error rates:**
    - 0 % unexpected in every run below the knee; 0.04 % in the soak (rig transport resets,
      no application error).
    - 0 × 401/403/429. All 404s were scoped-client authorization, which is correct.
31. **First saturation point:** PHP workers at 80–100 req/s on 16 single-threaded workers. The
    database stayed under 10 active backends, with 0 lock waits.
32. **Soak:** 15 min, 36,000 requests. No memory growth (33 MB per node before and after), no
    connection growth, no latency drift.
33. **Concurrent assignment:** exactly one current assignment in every round. The DB partial
    unique index holds.
34. **Transfer races:**
    - duplicate implementation → 1 transfer;
    - two transfers of one employee → 1 succeeds;
    - two people into one slot → 1 succeeds.
    - 0 violations, 0 deadlocks.
35. **Idempotency race:** 8 identical keyed requests → 1 transaction, 7 × 409. A cross-service
    key replay bug was fixed.
36. **Card revoke race:** **P0 reproduced** (1 of 5 rounds accepted a revoked card). **Fixed** by
    taking the card row lock on both sides. 0 violations in 25 rounds after the fix.
37. **Deadlocks and locks:** 0 deadlocks in every race and load run. Lock waits only where
    designed. No HTTP inside a transaction. No timeouts configured (C4).
38. **Situation Room aggregates:** 4.4–7.7 s per city-wide aggregate on a cold cache. Now
    stale-while-revalidate with a single refresh; a summary table is the next step (P2).
    Dashboard cold refresh: 105 s → 57 s, served stale (fresh 5 min, stale ≤ 30 min).
39. **Export performance:** capped (20k rows) or queued. In-memory arrays within caps (P2).
    Request memory 41–72 MB.
40. **Backup status:** designed (pgBackRest, two repositories); **not deployed**.
41. **PITR:** **not configured** (`archive_mode` off). NOT_READY for DR.
42. **Restore drill:**
    - logical dump/restore of the 3M benchmark: dump 26 s, restore 9 min 50 s, analyze 32 s;
    - counts and checksums identical; integrity 0;
    - PITR and production backups not drilled.
43. **Web-node failure:** pass. 4 of 16 killed, 1 of 4,800 requests lost (an in-flight POST).
44. **Queue-worker failure:** pass when killed between jobs; 0 lost, 0 duplicates. Kill
    mid-job: design-verified only.
45. **Partner outage:** pass. Core writes 100 %, events kept, deliveries retried, 0 loss, 0
    duplicates after recovery.
46. **Observability readiness:**
    - Health endpoints pass, with two fixes (queue COUNT, storage probe).
    - Request IDs reach events and API errors, but not log context or webhook headers (P2).
    - No metrics backend.
    - System Health does not compare signals with thresholds (P2).
    - No `pg_stat_statements` or slow-query log (C4).
47. **Deployment topology:** LB → N stateless FPM nodes → PgBouncer → PostgreSQL primary +
    standby. Object storage; separate worker pools; one active scheduler. Redis optional
    ([deployment-topology.md](deployment-topology.md)).
48. **Capacity model:**

    | Scenario | PHP workers | Notes |
    | --- | ---: | --- |
    | LOW | ≈ 35 | |
    | EXPECTED | ≈ 130 | PgBouncer needed |
    | HIGH | ≈ 410 | assumptions labelled |

49. **Storage growth (extrapolated):** about 250 GB/yr LOW, about 1 TB/yr EXPECTED, about
    3.7 TB/yr HIGH. Dominated by API and audit logs.
50. **P0 remaining:** **none in code.** Found and fixed: revoked card accepted under
    concurrency. Becomes P0 if deployed wrongly: node-local storage on more than one node (C3).
51. **P1 remaining:**
    - no verified production backup/PITR restore, and production engine unresolved (C1);
    - no HA (C2);
    - throughput unmeasured on production topology (C5);
    - partner list API offset paging (keyset cursor needed before any full-sync partner);
    - webhook queued delivery capped at 5/s per subscriber (HIGH scenario);
    - no statement/lock timeouts (C4);
    - `2026_10_22_100000` fails on MySQL/MariaDB: foreign-key name of 67 characters. Matters
      only while the MySQL host exists.
52. **P2 remaining:**
    - dashboard and Situation Room city aggregates (summary tables);
    - common-name search COUNT;
    - organization employee COUNT;
    - low-value indexes;
    - in-memory exports;
    - exports on `default`;
    - S3 thumbnails;
    - System Health thresholds;
    - correlation ID in logs and webhook headers;
    - per-table autovacuum;
    - repository-wide `pint --test` fails on 133 pre-existing files (none introduced here; two of them, `DeliverAnnouncement.php` and `EmployeeIndexTest.php`, were touched in this gate).
53. **NEEDS_DEPLOYMENT_CONFIGURATION:**
    - object storage;
    - trusted proxies and Host validation;
    - maintenance driver;
    - central logging;
    - `DB_SSLMODE`;
    - PgBouncer;
    - **POSTGRES_HA**;
    - REDIS_HA (if Redis is adopted);
    - per-role timeouts;
    - pgBackRest + WAL archiving;
    - `pg_stat_statements` and the slow-query log;
    - autovacuum per table;
    - OPcache and PHP-FPM sizing;
    - nginx gzip and cache headers;
    - `integrations` worker;
    - health-path network restriction;
    - per-environment `CACHE_PREFIX` / `REDIS_PREFIX`.
54. **NEEDS_POLICY_DECISION:**
    - SLA (latency, availability);
    - RPO_RTO;
    - RETENTION (audit, transactions, API logs);
    - usage forecast (scans, partner calls, concurrent users);
    - confirmation that production moves to PostgreSQL (D-5) versus the live MySQL host.
55. **Scale verdict:** **PARTIAL**.
56. **Production gate verdict:** **CONDITIONAL_PRODUCTION_GATE_2_READY**, conditions C1–C6.

## Changes made in this gate

| Area | Change | Before → after | Risk |
| --- | --- | --- | --- |
| Correctness (P0) | `RecordServiceTransactionAction` locks and re-checks the card in its transaction; `RevokeCardAction` locks the card | revoked card accepted in 1 of 5 rounds → 0 in 25 | one row lock per scan |
| Idempotency | concrete path in the request hash | key replayed across services → 409 reused | stored keys from before the deploy no longer replay |
| API | code filters are case-insensitive equality via `lower()`, so wildcards are literal; malformed card token gives 404, not 500 | 729 ms → 0.3 ms | exact match only (as documented) |
| Registry | shape-aware search; page cap 200 with notice | 2.7 s → 33 ms; deep page 3.5 s → not reachable | names no longer match e-mail text |
| Indexes | migration `2026_10_24_100000` (concurrent, idempotent, MySQL-safe, rollback-safe) | see item 7 | +558 MB |
| Dashboard | no needless 3M joins for city scope; occupancy memoised; `Cache::flexible` | 105 s → 57 s cold; served stale otherwise | figures up to 30 min old (timestamp shown) |
| Situation Room | `SituationCache` stale-while-revalidate + compute lock | herd → one refresh | stale within 5 × TTL |
| Organizations | deletion guard uses EXISTS probes | ~250k rows per page → 25 probes | none |
| Announcements | delivery checkpoint per chunk | retries re-notified everyone → resume | depends on the shared cache |
| Imports | object-storage-safe reader | broke on S3 → streams to a temp copy | none |
| Health | queue probe without COUNT; storage probe checks a writable root | | |
| Scheduler | `withoutOverlapping(5)` on 10-second integration tasks | 24-hour stall → 5 min | overlap is safe |
| Docs and runbooks | `integrations` worker; full schedule; env prefixes | | |
| Tooling | `scale:generate` (guarded, realistic, entitlements), `scale:export-api-loadtest-data`, `loadtest/k6/partner-api.js`, `loadtest/rig/*`, `loadtest/db/*`, `loadtest/concurrency/race.php`, `loadtest/integration/outbox-bench.php`, `loadtest/profile/*` | reproducible | benchmark databases only |

## Verification

| Command | Result |
| --- | --- |
| `php artisan about` | Laravel 12.69.2, PHP 8.4.25, environment local |
| `php artisan route:list` | 1,548 routes |
| `php artisan migrate:status` / `migrate --pretend` (development DB) | 1 pending: `2026_10_24_100000_add_service_path_lookup_indexes`; pretend shows only `CREATE INDEX CONCURRENTLY IF NOT EXISTS` statements |
| Migration on the 3M benchmark (PostgreSQL) | DONE in 2 min 2 s, concurrent, no write lock |
| Migration on MariaDB 10.4 (`euisis_mysql_check`) | up, down and up again: DONE (down tolerates InnoDB adopting the index for a foreign key) |
| Full MariaDB migrate of that scratch DB | **fails at the pre-existing `2026_10_22_100000`** (foreign-key name of 67 characters, MySQL limit 64), which is outside this gate's changes (P1 for the MySQL host only) |
| `php artisan test --parallel --processes=6` (excluding the known-hanging `PerformanceWorkflowTest`) | **4,329 passed, 5 failed, 1 skipped** (318 s). 4 × `RichTextArchitectureTest`: Windows path separators in a static source scan (pre-existing, environmental). 1 × `DesignImportTest` "Excel workbook…": 527 vs 528 rows **only under full-suite parallel load**; it passed 3 runs alone and 2 parallel runs of its folder (intermittent; the local-disk read path is unchanged) |
| New and changed tests | health probes, idempotency across paths, LIKE wildcards on API codes, registry search shapes and page cap, announcement resume, dashboard stale-while-revalidate, object-storage import reader: all pass |
| `vendor/bin/pint --test` | fails on 133 pre-existing files; every file created in this gate passes |
| `composer audit --locked` | no advisories |
| `npm audit` | 0 vulnerabilities |
| `npm run typecheck` | pass |
| `npm run i18n:audit` (strict) | pass (warnings only: 160 Latin words in Amharic, 1 deprecated term; none from this gate) |
| `npm run build` | pass (assets rebuilt, so `:8000` serves the registry change) |
| Load, race, outbox, failure, restore scripts | results above; integrity checks 0 on the benchmark and on the restored copy |

Reproduce on any benchmark database:

```bash
createdb aaemployeedb_scale3m && DB_DATABASE=aaemployeedb_scale3m php artisan migrate --force
DB_DATABASE=aaemployeedb_scale3m php artisan scale:generate --employees=3000000 --organizations=300 --units-per-org=40 --history-days=2 --login-users=300
psql -d aaemployeedb_scale3m -c 'VACUUM ANALYZE'
DB_DATABASE=aaemployeedb_scale3m php loadtest/db/explain-hot-paths.php 5
DB_DATABASE=aaemployeedb_scale3m php artisan scale:export-api-loadtest-data /secure/api-data.json
DB_DATABASE=aaemployeedb_scale3m loadtest/rig/nodes.sh start 16 8101
DB_DATABASE=aaemployeedb_scale3m DATA=/secure/api-data.json OUT=out loadtest/rig/run.sh normal normal mixed 190
DB_DATABASE=aaemployeedb_scale3m php loadtest/concurrency/race.php run revoke 12 20
psql -d aaemployeedb_scale3m -f loadtest/db/integrity-checks.sql
loadtest/db/restore-drill.sh aaemployeedb_scale3m aaemployeedb_restore_drill /tmp/dump 8
```

Clean-up: `dropdb aaemployeedb_scale3m`. It is a synthetic, isolated database; never run any
of these against production.
