# Load test results: partner / integration API at 3M employees

Production Gate 2, 2026-10-20.

## Setup

| | |
| --- | --- |
| Dataset | `aaemployeedb_scale3m`: 3,000,004 employees, 3.75M cards, 4M assignments, 3.6M service transactions ([scale-baseline.md](scale-baseline.md)) |
| App nodes | 16 × `php -S` (single-threaded PHP 8.4 built-in server, OPcache on, `validate_timestamps=0`, config/route/event caches), `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, change feed and webhooks enabled. Started by `loadtest/rig/nodes.sh start 16 8101`. |
| Load balancing | k6 picks a random node per request (no affinity). For the failure runs, `loadtest/rig/lb.mjs` (round-robin, health-checked on `/health/ready`, retries a refused GET once). |
| Database | PostgreSQL 18.6, stock settings, same machine |
| Load generator | k6 (`loadtest/k6/partner-api.js`) on the same machine; arrival-rate executor, so slow responses show as latency and dropped iterations, not as a lower offered rate |
| Clients | 20 city-wide + 4 organization-scoped synthetic API clients (`scale:export-api-loadtest-data`), real Sanctum tokens, real scopes, rate limits raised to 10,000/min per client for the test, and only for the test |
| Sample | 20,000 random employees (TABLESAMPLE across the whole table) with their cards |
| Machine | one Windows 11 workstation, 32 logical CPUs, 16 GB: **app, database and load generator share it** |

**Mixed workload per request:**

| Request type | Share |
| --- | ---: |
| card verify (`POST /cards/verify`) | 30 % |
| employee by UUID | 25 % |
| service eligibility | 15 % |
| current assignment | 10 % |
| employee by number | 8 % |
| organization units | 5 % |
| change-feed poll | 5 % |
| service transaction (write, with `Idempotency-Key`) | 2 % |

One request in ten comes from an organization-scoped client. Those clients sometimes ask for
employees outside their bound and get `404`, which is **correct authorization behaviour**. It
is counted separately and accounts for the whole of the ~5 % "non-2xx" column.

**Card scan** (`MIX=scan`): verify, then record the meal. That is two requests, with the
second a write transaction.

**Thresholds** are *engineering targets pending an approved SLA*
(**NEEDS_POLICY_DECISION_SLA**):

- unexpected errors (5xx, timeouts) < 1 %;
- read p95 < 500 ms, p99 < 1 s;
- write p95 < 800 ms.

## Results

| Run | Requests | Req/s achieved | p50 ms | p95 ms | p99 ms | max ms | Unexpected (5xx/timeout) | 404 (scope, expected) | 401/403 | 429 | Dropped iterations | Target met |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| NORMAL 20/s, 3 min | 3,600 | 20.0 | 83 | 198 | 231 | 325 | 0.00 % | 197 | 0 | 0 | 0 | yes |
| BUSY 50/s, 3 min | 9,001 | 49.9 | 98 | 237 | 351 | 759 | 0.00 % | 504 | 0 | 0 | 0 | yes |
| 80/s, 1 min | 4,801 | 79.5 | 162 | 443 | 626 | 936 | 0.00 % | 276 | 0 | 0 | 0 | yes (p95 near limit) |
| PEAK 100/s, 3 min | 11,421 | **54.4** | 2,310 | 12,891 | 27,907 | 51,176 | 0.00 % | 596 | 0 | 0 | 6,534 | **no: saturated** |
| 150/s, 2 min | 5,143 | 40.5 | 5,518 | 21,780 | 27,832 | 31,125 | 0.00 % | 264 | 0 | 0 | 12,858 | no |
| 200/s, 2 min | 3,637 | 28.3 | 9,709 | 34,864 | 40,126 | 46,349 | 0.00 % | 186 | 0 | 0 | 20,363 | no |
| **SOAK 40/s, 15 min** | 36,000 | 40.0 | 93 | 215 | 293 | 557 | 0.04 % (14) | 1,945 | 0 | 0 | 0 | yes |
| CARD-SCAN BURST, ramp 5 → 20 scans/s, hold 2 min | 7,198 | 26.7 | 113 | 256 | 350 | 575 | 0.00 % | 0 | 0 | 0 | 0 | yes (scan flow p95 440 ms) |
| CARD-SCAN BURST, ramp 5 → 40 scans/s, hold 2 min | 11,956 | **44.2** | 1,004 | 4,472 | 9,155 | 14,350 | 0.00 % | 0 | 0 | 0 | 622 | **no: saturated** (scan flow p95 8.5 s) |
| Change feed alone, 30/s, 1 min | 1,800 | 30.0 | 74 | 184 | 223 | 334 | 0.00 % | 0 | 0 | 0 | 0 | yes (~3,000 events/s served) |
| DEGRADED: 4 of 16 nodes killed, 40/s via LB | 4,800 | 40.0 | 87 | 198 | 221 | 317 | 0.02 % (1) | 255 | 0 | 0 | 0 | yes, see [failure-testing.md](failure-testing.md) |
| DEGRADED: database unavailable 30 s, 20/s via LB | 2,000 | 20.0 | 76 | 197 | 237 | 545 | 31.95 % (= the 31 s window) | 77 | 0 | 0 | 0 | expected: see [failure-testing.md](failure-testing.md) |

Per endpoint, soak at 40/s (p50 / p95 / p99 ms):

| Endpoint | p50 | p95 | p99 |
| --- | ---: | ---: | ---: |
| employee by UUID | 79 | 202 | 284 |
| employee by number | 79 | 201 | 287 |
| current assignment | 79 | 203 | 274 |
| service eligibility | 85 | 211 | 285 |
| organization units | 73 | 191 | 279 |
| card verify | 101 | 226 | 313 |
| change feed (100 events) | 72 | 203 | 272 |
| service transaction (write) | 117 | 235 | 327 |

**Error classes.**

- No 401, 403 or 429 in any run.
- Every 404 was an organization-scoped client asking outside its bound, which is correct.
- The only unexpected results were:
  - **soak:** 14 of 36,000 requests (0.04 %) with no matching application error in the log;
    transport resets of the single-threaded `php -S` rig;
  - **node-kill run:** one `POST` in flight on a killed node (502);
  - **database-outage run:** the outage window itself.
- **No application exception was logged during any measured run.**
- **No deadlock, no lock wait, no idle-in-transaction build-up** in any sample.

## Saturation: what limits throughput

| Signal at saturation (PEAK 100/s) | Value |
| --- | --- |
| PostgreSQL connections | max 16, which is every app node inside a request at once |
| PostgreSQL waiting locks / deadlocks | 0 / 0 |
| Slowest query observed | 0.31 s; sub-millisecond medians, see [database-capacity.md](database-capacity.md) |
| Machine CPU at 80/s (just below the knee) | 44 % average, 80 % max (Windows `% Processor Time`) |
| PHP node memory | 33 MB per node before and after the 15-minute soak (no growth) |

- **The first bottleneck is PHP request handling,** not PostgreSQL. Sixteen single-threaded
  nodes knee between 80 and 100 requests/s: about **5–6 requests/s per PHP worker** at the
  measured 80–160 ms per request. The database stayed under 10 active backends.
- **A card scan is heavier:** about 2.2 scans/s per worker, from the writes to
  `card_verifications`, `audit_logs`, `service_transactions`, the entitlement and card row locks,
  and the idempotency row.

### Per-request database cost

One `GET /api/v1/employees/{uuid}` issues **22 SQL statements**
(`loadtest/profile/query-log-router.php`):

- **Partner gate:** token lookup, client lookup, endpoint-permission join, request log insert,
  two `last_used_at` updates.
- **Rate limiting:** about 12 small `cache` table statements for two limiters, because
  `CACHE_STORE=database`.
- **The data itself:** 3 statements.

A card verify issues 32. With PDO's server-side prepares this measured about 106 PostgreSQL
transactions per request.

- **Turning on emulated prepares broke boolean bindings** (`api_request_logs.success`), so it is
  **not** a safe switch today and was reverted.
- **Moving the cache and rate limiter to Redis** would remove about half the statements per
  request. It is the first optimisation to make if database round trips start to dominate on
  separate hardware ([redis-readiness.md](redis-readiness.md)).

## Recovery

- **Scan burst (40/s):** k6 does not queue missed arrivals, so recovery equals the PHP socket
  backlog draining. Per-second latency was not exported for this run, so the exact recovery
  time is **not measured**. The next run, at 20/s right after it, started at normal latency.
  Queue backlog: none, because scans are synchronous.
- **Database outage:** the first successful second came within 2 s of the database coming
  back. That is the LB probe interval.
- **Node loss:** traffic moved to the 12 remaining nodes within one probe interval; latency was
  unchanged.

## Soak (15 minutes, 40 requests/s, 36,000 requests)

| | first quarter | last quarter |
| --- | ---: | ---: |
| PostgreSQL connections (avg / max) | 2.1 / 5 | 2.2 / 5 |
| PostgreSQL commits/s | 4,730 | 4,476 |
| PHP memory per node | 33.2 MB (before) | 32.6 MB (after) |

No connection leak, no memory growth and no latency drift were seen. The `cache` table holds
rate-limit rows that expire, so it does not grow. `api_request_logs` and `card_verifications`
grow with traffic by design (retention: kept until approved, `api:prune-logs`, ADR 0004).

## Integrity after load

`loadtest/db/integrity-checks.sql` ran after all load, race, outbox and failure tests. All 14
checks returned **0**:

- duplicate current assignments
- orphan assignments (employee, organization, position)
- employee pointer not current
- position with two incumbents
- two live current cards
- duplicate card UUIDs
- duplicate employee numbers
- duplicate idempotency claims
- duplicate provider references
- duplicate aggregate sequences
- published events without a feed position
- gaps in the change-feed positions (132,057 events)

## What this does and does not show

- **Shows:** at 3M employees the data layer is not the limit. Every partner lookup is a
  sub-millisecond index probe. Throughput scales with PHP workers: the 2026-10-11 run measured
  linear scaling from 8 to 24 workers.
- **Does not show:** production capacity.
  - `php -S` is not PHP-FPM.
  - The database shared the machine with the app and the load generator.
  - All clients came from one IP.
  - There was no TLS, no network latency and no PgBouncer.
  - A staging run on production-class, separate hosts is still required. Method:
    `loadtest/rig/run.sh` and [capacity-model.md](capacity-model.md).
