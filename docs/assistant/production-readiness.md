# Addis One Assistant — production readiness

## Deployment configuration

- Migrations: `2026_10_20_100000_create_support_tickets_table` (drops the
  **empty** placeholder `support_tickets` table from the 2026-05 foundation
  migration only when it has `opened_by` and zero rows; otherwise it throws —
  check the live table before deploying), `2026_10_20_101000_register_support_permissions`,
  `2026_10_20_110000_create_assistant_tables`.
- Scheduler: `assistant:prune` daily 03:50 (one server).
- Settings: System Settings › Assistant (capabilities, limits, retention);
  AI itself under Intelligence (`INTELLIGENCE_AI_*` in the environment).
- Rate limiter uses the configured cache store — Redis in production for
  distributed limits (`NEEDS_DEPLOYMENT_CONFIGURATION`).
- Support routing: `config/support.php` (`SUPPORT_DEFAULT_TEAM`,
  `SUPPORT_TEAM_<CATEGORY>` env) — `NEEDS_POLICY_DECISION_SUPPORT_ROUTING`.
- Knowledge: import approved documents (`intelligence:knowledge-import`) so
  page help and knowledge answers have sources.

## Verification record (2026-10-19, local, PostgreSQL dev DB + SQLite tests)

- `tests/Feature/Assistant` (11 tests) + `tests/Feature/Support` (4 tests): green.
- Guard tests (Ziggy routes file, sidebar navigation, admin UI consistency,
  translation audit, RBAC seeding, Intelligence foundation, System Settings):
  green; the only failing guards (`BoundaryHardeningTest` SBH-001,
  `SharedBrandingPropTest`) list the pre-existing Communication module
  offenders and nothing from the assistant or support domains.
- `npm run typecheck`, `npm run build`, `node scripts/i18n-audit.mjs --strict`:
  clean (one pre-existing deprecated-term warning in the transport module).
- `vendor/bin/pint --test` on every new PHP file: passed.
- `composer audit --locked`: no advisories; `npm audit --omit=dev`: 0.
- `php artisan migrate --pretend`: nothing pending; `php artisan about`: Laravel 12.69 / PHP 8.4.
- Browser (Chrome via Playwright, local server, System Admin account):
  trigger absent on the login page and present after sign-in; panel opens
  with status **Limited** (no AI provider configured) and the limited
  notice; "Check system status" returns the deterministic card (9 UNKNOWN
  components, scheduler unhealthy because no local scheduler runs);
  "The PDF export is not working, I got ERR-…" returns the troubleshooting
  steps, the observed "no verified incident" line and the ticket form;
  "Create support ticket" created `TKT-2610-0001` with a confirmation card
  and "View ticket" opened the ticket page (safe context only); 390 px
  viewport renders the full-height sheet with a sticky composer and the
  conversation preserved across navigation; Escape closes the panel and
  returns focus to the trigger; switching to Amharic localises the whole
  panel, dates show in the Ethiopian calendar, and "ይህን ገጽ አብራራ" answers in
  Amharic. Zero console errors apart from the one 500 caused by a stale
  local table (see "Deployment configuration").

The commands run were:

```
php artisan test tests/Feature/Assistant tests/Feature/Support
php artisan test --filter="ZiggyRoutesFileTest|AppSidebar|AdminUiConsistency|TranslationAudit|RbacSeeding|Intelligence"
php artisan test --parallel --processes=6 --filter='/^(?!.*PerformanceWorkflowTest)/'
vendor/bin/pint --test (changed files)
npm run typecheck && npm run build && node scripts/i18n-audit.mjs --strict
php artisan migrate --pretend / migrate:status / route:list --name=assistant
composer audit --locked, npm audit
```

## Bundle impact

The trigger ships inside the layout chunk; the panel is a lazy chunk
(`AssistantPanel-*.js`, ~29 kB raw / ~9 kB gzip at build time) loaded on
first open. Main `app-*.js` stays at ~333 kB raw / ~100 kB gzip.

## P0 / P1 posture

P0 (release blockers) — none open: scope enforcement, conversation and
ticket IDOR, secrets never sent, HR data gated, logs never exposed, no
arbitrary tools/SQL/shell, RAG filtered before context, text-only
rendering, failure isolation — all covered by tests listed in
`security.md`.

P1 — none known after the local E2E pass; items that depend on
installation data (help coverage for every module, approved knowledge
documents, Redis for limits) are listed as NEEDS_* in `architecture.md`.
