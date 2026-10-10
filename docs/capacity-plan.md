# Capacity plan

What the 2026-10-11 load test supports for sizing a 3,000,000-employee
deployment, and what it does not. Measurements and method are in
[load-testing.md](load-testing.md). No figure here is an SLO: response-time,
availability, RPO and RTO targets are for the business owner to set.

## What was measured

| Fact | Value | Basis |
| --- | --- | --- |
| Data set | 220,000 employees, 240 organizations, 2.6M service + 2.6M cafeteria transactions, 4.4M audit rows | 7 % of target employees |
| Throughput per app worker | ≈ 4 page requests/s at page p95 < 0.7 s | 24 single-threaded workers sustained ~99 req/s, two of three runs |
| Scaling | Linear in workers (8 → 24 workers: 3.1× throughput) | Same overload, same data |
| Database load at that rate | ≤ 3 concurrent active queries | `pg_stat_activity` sampled during the run |
| Login | ~350 ms alone; 1–3 s under load | bcrypt cost; dominates the first request of every session |
| Cold vs warm request | +170–300 ms on a fresh process | PHP-FPM starts every request cold |

Not measured: a database on separate hardware, PgBouncer, S3 storage, queue
workers under load, cafeteria scanning at volume, many client IPs, failover.

## Sizing method

Required app workers ≈ peak page requests per second ÷ requests per worker,
plus headroom for a node failing and for login storms.

The worker rate must be **re-measured on the target hosts**: a laptop core
under `php -S` is not a server core under PHP-FPM or Octane. Until then use
the measured 4 req/s/worker as a planning floor.

Peak demand depends on usage that only the business can state (share of
employees using the portal at the busiest hour, pages per visit, HR and
cafeteria activity). An illustrative calculation, with **assumptions to be
replaced**:

| Assumption (replace) | Value |
| --- | --- |
| Employees active in the busiest hour | 10 % of 3M = 300,000 |
| Pages per active employee in that hour | 4 |
| Peak page rate | 300,000 × 4 / 3,600 ≈ 333 req/s |
| Workers at 4 req/s | ≈ 84 |
| With one-node-down headroom and 50 % margin | ≈ 130 workers, e.g. 8 nodes × 16 PHP-FPM workers |

Cafeteria scans are separate: each is a short write path (locks one
entitlement and the provider row). They need their own measurement before
sizing the lunch-hour peak.

## Database

At the tested rate PostgreSQL was idle most of the time, so the database
size for interactive pages is driven by data volume and the heavy paths
below, not by request count. Before go-live:

- Run the same profiles against a production-sized copy (3M employees) on the
  production database class, and capture `pg_stat_statements` top queries.
- Put PgBouncer (transaction pooling) in front of PostgreSQL: each request
  opens a connection (~30 ms locally), and 130 workers plus queue workers
  exceed a default `max_connections`.
- Keep `ANALYZE` current after bulk loads; the synthetic data set needed it
  for good plans.

## Known limits that block city-wide scale

| Limit | Measured | Status |
| --- | --- | --- |
| City-wide dashboard (Super Admin / oversight) first computation | 7–11 s at 220k employees; grows with transactions and audit rows | Risk register **SCL-11**: needs daily rollup tables for transaction and audit charts. Cached and shared for 120 s once computed. |
| City-wide `/positions/status` | ~0.9 s at 220k positions | Grows linearly with positions; acceptable for a few administrators, re-measure at 3M. |
| Inline page payload | ~515 KB per page (Ziggy 405 KB + Vite prefetch 110 KB) | Risk register **SCL-12**. Enable gzip/brotli at the load balancer; reduce the route map. |
| Org-structure API | Nested document per organization | Bounded: above `API_STRUCTURE_MAX_NODES` (10,000) it answers `422 structure_too_large` and names the paginated endpoints. |

Scoped users (one organization) did not hit these limits: a scoped HR
officer's first dashboard computation took ~0.66 s with ~0.11 s in the
database.

## What would change this plan

Re-run [load-testing.md](load-testing.md) on staging hosts with a 3M data
set, at least three repetitions per step, with clients spread over several
IPs. Replace the measured worker rate and the illustrative assumptions above
with those results and the business's usage figures.
