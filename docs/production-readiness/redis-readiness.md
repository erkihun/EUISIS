# Redis readiness

Production Gate 2, 2026-10-19. Evidence: configuration review plus the
3M-employee benchmark ([load-test-results.md](load-test-results.md)).

## Verdict

**Redis is optional for the platform as configured, and it was not measured.**
The shipped configuration (`.env.example`) keeps every piece of shared state in
PostgreSQL:

| Concern | Driver in `.env.example` | Where it lives | Shared across nodes |
| --- | --- | --- | --- |
| Sessions | `SESSION_DRIVER=database` | `sessions` table | yes |
| Cache, cached aggregates | `CACHE_STORE=database` | `cache` table | yes |
| Rate limits (15 named limiters, ~150 route throttles, login throttle) | default cache store | `cache` table | yes |
| Locks (`onOneServer`, `withoutOverlapping`, `ShouldBeUnique`, `Cache::lock`) | default cache store | `cache_locks` table | yes |
| Queues | `QUEUE_CONNECTION=database` | `jobs`, `failed_jobs` | yes |

So a Redis outage cannot take the platform down today: no request path uses
Redis unless an operator sets one of those drivers to `redis`. The price is that
PostgreSQL is the only shared-state dependency. It carries a session write and
rate-limit counter writes on every request (measured in
[load-test-results.md](load-test-results.md), "per-request write cost").

This machine has no Redis server, no `phpredis` extension and no `predis`
package, so nothing below was run against Redis. Every Redis statement is
**design guidance, not a measurement**.

## Keys and isolation (config review)

- `config/database.php` defines two connections, `default` (db 0) and `cache` (db 1). There is no
  separate session or queue connection, and no Sentinel or cluster nodes.
  `persistent=false`.
- Both prefixes derive from `APP_NAME`: `addis_one_database_` and `addis_one_cache_`. Staging and
  production on one Redis server would read each other's keys. `.env.example` now documents
  `REDIS_PREFIX` / `CACHE_PREFIX` per environment.
- Cached keys that hold scoped data include the scope fingerprint:
  - the Situation Room `SituationCache` keys on scope + filters;
  - `DashboardDataService` keys on scope + permission set (120 s).
  - Neither the card, employee nor eligibility API paths cache anything. Revocation and inactive
    status are therefore read from PostgreSQL on every request, with no staleness window. See
    [failure-testing.md](failure-testing.md), revoke race.

## If Redis is introduced (recommended at scale)

Moving sessions, cache and rate limits to Redis removes 2–5 small PostgreSQL writes per request.
That is the main reason to add it.

- **Use separate logical databases or instances:**
  - db 0: queue, only if the queue moves;
  - db 1: cache, rate limits and locks;
  - a separate connection for sessions (`SESSION_CONNECTION`).
- Set `maxmemory-policy noeviction` on any instance holding locks, queues or sessions. Eviction
  silently drops locks and jobs.
- **HA:**
  - Cache-only Redis can fail open. Laravel then errors on cache calls, so it must be HA or the
    store set back to `database`.
  - Sessions or locks in Redis need Sentinel or a managed HA Redis:
    **NEEDS_DEPLOYMENT_CONFIGURATION_REDIS_HA**.
  - Without HA, a Redis outage would log everyone out and stop rate limiting. Rate limiting is
    fail-closed: the limiter throws, so requests error.
- **Persistence:**
  - Redis would never hold canonical data (employees, cards and transactions stay in
    PostgreSQL).
  - Queues need AOF (`appendfsync everysec`) or must stay in PostgreSQL. A lost job is a lost
    notification or webhook trigger. Integration events themselves are safe: they sit in the
    transactional outbox (`integration_events`) and are re-published from there.
  - Sessions: losing them only forces re-login. RDB snapshots suffice.
- **Memory estimate (extrapolated, not measured):**
  - Sessions: a database session row averages the payload measured in `sessions`; at about 1 KB
    each, 50,000 concurrent sessions ≈ 50 MB.
  - Rate-limit keys: two per active client per minute window, so negligible.
  - Cached aggregates: Situation Room and dashboard entries are compact JSON, tens of MB at most.
- **Network:** Redis must sit on the private network with `requirepass`/ACL users and TLS if the
  infrastructure policy requires it. It must never be exposed publicly.

## Redis failure behaviour

| Mode | Behaviour | Tested |
| --- | --- | --- |
| Current (`database` drivers) | Redis down: no effect | n/a — Redis is not on any path |
| Cache/limits on Redis, Redis down | cache calls throw, so pages and API return 500; `/health/ready` fails `cache` and the LB drains every node | **NOT MEASURED** (no Redis here) |
| Sessions on Redis, Redis down | every request loses its session (logged out) | **NOT MEASURED** |

`/health/ready` checks the cache store on every probe, so a Redis-backed cache outage marks
nodes not-ready instead of reporting healthy.
