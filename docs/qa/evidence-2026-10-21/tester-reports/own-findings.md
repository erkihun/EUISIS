# Own findings (lead tester) — established facts so far

## Environment
- Repo erkihun/euisis @ be95c28 (main), Laravel 12.69.2, PHP 8.3.6 (bcmath installed by me), Node 22.22, PostgreSQL 16.15 local (/tmp:54329, db euisis_app), .env from .env.example with DB_CONNECTION=pgsql, CACHE_STORE=database, QUEUE_CONNECTION=database, MAIL_MAILER=log, APP_DEBUG=false, APP_ENV=local.
- Composer plugins disabled by the sandbox → vendor/pest-plugins.json hand-generated from composer.lock (Pest parallel plugin).
- Imagick not available (same as CI).

## Static gates
- `npm run build` (tsc + vite): PASS; warning: chunks > 600 kB (rich-text-editor 1.2 MB).
- `npm run i18n:audit`: PASS (0 blocking; 164 warn latin_words_in_am, 1 deprecated_term). Note: audit fails with 117 "missing_en" when vendor/ is absent (it resolves framework validation keys from vendor) — bootstrap ordering dependency only.
- `vendor/bin/pint --test`: PASS, 0 style issues.

## Migrate / seed on PostgreSQL
- `php artisan migrate`: PASS (15.5 s, fresh DB).
- `php artisan db:seed`: **FAIL** with SQLSTATE 25P02 "current transaction is aborted" on `update "cache_locks"` — PG log shows first error `duplicate key value violates unique constraint "cache_locks_pkey"` on `insert into "cache_locks"`. Stack: EmployeeAssignmentCommunicationObserver.php:43 → PendingDispatch::__destruct (unique job SyncEmployeeCommunicationMemberships). Works with `CACHE_STORE=file php artisan db:seed`.
- Minimal repro (tinker, CACHE_STORE=database, PostgreSQL): inside DB::transaction dispatch the same ShouldBeUnique job twice → QueryException 25P02. Mechanism: Illuminate\Cache\DatabaseLock::acquire() does INSERT then catches QueryException and UPDATEs; on PostgreSQL the failed INSERT poisons the enclosing transaction, so the UPDATE and everything after fail. RegisterEmployeeAction.php:35 runs in DB::transaction and triggers two dispatches for the same employee (assignment created + employee current_assignment_id saved) → employee registration path affected. 9 ShouldBeUnique jobs exist; 8 transactional services/actions dispatch jobs.

## Serve, smoke, gates
- `scripts/smoke-test.sh http://127.0.0.1:8123`: 12/13 PASS (HTTPS check N/A on local http).
- `production:readiness`: no blocking items; WARN: pgsql sslmode=prefer, imagick missing, brand logo/favicon missing, no feedback routing rules, backups not configured, config/routes not cached, 0 of 14 institutions with public QR.
- `security:production-check`: no unsafe settings; WARN session cookie not Secure (http), log level debug (my .env).

## Repo CI status (GitHub Actions, workflow "Laravel", branch main)
- Runs #100–#106 all "failure"; run #107 (be95c28) in progress at time of check. Run #106 (d5e2c3c): Tests 3 failed, 2 skipped, 4506 passed, Duration 10366 s (2h53m, single process). Failing: Intelligence\TelemetryTest (flush, 0.0 vs 25.0 at :75), OrganizationDesign\DesignImportTest (Excel 527 vs 528 at :164), PublicFeedback\OrganizationFeedbackFormTest (offices at :249).

## Local Pest (SQLite, --parallel 3)
- First run: one worker stuck > 20 min at 91 % CPU on one test (no per-test time limit), 263 F in progress files of ~4.5k. Killed; re-run with enforceTimeLimit 180 s + JUnit in progress. Box load avg 6–10 during the run (6 browser agents) — timing-sensitive tests may fail; to be triaged by re-running failures serially when quiet.

## Browser auth journey (01-auth.js, 14/14 PASS)
- Login page EN/AM, wrong password error, Super Admin forced TOTP enrolment, dashboard (191 nav links), logout, MFA challenge on second login, wrong code rejected, protected page blocked before challenge, correct TOTP accepted, TOTP replay refused across sessions, HR Officer no MFA, login lockout after bad attempts (identity+IP 5/15min, account 20, IP 100), forgot-password no enumeration, /register redirects (registration disabled). 0 console errors, 0 5xx.
- Harness notes: UI defaults to Amharic (LocaleContext default 'am'); Inertia client-side navigation requires waiting on URL change.

## Page crawl as Super Admin (02-crawl.js): 349 parameterless GET routes
- 330×200, 13×403 (12 are /my-portal/* for a non-employee account, 1 /communication/api/channel-scopes/units), 2×404 (/court-cases feature-flagged off, /situation-room/actions/owners), 2 file downloads. **0 server errors, 0 debug output, 0 JS page errors, 0 5xx XHR.** 68 pages > 3 s under load (slowest /organizations/create 10.0 s, /organization-unit-types/create 8.8 s, /reporting-lines 8.6 s, /organizations/import-structure 8.5 s, /performance/plans 7.8 s) — contended box; re-measure when quiet.

## HTTP security probes (50-security-probes.sh)
- Headers: CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, COOP/CORP present. **X-Powered-By: PHP/8.3.6 exposed** (php built-in server; not stripped by SecurityHeaders middleware). **CSP script-src includes 'unsafe-inline' in production too** (SecurityHeaders.php:86-88, acknowledged TODO for nonce).
- CSRF: POST /login without token → 419 rendered as redirect back; login does not succeed (dashboard still 302 → login). PASS.
- Error hygiene: 404/invalid uuid/api 404 → no internals; TRACE 405.
- Sensitive files: .env, composer.*, .git/*, logs, employeeId_db, sec-routes.json → 404/403. **/.htaccess → 200** (php built-in server; irrelevant behind nginx, relevant if `php -S` is ever used).
- Unauthenticated API: 11 parameterless GET api routes → none 200. /session/status → 401 JSON.
- Throttling: forgot-password 429 after 5/min PASS; /health/live unthrottled (expected).
- Session: cookie rotated on login PASS; old cookie rejected after logout PASS; staff session to /provider/portal/scan → 401 (guard separation) PASS.
- Host header: `Host: evil.example` → redirect to http://evil.example/login. No TrustHosts middleware, no URL::forceRootUrl(APP_URL) (only forceScheme). Password reset notification is the framework default (URL from request host) → reset-link poisoning possible if the web server forwards arbitrary Host.
- Mail: password reset for an existing user logged `Password reset email could not be sent ... 127.0.0.1:587 Connection refused` although .env has MAIL_MAILER=log — System Settings › Email seeded `mail_mailer=smtp` with empty host (system_settings rows), and RuntimeMailSettings applies DB settings over .env (RuntimeMailSettings.php:82). User still sees the generic "link sent" message. Affects password reset and OTP after a fresh seed.

## Access-control design observations
- MFA_REQUIRED_ROLES default = "Super Admin,City Admin,HR Manager,Cafeteria Admin,Security Officer". Roles "HR Manager" and "Security Officer" do not exist (56 seeded roles). "System Admin" has the same 879 permissions as Super Admin and "Public Service Bureau Admin" 777, neither requires MFA → a full-privilege account can be used with password only.
- Repo hygiene: committed `employeeId_db` (2.8 MB SQLite dev DB with reference tables), `sec-routes.json` (297 KB), `sec-audit.json` (empty), `cognee_stderr.txt`, `omniroute_stderr.txt` at repo root.
- Client default locale is Amharic by design (LocaleProvider defaultLocale='am'); server APP_LOCALE=en → first page reconciliation (see agent F O3).
