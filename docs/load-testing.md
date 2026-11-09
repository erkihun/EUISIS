# Load testing

How EUISIS is load-tested, how to repeat it, and what the 2026-10-11 run
measured. The numbers come from one developer laptop. They show relative
cost, horizontal scaling and query defects. They are **not** production
capacity: see [capacity-plan.md](capacity-plan.md) for what they do and do
not support.

## Tooling

| Piece | Where | What it does |
| --- | --- | --- |
| `php artisan scale:generate` | `app/Console/Commands/Scale/GenerateScaleData.php` | Fills a database with synthetic, PII-free organizations, positions, employees, cards, assignments, cafeteria/service transactions, daily-activity logs and audit history. Refuses to run in production and needs PostgreSQL; point it at a separate database with `DB_DATABASE`. |
| `php artisan scale:export-loadtest-data` | `app/Console/Commands/Scale/ExportLoadTestData.php` | Writes the k6 input file: login accounts (`sc-<n>@scale.invalid`, every tenth a scoped HR officer), public card UUIDs, search terms. |
| `loadtest/k6/euisis.js` | k6 script | Three arrival-rate scenarios (employee portal, HR officer, public ID checker) spread across `BASE_URLS` at random, i.e. a load balancer with no session affinity. |

Profiles (`-e PROFILE=`): `smoke`, `baseline`, `peak`, `stress`, and
`custom` for step tests (`EMPLOYEE_RATE`, `HR_RATE`, `PUBLIC_RATE`,
`DURATION`). Rates are iterations per second. One employee iteration is 4
pages; one HR iteration is 4 pages; one public iteration is 1 request.

The thresholds in the script (error rate < 1 %, page p95 < 1.5 s, p99 < 3 s,
public p95 < 800 ms) are **test pass/fail placeholders**, not agreed SLOs.
Replace them with the business SLOs once those are set.

The public ID checker is rate-limited per client IP. k6 sends everything from
one IP, so `429` there is counted in `euisis_public_throttled`, not as an
error. Real traffic comes from many IPs.

## Repeating the test

```bash
# 1. A separate, migrated database (never the application database)
createdb aaemployeedb_scale
DB_DATABASE=aaemployeedb_scale php artisan migrate --force
DB_DATABASE=aaemployeedb_scale php artisan db:seed --class=PermissionSeeder --force   # and the other reference seeders
DB_DATABASE=aaemployeedb_scale php artisan scale:generate --employees=200000 --organizations=200 --history-days=20 --login-users=200
DB_DATABASE=aaemployeedb_scale php artisan scale:export-loadtest-data loadtest-data.json
psql aaemployeedb_scale -c 'ANALYZE'

# 2. App nodes behind the URLs in BASE_URLS (php-fpm or Octane on real hosts;
#    locally, N single-threaded `php -S` processes started from public/ with
#    OPcache on and config/route/event caches built)

# 3. Run
k6 run -e PROFILE=smoke -e BASE_URLS=http://node1,http://node2 \
       -e DATA=loadtest-data.json -e PASSWORD='…' loadtest/k6/euisis.js
```

Local pitfalls found on the way (Windows): Laravel's dev router resolves
`index.php` from the working directory, so start `php -S` from `public/`;
`APP_*_CACHE` paths must be relative to the project or start with `/`
(a `C:/…` path is joined onto the base path); Git Bash rewrites arguments
that start with `/` unless `MSYS_NO_PATHCONV=1` is set.

## 2026-10-11 run

**Environment.** One laptop: Intel i9-14900HX (24 cores / 32 threads), 16 GB
RAM, Windows 11. PostgreSQL 18.6, PHP 8.2.29 (OPcache on, config/route/event
cached, `APP_DEBUG=false`), database-backed session/cache/queue, k6 2.2.0 on
the same machine. App nodes: single-threaded `php -S` processes (8, then 24).

**Data set** (`aaemployeedb_scale`, after `ANALYZE`):

| Table | Rows |
| --- | ---: |
| employees / positions / id_cards | 220,000 each |
| organizations | 240 |
| cafeteria_transactions | 2,640,081 |
| service_transactions | 2,640,081 |
| daily_activity_logs | 3,077,657 |
| audit_logs | 4,402,419 |
| users | 201 (180 employees, 20 scoped HR officers, 1 Super Admin) |

That is 7 % of the 3M-employee target.

### Results

| Run | Nodes | Offered | Achieved | Errors | Page p50 / p95 / p99 | Public p95 |
| --- | ---: | --- | ---: | ---: | --- | --- |
| smoke | 8 | 2 emp + 1 HR + 1 public it/s | 24 req/s | 0 % | 68 ms / 283 ms / 731 ms | 212 ms |
| baseline (overload) | 8 | 20 + 5 + 10 it/s | 22 req/s | 21 % (timeouts, 429s counted then) | 26 s / 46 s / 52 s | 46 s |
| baseline (overload) | 24 | 20 + 5 + 10 it/s | 69 req/s | 1.1 % | 4.4 s / 20 s / 47 s | 35 s |
| sustained #1 | 24 | 10 + 2 + 5 it/s | 98 req/s | 0 % | 110 ms / 691 ms / 2.19 s | 788 ms |
| sustained #2 (60 s after restarting all nodes) | 24 | 10 + 2 + 5 it/s | 72 req/s | 0 % | 303 ms / 9.0 s / 17 s | 14 s |
| sustained #3 (after cool-down) | 24 | 10 + 2 + 5 it/s | 99 req/s | 0 % | 104 ms / 424 ms / 1.14 s | 575 ms |
| step 1.4× | 24 | 14 + 3 + 5 it/s | 99 req/s | 0 % | 1.7 s / 8.7 s / 12.9 s | 9.8 s |

"Achieved" counts every HTTP request (pages, login GET/POST, public checks
including throttled ones).

What the numbers show:

- **Horizontal scaling works.** Tripling the nodes from 8 to 24 tripled
  throughput (22 → 69 req/s under the same overload) with sessions, cache,
  rate limits and scheduler locks shared through the database. No request
  depended on which node served the previous one.
- **The app tier, not PostgreSQL, was the limit.** During sustained runs
  PostgreSQL had at most 3 active queries; each single-threaded node served
  about 4 page requests per second.
- **The knee on this rig is ~100 req/s with 24 workers** (≈ 4 req/s per
  worker). At 1.4× the sustainable rate throughput stayed flat and latency
  queued: the system degrades by queueing, not by erroring.
- **Run-to-run variance on a laptop is large** (p95 424 ms to 9 s at the same
  rate). The slow run started 60 s after 24 cold nodes came up
  (OPcache empty). Use dedicated hosts and repeat each step for decisions.
- **Login is the most expensive request** (bcrypt, ~350 ms alone, 1–3 s
  under load). A login storm after an outage or at shift start is the
  realistic peak to plan for.

### Single-request cost (in-process, warm, 220k employees)

| Page | Queries | Time |
| --- | ---: | ---: |
| /my-portal | ~66 | ~38 ms |
| /dashboard (scoped HR, cached figures) | ~30 | ~38 ms |
| /employees | ~45 | ~58 ms |
| /positions/status (scoped) | ~40 | 255 ms → **41 ms** after fix |
| /positions/status (city-wide) | ~40 | ~930 ms |
| /dashboard (city-wide, first computation) | 117 | 7.4–10.7 s |

A cold request (fresh process, as in PHP-FPM) costs ~170–300 ms more than a
warm one (permission registry, translations, model boot). The PostgreSQL
connection alone is ~30 ms per request without a pooler.

### Defects the run found

| Finding | Fix |
| --- | --- |
| Position status totals grouped **every** current assignment in the city before joining one organization's positions (4 × ~80 ms per page) | Aggregates filtered to the reported positions (.[1].) |
| Position list ORDER BY walked the unit index across all 220k positions, discarding 219,500 rows to return 15 | `positions_org_unit_code_idx`, migration `2026_10_11_100000` (.[1].) |
| Dashboard provider ranking joined every service transaction ever recorded | Counts the selected period (04b3337) |
| Every dashboard transaction chart left-joined employees and assignments, even for global viewers | Scope applied by subquery only when needed (04b3337) |
| City-wide dashboard: first computation 7–11 s, dominated by aggregating 2.6M transactions and 4.4M audit rows | **Open** — risk register SCL-11 (daily rollup tables) |
| Every page, including public ones, carries ~515 KB of inline script: the Ziggy route map (405 KB, all 1,160 routes) and Vite's prefetch list (110 KB) | **Open** — risk register SCL-12 |

### Not covered by this run

Cafeteria scanning and settlement under load, exports, file uploads/downloads
through S3, queue workers under load, PgBouncer, a database on its own host,
TLS termination at a real load balancer, many client IPs, and failover. Each
needs a run on staging infrastructure before the capacity plan's numbers are
trusted.
