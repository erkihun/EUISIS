# Core platform: application rollback runbook

Use this when a release misbehaves after its switch. It was rehearsed on
2026-10-26: N to N+1, then a simulated fault, then back to N, then forward
again (evidence: [core-go-live-evidence.md](../go-live/core/core-go-live-evidence.md) §7).
Script: [`core-platform/deploy/release/rollback.sh`](../../core-platform/deploy/release/rollback.sh).

## Decide

Roll back when the new release causes user-facing errors, wrong data or a
security fault, and a fix cannot be deployed within the agreed time (to be set
by the application owner). Roll back the **application only**. The database
stays on the new schema, because migrations are additive within a release
line ([core-deployment.md](core-deployment.md), "Migration rules").

**Do not** run `php artisan migrate:rollback` in production. `down()` methods
drop columns and tables, and with them data. A schema fault is fixed forward
with a new migration.

## Roll back

```bash
CORE_ROOT=/srv/core SMOKE_URL=https://core.example/ \
RELOAD_CMD='sudo systemctl reload php8.4-fpm' \
WORKERS_CMD='sudo systemctl restart core-worker@*' \
CORE_MONITOR_TOKEN="$(cat /etc/core/monitor.token)" \
core-platform/deploy/release/rollback.sh            # to PREVIOUS_RELEASE, or: rollback.sh <sha8>
```

What it does, and what to check at each step:

| Step | What happens | Check |
|---|---|---|
| Code | `current` goes back to `releases/<previous>`. That release's own `vendor/` and `bootstrap/cache` come with it | `cat /srv/core/CURRENT_RELEASE` |
| Frontend assets | each release carries its own `public/build`, so the screens roll back together with the code | the smoke test checks that `/login` serves that release's `app-*.js` |
| Configuration cache | `optimize:clear`, then `optimize` in the target release, against the current `shared/.env` | the config audit is not run again; a changed `.env` is rolled back separately (below) |
| Database compatibility | the script lists the migrations the target release does not know | each listed migration must be additive. If one is not, stop and fix forward instead |
| PHP-FPM | `RELOAD_CMD`, a graceful reload | no 502s on the monitor |
| Queue workers | `queue:restart` and `WORKERS_CMD`: workers finish their current job and restart on the old code | jobs queued by the newer release must still run on the older one. Core jobs carry ids, not code, but check failed jobs after a rollback |
| Horizon | not used (the Core has no Redis) | — |
| Cache | the application cache (database store) holds settings and health data only, no code | after a rollback across a settings-schema change: `php artisan cache:clear` |
| Smoke | `/up`, `/health/live`, `/health/ready`, `/login` (with that release's assets), and `/health/monitor` reports the target release | the script fails loudly if any check does not pass |

## Configuration rollback

`shared/.env` is rendered from the secret store, and its history is the secret
store's. To undo a configuration change, re-render the previous version and
run `php artisan optimize` in `current/backend`. Then reload PHP-FPM and run
`queue:restart`. Never roll back a **rotated key** by restoring the old value
alone. Rotations follow their own runbooks: credential keys in ADR 0010, and
the audit seal key in [core-operations-runbooks.md](core-operations-runbooks.md).

## After

- Leave the bad release in `releases/` for investigation; `deploy.sh` prunes
  later.
- Record the rollback: time, from, to, reason and who decided. Add it to the
  incident record.
- Fix forward: a new release carrying the fix, plus any corrective migration.
