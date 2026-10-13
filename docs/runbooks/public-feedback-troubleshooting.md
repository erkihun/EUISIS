# Public feedback: "citizens cannot submit"

What to run on the server, in order, when a QR code opens but feedback,
suggestions or complaints do not go through. Every step is read-only unless
it says otherwise.

## 1. Run the doctor

```bash
php artisan public-feedback:doctor
```

One line per dependency of the public form: pending migrations, the policy
switches, active QR codes and tokens, the complaint numbering rule, cache
(including the atomic locks the one-time form token needs), session, queue,
mail, `APP_URL`, cookie security, proxies and the last logged submission
failures. `FAIL` lines are the cause; `WARN` lines are worth fixing.

## 2. What each failure means

| Doctor line | What the citizen sees | Fix |
|---|---|---|
| Migrations: pending | 500 error page with an `ERR-…` code | `php artisan migrate --force`, then run the doctor again |
| Public feedback enabled: switched off | "This feedback link is not available" | System Settings › Public feedback › Enabled |
| Active institution QR codes: none | nothing to scan | Organizations › institution › Feedback QR › Generate; print the sheet (PDF) |
| Complaint numbering rule: missing | complaints fail, ratings and suggestions work | `php artisan migrate --force` (migration 2026_10_10_000200 creates it) |
| Cache: cannot store or lock | every submission says "could not be saved" | `CACHE_STORE=database` needs the `cache` and `cache_locks` tables (migrations); `file` needs a writable `storage/framework/cache` |
| Session: table missing | every submission answers 419 (page expired) | `SESSION_DRIVER=database` needs the `sessions` table; or use `file` |
| Secure session cookie: APP_URL not https | every submission answers 419 | the site must be served over HTTPS with `APP_URL=https://…`, or set `SESSION_SECURE_COOKIE=false` on a plain-HTTP server |
| Queue: oldest job waits over 15 minutes | submissions are saved, but acknowledgements and handler alerts never arrive | run a worker: `php artisan queue:work database --queue=notifications,default --tries=3` under a process manager or cron |
| Recent submission failures | "could not be saved" after pressing Submit | the line names the SQLSTATE; fix the database issue it points to |

## 3. Rate limits

Each QR code accepts 3 submissions and each visitor address 8 submissions per
10 minutes (System Settings › Public feedback). Over the limit the citizen sees
"Too many requests". If the application sits behind a reverse proxy that does
not pass the visitor's address through (`REMOTE_ADDR` is the proxy), every
visitor shares one address and the limit is reached by the whole city at
once. Check with the doctor's *Trusted proxies* line and the web server's
access log: if PHP sees `127.0.0.1` for everyone, set
`APP_TRUSTED_PROXIES=127.0.0.1` (or the proxy's address) in `.env`.

## 4. Reference data a new server needs

`DatabaseSeeder` is demo data and refuses to run in production. A new server
seeds reference data with:

```bash
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=SystemSettingsSeeder --force
php artisan db:seed --class=CodeRuleSeeder --force
php artisan db:seed --class=GrievanceDefaultsSeeder --force
php artisan db:seed --class=FieldWorkTypeSeeder --force
php artisan db:seed --class=OrganizationTypeSeeder --force
php artisan db:seed --class=OrganizationUnitTypeSeeder --force
php artisan db:seed --class=IsicActivitySeeder --force
php artisan db:seed --class=OccupationSeeder --force
php artisan db:seed --class=CafeteriaSettingsSeeder --force
php artisan db:seed --class=CafeteriaDayRuleSeeder --force
php artisan db:seed --class=PublicSiteDefaultsSeeder --force
```

All of them can be run again safely.

## 5. Verified on MySQL

The module was run against MariaDB 10.4 on 2026-10-13: all 239 migrations,
the 21 public-feedback test files (220 tests), the public site, ID checker and
service-feedback admin tests (225 tests), and a browser pass of the landing,
rating, suggestion and complaint pages for both an institution QR and an
employee QR, including a real rating submission and a refused replay. Nothing
in the module is PostgreSQL-specific.
