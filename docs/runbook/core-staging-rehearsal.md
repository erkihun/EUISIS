# Core platform: staging rehearsal

How the go-live rehearsal is run end to end. It was done on 2026-10-26 on a
developer workstation (results: [core-go-live-evidence.md](../go-live/core/core-go-live-evidence.md)).
**It must be repeated on real staging** (Linux, Nginx, PHP-FPM 8.4,
PostgreSQL 18, private storage, real TLS) before go-live. The scripts are
the same, and only the rig differs.

| Workstation rig (`core-platform/loadtest/rig/`) | Real staging |
|---|---|
| `tls-lb.mjs`: a Node TLS front with a self-signed certificate, round-robin, health-checked | Nginx or the load balancer with the organization's certificate |
| `nodes.sh`: N single-threaded `php -S` processes with OPcache | PHP-FPM pool; `RELOAD_CMD='systemctl reload php8.4-fpm'` |
| `workers.sh`: `queue:work` ×2 and `schedule:work` | systemd/supervisor units and cron `schedule:run` |
| directories on one disk for "off-host" | a second host or bucket (`OFFHOST_CMD`) |
| a throwaway GPG key in a temp home | the escrowed backup key |

## Sequence

1. **Prepare.**
   - Staging root: `releases/`, `shared/.env` rendered from the secret store
     (separate random keys: `APP_KEY`, `CORE_CREDENTIAL_KEYS`,
     `CORE_AUDIT_SEAL_KEY`, `NATIONAL_ID_HASH_KEY`, `CORE_MONITOR_TOKEN`;
     `APP_ENV=production`, `APP_DEBUG=false`, `https://` APP_URL,
     `SESSION_SECURE_COOKIE=true`, the `database` queue/cache/session,
     `TRUSTED_PROXIES`, `CORE_AUDIT_HEAD_DISK`, `CORE_BACKUP_STATUS_FILE`).
   - An empty PostgreSQL 18 database.
2. **Build** each release once: `deploy/release/build-artifact.sh <commit> <dir>`.
3. **Deploy N** with `deploy/release/deploy.sh`.
4. **Load data:** `core:import-from-euisis` from the (sanitized) EUISIS copy,
   then `php deploy/migration/reconcile.php` (it must pass).
5. **Test accounts:** synthetic, with MFA, one per role (never real people).
   **Golden Path:** `deploy/staging/golden-path-smoke.php`.
6. **Backup:** `deploy/backup/core-backup.sh`. **Restore drill:**
   `deploy/backup/core-restore-drill.sh` from the off-host copy into an
   isolated cluster. It must pass; the status file then shows the restore
   test.
7. **Configuration review:** `php artisan core:config-audit` must pass in
   production mode (keys, backup, scheduler, seal heads, storage, TLS
   settings).
8. **Deploy N+1** and run the smoke test again: the Golden Path, plus
   `/health/monitor` reporting N+1.
9. **Simulate a fault, then roll back:** `deploy/release/rollback.sh`. The
   smoke test must pass on N against N+1's schema. Then deploy N+1 again
   (fix forward).
10. **Load:** `loadtest/k6/core-golden-path.js` at stepped virtual users,
    with `loadtest/rig/sampler.ps1` (or the hosts' own metrics) recording CPU,
    memory, database connections and the queue.
11. **Monitoring:** point the external monitor at `/health/ready` and
    `/health/monitor`, then trigger each critical alert once
    ([core-monitoring-and-alerts.md](core-monitoring-and-alerts.md)).
