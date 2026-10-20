# Load, scale and resilience tooling

**Benchmark databases only.** Every script refuses `APP_ENV=production`. The ones that write
also refuse any database that holds employees whose numbers do not start with `SC-`
(synthetic). Results and method are in [docs/production-readiness/](../docs/production-readiness/).

| Path | What it does |
| --- | --- |
| `php artisan scale:generate` | Synthetic 3M-scale data: organizations → units → positions → employees → assignments → cards → entitlements, plus history. Varied English/Ge'ez names. |
| `php artisan scale:export-api-loadtest-data <file>` | Creates synthetic API clients with tokens and samples employees for k6. Keep the file outside the repository: it holds bearer tokens. |
| `php artisan scale:export-loadtest-data <file>` | Web-session k6 data (`k6/euisis.js`) |
| `k6/partner-api.js` | Partner API profiles: smoke, normal, busy, peak, burst, soak, custom. Mixes: mixed, read, scan, or a single endpoint. |
| `k6/euisis.js`, `k6/public-feedback.js` | Web and public-site load (2026-10-11/12) |
| `k6/summarize.mjs` | k6 `--summary-export` → markdown rows |
| `rig/nodes.sh start\|check\|stop N PORT` | N local `php -S` nodes with OPcache and their own config cache |
| `rig/run.sh` | one measured run: k6 + `rig/sampler.php` (database connections, locks, commits, queue, outbox) |
| `rig/lb.mjs` | health-checked round-robin LB, for failure tests |
| `rig/webhook-receiver.mjs` | local webhook receiver (`ok`/`down`/`slow` modes); never point at real partners |
| `db/explain-hot-paths.php` | EXPLAIN (ANALYZE, BUFFERS) medians of the hot queries with sampled keys |
| `db/baseline.sql` | sizes, counts, settings |
| `db/integrity-checks.sql` | invariants after load; every check must be 0 |
| `db/restore-drill.sh` | dump → restore into a new database → compare counts and checksums → integrity |
| `db/realistic-names.sql`, `db/backfill-entitlements.sql` | upgrade a benchmark database made by the older generator |
| `concurrency/race.php run <scenario> N R` | parallel-process races on the real actions: assignment, position, transfer-same, transfer-two, transfer-position, revoke, idempotency |
| `integration/outbox-bench.php` | outbox write overhead, backlog, publish rate, subscriptions, webhook delivery rate |
| `profile/requests.php` | per-request PHP memory, time and queries (cold cache) |
| `profile/query-log-router.php` | `php -S` router that logs every SQL statement per request |
