# Deployment and rollback runbook

For the operator deploying EUISIS to staging or production. Commands assume a
Linux host, the application in `/var/www/euisis/current` (a symlink to the
active release), PHP-FPM, a queue worker under a process supervisor, and cron.
Adjust paths to the real host. No command here prints a secret.

Related: [go-live checklist](../go-live-checklist.md) ·
[backup and disaster recovery](../backup-and-disaster-recovery.md) ·
[incident response](../incident-response-plan.md) ·
[production readiness assessment](../production-readiness-assessment.md)

## 0. Before the window

1. The release is a tagged commit that passed CI: `php artisan test`, `npx tsc --noEmit`, `npm run build`, `composer audit --locked`, `npm audit --omit=dev`.
2. The release has been deployed to **staging on the production database engine and version** and passed steps 3–6 there, including the UAT script in the go-live checklist.
3. Announce the window. Scanning at cafeteria counters stops while the site is in maintenance mode; agree the time with providers (outside meal hours).

## 1. Back up (mandatory)

Before a destructive/high-risk migration, require a recent pgBackRest full chain plus
WAL in both independent encrypted repositories, successful verification and a current
isolated restore test. Follow [database backup operations](../runbooks/database-backup.md).
Record backup labels, repository/timeline, validation evidence and the matching versioned
file snapshot in the change ticket. Escrow keys separately. Do not take an unencrypted
storage tarball or rely only on a logical dump. Do not run backup commands automatically
from Laravel migrations. Missing recovery evidence blocks the deployment.

## 2. Build the new release (site still up)

```bash
RELEASE=/var/www/euisis/releases/$(date +%Y%m%d%H%M)
git clone --depth 1 --branch <tag> <repo-url> "$RELEASE"
cd "$RELEASE"
ln -s /var/www/euisis/shared/.env .env
ln -s /var/www/euisis/shared/storage storage
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci && npm run build          # or copy public/build from the CI artefact
php artisan migrate:status        # read: which migrations are Pending
php artisan migrate --pretend     # read the SQL; stop if anything is unexpected
php artisan production:readiness  # must end "No blocking items"
```

## 3. Switch

```bash
cd /var/www/euisis/current && php artisan down --retry=60
cd "$RELEASE"
php artisan migrate --force
php artisan optimize               # config, routes, views and events cached
php artisan permission:cache-reset
ln -sfn "$RELEASE" /var/www/euisis/current
sudo systemctl reload php8.2-fpm
php artisan queue:restart
php artisan up
```

## 4. Verify (within 15 minutes)

```bash
scripts/smoke-test.sh https://<production-host>          # must print SMOKE TEST PASSED
php artisan production:readiness                          # no FAIL
php artisan data:audit-duplicates
php artisan structure:audit
php artisan cafeteria:audit-configuration
php artisan queue:failed                                  # empty
tail -n 100 storage/logs/laravel-$(date +%F).log          # no new errors
```

### Employee portal mobile delivery check

Before ending the deployment window, test the login and `/my-portal` on an
actual Android and iPhone over mobile data as well as Wi-Fi. The live `.env`
must retain these values:

```dotenv
APP_URL=https://ems.pshrdb.gov.et
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=ems.pshrdb.gov.et
CORS_ALLOWED_ORIGINS=https://ems.pshrdb.gov.et
```

Nginx/Plesk must compress JavaScript and CSS. Confirm the deployed main
JavaScript response includes `Content-Encoding: gzip` or `br` when requested;
without it, low-bandwidth phones can spend too long loading the portal bundle.

Then one real counter scan at one cafeteria, watched by the provider, and the
transaction visible in both the provider portal and the back office.

## 5. Roll back

Roll back when a smoke check fails, the counter scan fails, or error rates rise
and a fix is not ready within the window.

**Code only** (no migration ran in step 3, or the migrations only added tables/columns):

```bash
php artisan down --retry=60
ln -sfn /var/www/euisis/releases/<previous> /var/www/euisis/current
cd /var/www/euisis/current && php artisan optimize && php artisan permission:cache-reset
sudo systemctl reload php8.2-fpm && php artisan queue:restart && php artisan up
scripts/smoke-test.sh https://<production-host>
```

**Code and data**: several migrations in this release move data and have no
exact inverse (`2026_09_20_120000_remove_direct_card_print_permission`,
`2026_09_22_140000_align_change_request_permission_names`,
`2026_09_27_000300_add_entitlement_ledger_and_transaction_snapshots`, the
permission registrations). **Do not use `migrate:rollback` on production.**
Use the controlled [PITR workflow](../runbooks/postgresql-pitr.md) with the step 1 recovery evidence:

1. `php artisan down`.
2. Export every cafeteria transaction, card status change and settlement made since the switch (back-office exports), so they can be re-entered or reconciled — a restore discards them.
3. Preserve current state; restore database/WAL and matching files/keys in isolation. Validate, obtain independent approval, then perform controlled cutover.
4. Point `current` at the previous release, `php artisan optimize`, `permission:cache-reset`, reload PHP-FPM, `queue:restart`, `up`.
5. Smoke test, then reconcile the exported records with the providers before the next settlement.

Record what happened in the incident log (incident-response-plan.md).

## Scheduled and background processes

| Process | Requirement |
|---|---|
| Cron | `* * * * * cd /var/www/euisis/current && php artisan schedule:run >> /dev/null 2>&1` |
| Queue worker | `php artisan queue:work --tries=3 --max-time=3600` under supervisor; restarted by `queue:restart` on every deploy |
| Scheduled jobs | `cafeteria:sync-policy-statuses` 00:05, `api:prune-logs` 02:30, `nfc:prune-challenges` 02:45, `daily-activities:send-reminders` every 15 min |
| Load balancer | health check `GET /up` (fails when the database or cache is unreachable); set `APP_TRUSTED_PROXIES` to the balancer addresses |
