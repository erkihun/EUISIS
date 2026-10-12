# Public feedback load testing

How the unified public feedback flow (employee QR and institution QR; rating,
suggestion and complaint) is load-tested, how to repeat it, and what the
2026-10-12 run measured. The numbers come from one developer laptop and show
relative cost, the shape of degradation and horizontal scaling. They are
**not** production capacity and they do not support any claim about
3,000,000 employees (QA brief §74): see the "What was measured" section for
the exact dataset and rig. The general platform run and its capacity notes
are in [load-testing.md](load-testing.md) and [capacity-plan.md](capacity-plan.md).

## Tooling

| Piece | Where | What it does |
| --- | --- | --- |
| `php artisan feedback:scale-seed` | `app/Console/Commands/Scale/SeedPublicFeedbackScale.php` | On a database built by `scale:generate`: ensures an active QR code for every active institution, city-wide categories, one handler account (`fb-handler-<n>@scale.invalid`, "Service Quality Team", scoped to one institution) and one routing rule per institution, active feedback tokens for `--employee-tokens` employees (each with one position service so a rating can be submitted), and appends `--submissions` synthetic submissions spread evenly over the six target × type combinations with realistic statuses, history and assignments. PII-free; refuses production and non-PostgreSQL databases. |
| `php artisan scale:export-loadtest-data` | `app/Console/Commands/Scale/ExportLoadTestData.php` | Now also writes `employeeFeedback` (token + service id), `organizationTokens`, `categories`, `feedbackHandlers`, `feedbackAdmins` and `openSubmissions` (with the handler of the same institution) for the k6 script. |
| `loadtest/k6/public-feedback.js` | k6 script | Scenarios A–J of QA brief §71 as arrival-rate scenarios, plus a scan burst (§72). Requests are spread over `BASE_URLS` at random (a balancer with no affinity). |

### Scenarios

| Scenario | What one iteration does | Rate (profile `baseline`) |
| --- | --- | --- |
| A employee QR landing | GET `/service-feedback/{token}` | 10/s |
| B institution QR landing | GET `/feedback/{token}` | 10/s |
| C employee rating submit | GET the form, read the one-time form token, POST rating + message + service | 1/s |
| D employee suggestion submit | GET form, POST message + category | 1/s |
| E employee complaint submit | GET form, POST message + category | 1/s |
| F institution rating submit | as C against `/feedback/{token}/feedback` | 1/s |
| G institution suggestion submit | as D | 1/s |
| H institution complaint submit | as E | 1/s |
| I internal list / dashboard | handler signs in once per VU; GET complaints inbox, suggestions inbox, feedback dashboard | 4/s (12 pages/s) |
| J assignment / review | Super Admin signs in once per VU; POST assign of an open suggestion/complaint to the institution's handler (ends the previous assignment, writes history and audit, queues a notification) | 2/s |
| BURST one poster scanned | ramp 0 → `BURST_RATE` scans/s of **one** institution QR in 10 s, hold 30 s, ramp down 10 s, on top of a light background of A–J | profile `burst`, 200/s |

Profiles: `smoke` (2 landing, 1 submit, 1 internal, 1 review per second,
30 s), `baseline` (20 / 6 / 4 / 2, 3 min), `peak` (60 / 15 / 10 / 4, 3 min),
`stress` (150 / 30 / 20 / 8, 3 min), `burst` (5 / 2 / 2 / 1 plus the ramp,
70 s) and `custom` (`LANDING_RATE`, `SUBMIT_RATE`, `INTERNAL_RATE`,
`REVIEW_RATE`, `DURATION`, `BURST_RATE`). The `submit` figure is the total
over C–H; each of the six runs at a sixth of it.

A submission counts as **stored** only when the redirect after the POST
renders the receipt (`submitted: true`); the DB row delta during the run is
reported alongside as an independent check. The public submit routes are
throttled per QR code (3 per 10 minutes by default) and per client address
(8 per 10 minutes). One generator is one address, so every request carries
an `X-Forwarded-For` from a per-VU pool and the nodes under test trust the
generator as a proxy (`APP_TRUSTED_PROXIES=127.0.0.1`); a real deployment
behind a balancer sees distinct client addresses the same way. A `429` is
the limiter working and is counted in `pf_throttled`, never as an error. The
thresholds in the script (error rate < 1 %, landing p95 < 800 ms, submit and
page p95 < 1.5 s, page p99 < 3 s) are **pass/fail placeholders**, not agreed
service levels; there is no public SLA to encode (QA brief: do not invent
one).

## Repeating the test

```bash
# 1. A separate, migrated, seeded database (never the application database)
DB_DATABASE=aaemployeedb_scale php artisan migrate --force
DB_DATABASE=aaemployeedb_scale php artisan db:seed --class=PermissionSeeder --force
DB_DATABASE=aaemployeedb_scale php artisan db:seed --class=RolePermissionSeeder --force
DB_DATABASE=aaemployeedb_scale php artisan scale:generate --employees=200000 --organizations=200 --login-users=200
DB_DATABASE=aaemployeedb_scale php artisan feedback:scale-seed --employee-tokens=2000 --submissions=20000
DB_DATABASE=aaemployeedb_scale php artisan scale:export-loadtest-data loadtest-data.json

# 2. N app nodes with SHARED session/cache/queue stores (database or redis),
#    APP_TRUSTED_PROXIES naming the generator, config/route/event caches built,
#    OPcache on; locally: php -S from public/ with the framework's server.php.
#    One queue worker: php artisan queue:work database --queue=notifications,default

# 3. Run (repeat each profile; discard the first run after a node restart)
k6 run -e PROFILE=baseline -e BASE_URLS=http://node1,http://node2 \
       -e DATA=loadtest-data.json -e PASSWORD='…' --summary-export out.json loadtest/k6/public-feedback.js
```

Role grants are part of the deploy, not of the migrations: the module's
permissions are created by migrations and granted to no role, so a database
that only ran `migrate` has a Super Admin who cannot assign or resolve (the
first scale run returned `403` on every assignment until the role seeders
ran). `php artisan rbac:report --strict` reports that drift.

## 2026-10-12 run

**Environment.** One laptop: Intel i9-14900HX (24 cores / 32 threads), 16 GB
RAM, Windows 11. PostgreSQL 18, PHP 8.2.29 (OPcache on, config/route/event
caches built, `APP_DEBUG=false`), database-backed session, cache (also the
rate limiter) and queue, one `queue:work` process, k6 2.2.0 and the
PostgreSQL server on the same machine. App nodes: 8 single-threaded `php -S`
processes. Redis was not part of this rig (the cache store under test was
the database), so no Redis latency was measured.

**Data set** (`aaemployeedb_scale` after `feedback:scale-seed`):

| Table | Rows |
| --- | ---: |
| employees / id_cards | 220,000 each |
| organizations (all with an active feedback QR) | 240 |
| employee_feedback_tokens (active) | 2,000 |
| public_feedback_categories / routing_rules / handler accounts | 8 / 240 / 240 |
| employee_service_feedback | 20,033 before the runs (3,339 per target × type combination) |
| public_feedback_status_history / assignments | 46,263 / 13,355 |
| audit_logs | 4,404,773 |

That is 7 % of the 3M-employee target for employees and a small submission
table; the submission-side numbers below say nothing about a table of
millions of submissions.

### Results

Process figures come from a sampler that polled `pg_stat_activity`, the
`jobs` table and the Windows process list every 5 s. "php CPU" sums every
php.exe on the machine (the 8 nodes, the queue worker, the sampler and an
idle development server), so it overstates the nodes slightly. "PG
connections" is the total for the scale database including the sampler's
own connection.

#### Runs

| Run | Nodes | Offered (it/s: landing + submit + internal + review) | Achieved req/s | Iterations dropped | p50 / p95 / p99 (all requests) | Errors | Stored (k6 receipts; DB rows) |
| --- | ---: | --- | ---: | ---: | --- | ---: | --- |
| smoke (cold, 30 s) | 8 | 2 + 1 + 1 + 1 | 11.1 | 5 | 447 ms / 1.77 s / 2.22 s | 1.3 % ¹ | 33; 33 |
| baseline (overload) | 8 | 20 + 6 + 4 + 2 | 37.2 | 1,069 | 8.97 s / 32.0 s / 47.4 s | 3.1 % ² | 814; — |
| sustained (custom) | 8 | 8 + 3 + 1 + 1 | 22.1 | 302 | 3.21 s / 15.9 s / 25.0 s | 0.0 % | 464; 456 |
| baseline | 24 | 20 + 6 + 4 + 2 | 49.0 | 575 | 1.40 s / 12.0 s / 29.5 s | 0.1 % ³ | 985; 900 ⁴ |
| burst (one poster, 100 scans/s for 30 s + 5 + 2 + 2 + 1 background) | 24 | ramp 0→100→0 over 50 s | 43.5 | 967 | 2.09 s / 19.5 s / 27.4 s | 0.0 % | 108; 103 |

¹ the assign step answered 403 because the scale database had only run
`migrate`, not the role seeders (see "Repeating the test"); fixed before the
later runs. ² request timeouts and failed logins under overload, no 5xx.
³ four rating submissions whose redirect timed out. ⁴ k6 counts every receipt;
the duplicate-submission guard shows a receipt for a repeat of the same text
from the same code within its window without storing a second row, which
is the difference between the two figures.

| Run | Request kind | p50 | p95 | p99 |
| --- | --- | ---: | ---: | ---: |
| sustained, 8 | landing / submit / internal page | 3.65 s / 2.87 s / 2.88 s | 15.6 s / 15.8 s / 16.2 s | 23.2 s / 25.7 s / 25.3 s |
| baseline, 24 | landing / submit / internal page | 1.19 s / 1.68 s / 1.58 s | 12.2 s / 10.7 s / 12.2 s | 30.9 s / 25.2 s / 27.7 s |
| burst, 24 | landing (burst scans included) / submit / page | 2.04 s / 2.44 s / 2.37 s | 18.6 s / 19.6 s / 20.9 s | 27.4 s / 26.6 s / 26.7 s |

| Run | VUs max | PG connections max (active, lock waits) | Queue backlog max → end | Failed jobs | php processes | php CPU (cores, avg) | php RSS max | postgres RSS max |
| --- | ---: | --- | --- | ---: | ---: | ---: | ---: | ---: |
| sustained, 8 | 236 | 10 (3, 1) | 4 → 0 | 0 | 12 | 4.5 | 963 MB | 524 MB |
| baseline, 8 | 561 | 10 (4, 2) | 6 → 3 | 0 | 12 | 4.8 | 1,048 MB | 602 MB |
| baseline, 24 | 341 | 25 (9, 5) | 6 → 1 | 0 | 28 | 11.7 | 2,498 MB | 891 MB |
| burst, 24 | 489 | 24 (6, 3) | 3 → 0 | 0 | 28 | 7.7 | 2,517 MB | 877 MB |

Not measured: PostgreSQL CPU (the service runs under another Windows
account and reports no CPU time to the sampler), Redis latency (no Redis in
this rig), TPS of the database (the k6 "stored" counter and the row delta
are the transaction figures). After the runs: 0 jobs queued, 0 failed jobs,
every notification delivered to the database channel.

### What the numbers show

- **The app tier saturates first, and it saturates early for this flow.**
  Eight single-threaded workers sustained about 22 req/s and twenty-four
  about 49 req/s of this mix, i.e. 2–3 req/s per worker — below the ~4 req/s
  per worker of the portal pages measured in October's general run. A
  submission is two requests (form page with a one-time token, then the
  POST that writes the row, history, assignment, audit entry and a queued
  notification inside one transaction), and a landing page costs 13
  queries. Every profile above `sustained` on 8 nodes and `baseline` on 24
  was over the knee: p50 rose to seconds and k6 dropped iterations, but the
  error rate stayed at or near zero — the system degrades by queueing, not
  by failing.
- **Horizontal scaling works for this flow.** Tripling the nodes raised
  throughput from 22 to 49 req/s at the same offered load with session,
  cache, throttle and one-time form tokens shared through the database; no
  request depended on the node that served the previous one.
- **PostgreSQL was never the limit.** At most 9 active queries and 5
  lock waits on 25 connections, under 900 MB resident. The hot-row scan
  burst (100 scans/s of one poster, each incrementing the same
  `scan_count` row) produced no errors and at most 3 lock waits.
- **The queue kept up with one worker** (backlog ≤ 6, 0 failures); the
  routing notification for every stored suggestion and complaint was
  delivered within the run.
- **What this does not say.** Nothing here is production capacity: the
  nodes were `php -S` processes on the same laptop as the database and the
  generator, the submission table held 21k rows, and PostgreSQL CPU was not
  observed. It does say that a 3M-employee deployment needs the public
  feedback tier sized by requests per second of this mix (about 2–3 req/s
  per PHP worker on this hardware, to be re-measured on the target hosts
  with php-fpm or Octane), that the database and queue have headroom at
  these rates, and that a crowd scanning one poster does not break the flow.

### Defects the runs found

| Finding | Fix |
| --- | --- |
| A database that only ran `migrate` has no role holding the module's permissions; Super Admin got 403 on every assignment | documented as a deploy step; `rbac:report --strict` reports the drift; the readiness gate warns when no role holds `service_feedback.assign` |
| The runner's first k6 script counted a 403 page as a failed check without saying why | the script now reports status and validation errors per step (`pf-debug-assign`) |
