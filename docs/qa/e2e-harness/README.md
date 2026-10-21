# E2E QA harness (2026-10-21 pass)

Playwright scripts used for the end-to-end test report in `docs/qa/e2e-test-report-2026-10-21.md`. They are evidence and a starting point, not a maintained suite: selectors follow the UI as of commit `be95c28`, and several scripts carry ids from that run in `results/*-state.json` files that are not committed.

## Prerequisites

- A running app (`php artisan serve --port=8123`) with a seeded database and a `queue:work` worker. `BASE_URL` overrides the default `http://127.0.0.1:8123`.
- Node 22 and `playwright@1.56.x` installed next to the scripts (`npm i playwright@1.56.1`); the Chromium that version expects (`npx playwright install chromium` outside the sandbox).
- `02-crawl.js` reads `../routes.json`, the output of `php artisan route:list --json`.
- Demo accounts use the password `password` (local/testing environments only). MFA-required roles (Super Admin, City Admin) are handled by `lib.js` with a TOTP generator; the first login enrols and prints the secret into `results/superadmin-secret.txt`.

## Files

| Script | Area |
|---|---|
| `lib.js` | shared helpers: browser/context with English UI cookie, `login` (MFA-aware), `logout`, `submitAndLeave` (waits for Inertia client-side navigation), `visit`, `Recorder` (step log, console/page errors, 5xx XHR) |
| `01-auth.js` | authentication, MFA enrolment/challenge/replay, lockout, forgot-password, registration off |
| `02-crawl.js` | visits every parameterless GET route as a user and records status, timing, console errors |
| `10-hr-*.js` | organizations, positions, employees, CSV import, transfers |
| `20-idcards-lifecycle.js` | card request → approve → print batch → issue → activate → public checker → lost/revoke → replacement |
| `30-public-*.js` | public site, complaints and tip-offs, back office, employee portal, XSS probes |
| `40-admin-*.js` | users, roles, profile, system settings, audit, recycle bin, API management, code rules, dashboard |
| `50-security-probes.sh` | curl-based header, CSRF, throttling, session and information-leak probes |
| `60-portal-*.js` | service providers, provider users, cafeteria and transport portals, settlements |
| `70-quality-*.js` | Amharic rendering, Ethiopian calendar, language switch, responsive, dark mode, axe, performance |
| `80-perf-quiet.js` | median-of-three page timings on an idle box |
| `AGENT_BRIEF.md` | the brief each module tester worked from |

Run any script with `node <script>`; results land in `results/<name>.json` and screenshots in `shots/`.
