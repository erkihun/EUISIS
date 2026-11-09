# Load balancer and multi-node readiness

Production Gate 2, 2026-10-19. The deployment procedure is in
[../load-balancing.md](../load-balancing.md); this page records what was
verified and what is still open.

## Verdict: multi-node capable once five deployment settings are made

- **Measured:** the API load tests ran with k6 picking a random node for every request. That is
  a load balancer with **no session affinity**, over 8–24 identical nodes sharing one PostgreSQL
  ([load-test-results.md](load-test-results.md)).
- **No cross-node errors:** nothing in those runs failed because a request reached a different
  node than the previous one.
- **Earlier evidence:** web sessions were exercised the same way in the 2026-10-11 run
  ([../load-testing.md](../load-testing.md)).

| Concern | State | Verdict |
| --- | --- | --- |
| Sessions | `database` driver (`sessions` table); no remember-me; MFA state is in the session | OK; never `file` |
| CSRF | framework default; token in the shared session | OK |
| Cache, rate limits, locks | default store `database`; 15 named limiters plus route throttles; `Cache::lock`, `onOneServer`, `ShouldBeUnique` | OK on `database`/`redis`; **wrong on `file`/`array` with >1 node** (each node counts separately, locks don't exclude) |
| Scheduler | 23 tasks, all `withoutOverlapping()->onOneServer()` | OK; a second scheduler host is harmless |
| Persistent files | every persistent file type (photos, ID print artefacts, evidence, legal sources, imports, exports, attachments) is on the `local` or `public` disk, which switch to S3 via `STORAGE_PRIVATE_DRIVER` / `STORAGE_PUBLIC_DRIVER` | **NEEDS_DEPLOYMENT_CONFIGURATION**: node-local by default |
| Code that assumed a local path | `DesignImportReader` opened staged imports with `fopen(Storage::path())`, which breaks on S3 | **Fixed:** streams to a node-local temp copy on non-local disks (test added) |
| Other `path()` use | `EmployeePhotoStorage` thumbnails and `images:optimize` call `path()`. On S3, thumbnails fall back to the full-size original (degraded, not broken) | P2 |
| Temporary files | PhpSpreadsheet and import temp copies go to `sys_get_temp_dir()` | OK: local temp is fine |
| Maintenance mode | `APP_MAINTENANCE_DRIVER=file` by default, which takes only one node down | **NEEDS_DEPLOYMENT_CONFIGURATION**: set `cache` |
| Logs | `LOG_STACK=single`, node-local file | **NEEDS_DEPLOYMENT_CONFIGURATION**: ship to a central collector |
| Trusted proxies | `APP_TRUSTED_PROXIES` empty by default; `config/trustedproxy.php` reads it; no `trustHosts` | **NEEDS_DEPLOYMENT_CONFIGURATION**, see below |
| Health checks | `/health/live`, `/health/ready`, `/up`; outside the session middleware | OK; two fixes this gate, see below |

## Trusted proxies and forwarded headers

- **What happens if it is unset:** set `APP_TRUSTED_PROXIES` to the LB/proxy addresses only.
  Otherwise HTTPS is not detected (no HSTS, insecure redirects), and every client appears as the
  LB address. All per-IP throttles then share one key: login, public ID checker, public
  feedback. One abusive client would lock everyone out.
- **Never use `*`.** That lets any client forge `X-Forwarded-For` and bypass per-IP limits.
- **The proxy must overwrite, not append, `X-Forwarded-For`** from untrusted clients.
- **Add `trustHosts`** (or proxy-level Host validation): without it, password-reset links can be
  poisoned through the Host header.

## Health endpoints (load balancer contract)

| Path | Use | Expected |
| --- | --- | --- |
| `GET /health/live` | liveness: restart the process only if this fails | `200 {"status":"ok"}`; touches nothing |
| `GET /health/ready` | readiness: take the node out of rotation, do not restart | `200` when database, cache, queue store and private storage answer; `503 {"status":"fail","checks":{…}}` otherwise |

Two changes in this gate:

1. **The queue probe no longer runs `COUNT(*)` on `jobs`.** On the database driver it now does a
   one-row lookup. Before, every LB probe, every few seconds on every node, counted the whole
   jobs table. That gets slower exactly when a backlog builds.
2. **The storage probe now fails when the private storage root is not a writable directory**
   (a broken or read-only mount). Before, it ignored the result and always reported ok. An
   *unmounted* path that falls back to the node's own empty directory still passes. The
   deployment must alert on the mount itself, or use object storage, where an unreachable
   bucket throws.

Probe interval guidance:

- 5–10 s, with 2–3 failures before draining.
- Restrict both paths to the LB network at the proxy.
- They are not throttled, so do not expose them publicly.

## Compression and static assets

- `public/.htaccess` sets deflate and long-lived `immutable` cache headers for `/build/*`
  (Vite-hashed). That applies to Apache only.
- The documented topology is nginx, and the repository holds **no nginx config**. Add
  `gzip`/`brotli` for `text/html`, `application/json`, `text/css` and `application/javascript`,
  plus `Cache-Control: public, max-age=31536000, immutable` on `/build/`:
  **NEEDS_DEPLOYMENT_CONFIGURATION**.
- API JSON responses compress about 5–8×. That matters for change-feed pages of 500 events.
