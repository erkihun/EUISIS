# Shared brief for E2E test agents (Addis One / EUISIS)

## System under test
- Laravel 12 + Inertia React app, running at **http://127.0.0.1:8123** (`php artisan serve`, 4 PHP workers). A `queue:work` worker is running.
- Database: PostgreSQL 16 (production engine). Inspect with:
  `psql -h /tmp -p 54329 -U postgres -d euisis_app -c "<sql>"` (read freely; write only to fix up test data you created).
- App log: `/home/user/EUISIS/storage/logs/laravel.log` (shared with the Pest suite running in parallel, grep for `local.ERROR`).
- Repo: `/home/user/EUISIS` (read-only for you: do NOT edit, commit, or run artisan commands that mutate config; `php artisan tinker --execute='...'` is fine for reading or creating test data).
- Default UI language is Amharic; the harness sets the `euisis_locale=en` cookie so pages render in English. Pass `uiLocale: 'am'` to `newBrowser` to test Amharic.
- Demo password for every seeded account is `password`. Roles `Super Admin`, `City Admin` require TOTP MFA; do not use them. Use the account assigned to you.

## Harness (Node + Playwright, Chromium already installed)
Directory: `/tmp/claude-0/-home-user-EUISIS/04a59b1e-adf3-537d-b073-538f279f04b1/scratchpad/e2e`
- `lib.js` exports: `BASE`, `newBrowser({ viewport, uiLocale, timeout })` → `{ browser, context, page }`; `newPageIn(context, uiLocale)`; `login(page, email, password)` (returns landing URL; handles MFA when given a secret); `logout(page)`; `submitAndLeave(page, fromUrlRegex, timeoutMs)` (click `button[type=submit]` and wait for the URL to change — use this instead of waitForNavigation, Inertia navigates client-side); `visit(page, url)` (asserts no debug output / no 4xx-5xx); `expectUrl(page, re)`; `shot(page, name)`; `Recorder` (`R.attach(page)`, `await R.step(label, async () => {...}, page)`, `R.save()`).
- Write your script as `NN-<area>.js` in that directory, run with `node NN-<area>.js`. Results go to `results/<name>.json`, screenshots to `shots/`.
- Look at `01-auth.js` for the pattern. Selectors: prefer `input[name=...]`, `getByRole`, `getByLabel`, `getByText`. Read the React page under `/home/user/EUISIS/resources/js/Pages/<Module>` to find field names and buttons before guessing.
- Routes: `/tmp/claude-0/-home-user-EUISIS/04a59b1e-adf3-537d-b073-538f279f04b1/scratchpad/routes.json` (output of `route:list --json`).

## How to test (professional tester posture)
- Test real user journeys end to end through the browser, including validation errors, authorization boundaries (a role that must NOT see/do something), and data persistence (reload the page / query the DB to confirm).
- Every step must be evidence-based: HTTP status, visible text, DB row, screenshot. Do not report something as working that you did not observe.
- Record anything odd even if not in your scope: console errors, slow pages (>3 s), English text leaking into Amharic, broken layouts, 500s, stack traces, PII exposure, missing CSRF, insecure defaults.
- Leave shared state usable: do not deactivate seeded users, do not change system settings without reverting, do not delete seeded organizations; create your own test records with a `QA-` prefix.

## Report format (your final message)
1. One-line verdict for your area.
2. Table of journeys: journey, result (PASS/FAIL/BLOCKED), evidence.
3. Defects: ID, severity (Critical/High/Medium/Low), title, steps to reproduce, expected vs actual, evidence path (screenshot/log line/SQL), suspected root cause with file:line if you looked.
4. Observations (non-defects) and untested scope with reason.
