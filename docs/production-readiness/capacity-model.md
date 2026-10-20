# Capacity model: 3,000,000 employees

Production Gate 2, 2026-10-20. Rates come from the measurements in
[load-test-results.md](load-test-results.md) and [database-capacity.md](database-capacity.md).
Every usage figure is an **assumption, labelled as one**: no approved usage
forecast, SLA or concurrency requirement exists (**NEEDS_POLICY_DECISION_SLA**,
**NEEDS_POLICY_DECISION_USAGE_FORECAST**).

## 3M employees is not 3M concurrent users

| Quantity | What drives it | Basis |
| --- | --- | --- |
| Employee population | 3,000,000 rows | given target; **measured** as a dataset |
| Concurrent web sessions | HR, ID-card and admin staff, plus employees using self-service | assumption: a few thousand at peak, not millions |
| API requests/s | partner systems (cafeteria, transport, payroll) | assumption, see scenarios |
| Card scans/s | meal and transport bursts | assumption, see scenarios |
| Master-data events/day | hires, transfers, card changes, reorganizations | assumption |

## Measured unit costs (this rig: shared workstation, single-threaded `php -S`)

| Unit | Measured | Source |
| --- | --- | --- |
| Partner API request (mixed) | **~5–6 req/s per PHP worker** below the knee; p50 80–100 ms, p95 ~200 ms | 16 workers knee between 80 and 100 req/s |
| Card scan (verify + record) | **~2.2 scans/s per PHP worker** | burst holds at 20 scans/s and saturates at 40 on 16 workers |
| Web page (logged in) | ~4 pages/s per PHP worker | 2026-10-11 run (24 workers, ~99 req/s) |
| PHP memory per request | API 41–42 MB peak; heavy web pages 69–72 MB peak (CLI, cold cache); `php -S` node working set 33 MB steady | `loadtest/profile/requests.php`; soak |
| DB time per API request | 3 data statements under 1 ms each; 22–32 statements in total including gate and rate limiter | query log |
| DB connections | ≈ busy PHP workers. Never above 16 here (all 16 nodes inside a request at once) | sampler |
| Outbox publish | ~480 events/s (single writer by design) | outbox bench |
| Webhook delivery | 104/s (1 dispatcher), 175/s (4); queued mode is capped at 50 per subscription per 10 s, i.e. 5/s per subscriber | outbox bench, worker test |
| Change feed | ~3,000 events/s served at 30 req/s, p95 184 ms | k6 |

`php -S` has no request concurrency per process and shared the CPU with PostgreSQL and k6.
On dedicated hosts PHP-FPM workers will do better per core. Treat these as **floor** rates
until the staging run replaces them.

## Scenarios (assumptions, not a forecast)

| Assumption | LOW | EXPECTED | HIGH |
| --- | ---: | ---: | ---: |
| Employees using the cafeteria per day | 10 % (300k) | 30 % (900k) | 60 % (1.8M) |
| Share of a day's scans in the busiest 30 min | 30 % | 30 % | 40 % |
| → **peak card scans/s** | **50** | **150** | **400** |
| Partner lookups per working day (10 h) | 1M | 5M | 20M |
| → peak lookup req/s (2× the 10-h average) | 56 | 280 | 1,100 |
| Concurrent admin/HR users (1 page per 30 s) | 200 → 7 pages/s | 1,000 → 33 pages/s | 3,000 → 100 pages/s |
| Master-data events/day | 5k | 30k | 300k (a reorganization) |
| Webhook subscribers | 2 | 5 | 10 |
| → webhook deliveries/day | 10k | 150k | 3M |

### PHP workers needed at measured rates (floor)

| | LOW | EXPECTED | HIGH |
| --- | ---: | ---: | ---: |
| scans (÷ 2.2/s) | 23 | 68 | 182 |
| lookups (÷ 5.5/s) | 10 | 51 | 200 |
| admin pages (÷ 4/s) | 2 | 8 | 25 |
| **total PHP workers (peak hour; bursts may coincide)** | **≈ 35** | **≈ 130** | **≈ 410** |
| app nodes at 32 workers each | 2 (HA minimum) | 4–5 | 13 |
| DB connections without a pool | 35 + workers | 130 + workers | 410 + workers |
| → **PgBouncer** | optional | **required** (above `max_connections` 100) | **required** |

**Database load at these rates**, extrapolated:

- The data statements are sub-millisecond index probes. At EXPECTED peak (~500 req/s
  combined) that is roughly 1,500 data statements/s plus 10,000 gate and rate-limit
  statements/s on the `database` cache store.
- The second number is the reason to put the cache and rate limiter on Redis before HIGH.
- **Not measured on server hardware.**

### Integration capacity

| | LOW | EXPECTED | HIGH |
| --- | ---: | ---: | ---: |
| Outbox publish time per day of events (480/s) | 10 s | 1 min | 10 min |
| Webhook delivery at 175/s (4 dispatchers) | 1 min | 14 min | 4.8 h |
| Queued mode, per subscriber (5/s cap) | 17 min | 1.7 h | **16.7 h**, a backlog |

The HIGH scenario needs a larger `INTEGRATION_WEBHOOK_PER_SUBSCRIPTION_BATCH`, more
`integrations` workers, or partners on the change feed (**P1 for HIGH, not for EXPECTED**).

## Storage growth (measured bytes per row × assumed volumes; extrapolation)

| Table | Bytes/row incl. indexes (measured) | Rows per scan / per lookup |
| --- | ---: | --- |
| service_transactions | 256 | 1 per scan |
| card_verifications | 426 | 1 per verify |
| audit_logs | 323 | 1–2 per scan, 1 per card lookup |
| api_request_logs | 366 | 1 per API request |
| integration_events | 1,102 | 1 per master-data change |
| webhook_deliveries | 567 | 1 per event × subscriber |
| employees / id_cards / assignments | 980 / 583 / 321 | master data: 3M rows ≈ 2.9 / 2.2 / 1.3 GB |

| Daily growth (250 working days/year) | LOW | EXPECTED | HIGH |
| --- | ---: | ---: | ---: |
| scans (≈ 2.1 KB each, all rows above) | 0.6 GB | 1.9 GB | 3.8 GB |
| partner lookups (log + audit ≈ 0.4–0.7 KB) | 0.4 GB | 2.2 GB | 9 GB |
| events + deliveries | 0.01 GB | 0.12 GB | 2 GB |
| **per year** | **≈ 250 GB** | **≈ 1 TB** | **≈ 3.7 TB** |

`api_request_logs` and `audit_logs` dominate. The current 90-day API-log retention caps the
first at about 50–200 GB. Audit retention and transaction archiving need a policy
(**NEEDS_POLICY_DECISION_RETENTION**). Monthly partitioning of `audit_logs`,
`service_transactions` and `cafeteria_transactions` becomes worthwhile in the EXPECTED scenario
within its first year ([database-capacity.md](database-capacity.md#autovacuum-and-growth-tables)).

## PHP-FPM sizing (method; no hardware is specified)

1. `pm.max_children` = (node RAM − OS − OPcache − 1 GB reserve) ÷ peak worker RSS. Use 70 MB
   per worker for mixed web and API traffic, from the measured 41–72 MB peaks. **Re-measure on
   the real host** with `ps -o rss` under load.
2. Choose workers per node ≈ 2–4 × CPU cores. The work is I/O-light and CPU-heavy: request time
   is mostly PHP.
3. Nodes = peak workers needed ÷ workers per node, + 1 for failure (N+1).
4. `pm = static`, or `dynamic` with `pm.max_requests = 1000`. The soak showed no growth, but
   recycling is cheap insurance.
5. OPcache: `validate_timestamps=0` in production, reset on deploy;
   `opcache.memory_consumption ≥ 256`; `max_accelerated_files ≥ 40000`. That is what the rig used.
   The value on the real server is **NEEDS_DEPLOYMENT_CONFIGURATION**.
6. Do not adopt Octane to raise these numbers: framework boot is not the dominant cost.

## Scale up or scale out

- **LOW:** two nodes for availability, plus a primary and a standby.
- **EXPECTED:** 4–5 app nodes, PgBouncer, primary + standby, Redis for cache and limits.
- **HIGH:** scale out app nodes. Measure first on a dedicated database host before adding read
  replicas; partner reads are index probes and do not need replicas.

## Alerts

Initial thresholds come from the measured rig behaviour. Tune them after a production baseline.

| Signal | Warn | Critical | Why |
| --- | --- | --- | --- |
| API p95 (5 min) | > 500 ms | > 2 s | rig p95 ~200 ms below the knee; at saturation p95 jumped to 4–13 s |
| 5xx rate (5 min) | > 0.5 % | > 2 % | measured 0–0.04 % in healthy runs |
| Busy PHP workers / max_children | > 70 % | > 90 % | the knee is the first limit |
| DB connections / max_connections (or pool) | > 70 % | > 90 % | |
| DB deadlocks | any | > 5/h | none observed in any test |
| Oldest job age per queue | > 5 min | > 30 min | |
| Outbox pending | > 50,000 | > 500,000 or oldest > 15 min | the publisher drains ~480/s |
| Webhook deliveries `retrying` / `dead` | > 1,000 / any | > 10,000 / > 100 | |
| Disk free (DB volume) | < 25 % | < 10 % | growth table above |
| Readiness failing nodes | ≥ 1 | ≥ 50 % | |
