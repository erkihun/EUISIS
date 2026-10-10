# Load balancing and stateless app nodes

How to run EUISIS on several application nodes behind a load balancer. Related:
[golden path](production-golden-path.md) · [production runbook](production-runbook.md)
· [database scale](database-scale.md).

```
Clients → WAF / reverse proxy (TLS) → Load balancer
            → App node 1..N  (nginx + PHP-FPM, identical release, no local state)
                 ├─ PostgreSQL primary (+ standby)      via PgBouncer (recommended)
                 ├─ Shared store for sessions / cache / locks / queue (database today; Redis recommended)
                 └─ S3-compatible private + public object storage
            Queue workers (separate processes/hosts)   Scheduler (one active instance)
```

## What makes a node stateless

| Concern | Production setting | Notes |
| --- | --- | --- |
| Sessions | `SESSION_DRIVER=database` or `redis` | Never `file`. MFA state lives in the session (`mfa_verified_at`), so a shared driver keeps MFA valid across nodes. No sticky sessions needed. |
| Cache, rate limits, locks | `CACHE_STORE=database` or `redis` | Rate limits and `Cache::lock` (backup monitor, ID-card templates, schedule `onOneServer`) must be shared. |
| Queue | `QUEUE_CONNECTION=database` or `redis` | `retry_after` 900s (must exceed the longest job timeout, 600s). |
| Private files | `STORAGE_PRIVATE_DRIVER=s3`, `STORAGE_PRIVATE_BUCKET`, `AWS_*` | Evidence, photos, ID print artifacts, attachments, generated letters. Never public URLs. |
| Public files | `STORAGE_PUBLIC_DRIVER=s3`, `STORAGE_PUBLIC_BUCKET`, `STORAGE_PUBLIC_URL` | Logos, profile photos, public-site images. Replaces the per-node `public/storage` symlink. |
| Maintenance mode | `APP_MAINTENANCE_DRIVER=cache` (with a shared cache store) | The `file` driver takes only one node down. |
| Logs | ship to central logging (stderr/syslog → collector) | `LOG_STACK=single` writes node-local files. |
| Feature flags | `config/features.php` via env | Identical env on every node. |

Moving existing files to object storage: copy `storage/app/private/**` and
`storage/app/public/**` to the buckets preserving paths, then switch the driver
(see [object storage recovery](runbooks/object-storage-recovery.md)). Database rows
that store `disk => 'local'` keep working because the `local` disk name is kept and
re-pointed.

## Load balancer

- **Health checks:**
  - Liveness: `GET /health/live` (no dependency touched; restart a node only on this).
  - Readiness: `GET /health/ready` → 200 when database, cache, queue store and private
    storage answer; 503 otherwise (take the node out of rotation, do not restart).
  - Neither starts a session. Restrict both to the load balancer's network at the proxy.
- **TLS and proxies:** terminate TLS at the proxy and forward `X-Forwarded-For/Proto/Host/Port`.
  Set `APP_TRUSTED_PROXIES` to the proxy/LB addresses. Without it HTTPS is not
  detected (HSTS skipped), and every client appears as the LB address, so per-IP
  throttles (public QR feedback, ID checker, login) collapse onto one key.
- **Cookies:** `APP_URL=https://…` makes session cookies `Secure`; `HttpOnly` and
  `SameSite=lax` are the defaults; `SESSION_ENCRYPT=true`.
- **No sticky sessions** are required once sessions/cache are shared.
- **Draining:** remove a node from rotation, let in-flight requests finish (FPM
  `request_terminate_timeout` ≥ the longest synchronous request), then stop it.

## Rolling deployment

1. Build the release artifact once (CI) — identical on every node.
2. Run migrations once, from one node or a release job, before switching traffic.
   Migrations must be backward compatible with the previous release (expand → migrate
   → contract; see [database scale](database-scale.md)).
3. Switch nodes one at a time: drain, swap the release symlink, reload FPM, readiness
   check, re-add.
4. `php artisan queue:restart` once (shared cache) so workers pick up new code.
5. Scheduler: exactly one host runs `schedule:run` each minute; every task is also
   `onOneServer()` so a second scheduler is harmless.

## Node replacement

A new node needs only the release, the shared `.env`, and network access to
PostgreSQL/PgBouncer, the shared cache/queue store and object storage. It holds no
data; a failed node is simply replaced.

## Workers

Run separate supervised pools so a long job never blocks operational work:

```
php artisan queue:work --queue=default --timeout=120 --tries=3
php artisan queue:work --queue=notifications --timeout=120 --tries=3
php artisan queue:work --queue=long --timeout=600 --tries=3
```

Every worker's `--timeout` must stay below the connection `retry_after` (900s).
