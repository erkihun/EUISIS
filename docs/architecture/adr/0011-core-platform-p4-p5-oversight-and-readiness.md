# ADR 0011: Core platform P4/P5 — oversight and production readiness

Status: accepted 2026-10-25. Decided by the engineering lead under the project
owner's delegation (ADR 0005), choosing the fail-closed option where the
business rule was not stated. Builds on ADR 0010.

## Decisions

### 1. Data-quality issues sit on top of the existing checks

- **The checks.** `IntegrityChecks` stays the one check engine. It gains
  checks for:
  - an active card whose holder is not active;
  - an active card with a revoked credential;
  - a missing required photo or document;
  - an occupancy record without its placement;
  - an organization with two structural parents;
  - an ended organization still in the hierarchy.
- **Severity.** Each check has one fixed severity (`IntegrityChecks::SEVERITY`):
  - a broken identity or credential chain is critical;
  - a broken placement or structure rule is high;
  - what blocks a card or needs a decision is medium;
  - what is merely missing is low.
- **Not checked.** "Two current primary placements" is prevented by a unique
  index, so it is not a check.
- **The scan.** `DataQualityScanner` (`core:data-quality-scan`, hourly) keeps one
  issue per (check, record).
  - It reopens a resolved issue it finds again.
  - It never reopens an accepted risk or a false positive.
  - Because the checks are exact SQL, it resolves an issue it no longer finds,
    audited (setting `data_quality.auto_resolve`). It does not do this when a
    check hit its per-scan cap.
- **The workflow** (`ManageDataQualityIssues`): open → investigating → resolved
  or false positive (`data_quality.manage`), or accepted risk
  (`data_quality.accept_risk`). Closing needs a note.
- **Scope.** Issues carry the organization of their record and are seen only
  inside the reader's scope. An issue with no organization is seen city-wide
  only.

### 2. Reports: one catalog, the same query for screen and file

- **The catalog.** `ReportCatalog` defines the twenty Core reports. Each has:
  - the permission it needs;
  - whether it is city-wide only;
  - its filters;
  - its columns, with sensitive ones marked;
  - its query, limited to the reader's organizations.
- **One query.** The preview and the export run the same query, so an export
  can never reach more than the screen.
- **History.** Assignment history uses each placement's own organization and
  position (the structure of the time). "As of" selects the placements in
  force on a date. The hierarchy report takes any hierarchy version.
- **Sensitive columns** (phone, e-mail, date of birth) are left out unless the
  export asks for them. That needs `reports.export_sensitive` and a stated
  purpose, which is stored. A national ID is never in any report.
- **Exports are always queued** (`GenerateExport`). This is one path for small
  and large, so nothing large runs in a web request.
  - Above `reports.max_export_rows` an export is refused.
  - Files go to the private `exports` disk and are removed after
    `reports.export_retention_days` (`core:prune-exports`); the record stays.
  - Only the requester downloads.
  - Requesting, finishing, failing and downloading are each audited, with the
    filters, columns, row count and format.
  - Cells starting with `= + - @`, tab or carriage return are made text.
- **PDF** is a printable page (print or save as PDF in the browser, which has
  Ethiopic fonts). Core has no server PDF library. Server-side PDFs with
  embedded Amharic fonts are left for later rather than adding a rendering
  stack now.

### 3. Notifications come from the audit trail

- **Trigger.** `NotificationRouter::RULES` maps audit actions to an event and
  recipients. Recipients are holders of a permission whose scope covers the
  change's organization, or the people the record names, never the actor.
  Each audited change that matches queues one job after commit, so a
  notification cannot roll back or block the change.
- **In-app.** Notifications are stored as an event key and parameters
  (numbers and codes, no personal details) and written in the reader's
  language when shown. Templates are `lang/{en,am}/notifications.php`.
- **E-mail** is sent only when `notifications.email_enabled` is on, in the
  default language. A failure is recorded and never retried into a duplicate.
- **SMS.** Core has no SMS gateway. When `notifications.sms_enabled` is on,
  each attempt is recorded as "skipped — no gateway", never pretended.
- **Time-based notices.** `core:notify-expiring` (every five minutes) announces
  cards expiring within `notifications.card_expiry_notice_days` and
  break-glass grants ending within `notifications.break_glass_warning_minutes`.
  A failed audit verification writes `audit.verification_failed`, which
  becomes a security alert.

### 4. Dashboard KPIs

- **Scope.** The new KPIs are scoped like the old ones. City-wide-only figures
  (hierarchy approvals, webhooks, API errors, failed jobs, break-glass, MFA
  coverage) are hidden from scoped readers.
- **Caching.** Workload counts are cached per scope for 60 seconds. Security
  figures are always live.
- **Data-quality figure.** The figure comes from the issues table, never from
  running the checks on a page load. The performance review measured 5 to
  16 seconds for the checks at 300,000 employees.

### 5. System Health, backups, queues, observability, configuration

- **One service.** `SystemHealth` remains the only health service, for the API
  and the new screen. The screen shows healthy / warning / critical / unknown
  for ok / warning / error / unknown.
- **Backups** run outside the Core. Their tooling writes a JSON status file
  (`CORE_BACKUP_STATUS_FILE`) that `BackupStatus` only reads. With no file the
  status is "unknown — not integrated", never healthy. A success older than
  `CORE_BACKUP_MAX_AGE_HOURS` is a warning, twice that is critical, and a
  failure after the last success is critical. A missing or failed restore
  test is a warning.
- **Queue.**
  - The health check shows per-queue backlog, stuck jobs (reserved for more
    than 15 minutes), retrying jobs and failed jobs.
  - Failed jobs show only their job name and the first line of the error,
    never the payload.
  - Retry and remove use Laravel's own `queue:retry` and `queue:forget`, need
    `queue.manage`, and are audited.
- **Scheduler.** Every scheduled Core command records its run (also through
  `ScheduledTaskFinished`). A command never seen is "unknown".
- **Observability**, with no new infrastructure:
  - slow requests (over `CORE_SLOW_REQUEST_MS`) are logged with route and
    duration only, and counted per day;
  - background job durations and failures are counted per class;
  - API latency, error rates and verification latency come from the API call
    log.
- **Configuration.** `ConfigAudit` (`core:config-audit`, and the System Health
  screen) reviews the production settings, never showing secret values. It
  fails in production on APP_DEBUG, the HTTPS URL, secure, same-site and
  HTTP-only cookies, the session driver, a real queue and cache, enforced MFA,
  and mail when e-mail is on. It warns on separate credential and seal keys,
  backup integration, trusted proxies, log level and https CORS origins.
- **Sign-in audit.** Failed sign-ins and lockouts are audited for the security
  dashboard, with the e-mail kept only as a hash.

### 6. Settings

- **New groups**, merged into the General section like the earlier Core
  groups: integration (ADR 0010), notifications, reports, audit and
  data_quality. Every setting is validated, needs `system-settings.update`,
  is audited (`settings.updated`) and has a safe default. Every setting is
  read by code; none is decorative.
- **Where the other groups live:**
  - "Code rules" is numbering.
  - "Employee/Position rules" is workforce.
  - "MFA" is part of security.
  - "Card templates" is its own screen (ADR 0010).
  - "Environment" is read-only on System Health (configuration review), not
    editable settings.

### 7. API documentation

- **The file.** `core-platform/docs/openapi/core-integration-v1.yaml`
  (OpenAPI 3.1) documents the integration API that exists: ten routes,
  scopes, rate limits, pagination, errors, card and credential codes, event
  types and the webhook contract.
- **Kept honest.** `CoreOpenApiTest` fails when a route, an event type or a
  card code is added or removed without the file.
- **What it covers.** The staff API (`/api/v1/*` for Core accounts) is not part
  of the external contract and is not described there.

### 8. Calendar and language

- **Storage.** Stored and API dates stay canonical Gregorian/ISO.
- **Display.** `CoreDates` applies the screens' rule to exports and e-mail:
  Amharic shows the Ethiopian calendar, English the ISO date. It uses the
  EUISIS `EthiopianCalendarService`, copied unchanged into Core.
- **Exports.** An export follows the language of the page it was asked from:
  headings, value labels, title and dates.

### 9. Performance review (measured, not assumed)

The data set was synthetic, on PostgreSQL: 300,000 employees, 320,000
positions, 250,000 cards and credentials, 500,000 audit rows and 200,000 API
calls. Timings are the median of three runs, single user, on a developer
machine. Results:

- **Lists:**
  - employees: 37–48 ms;
  - positions: 31 ms;
  - assignments: 31 ms;
  - cards: 69 ms;
  - audit: 27 ms;
  - organization tree: 11 ms.
- **Search** by number or name: 168–214 ms.
- **Card check:** by UUID 2 ms; by token under 1 ms.
- **Report previews:** 35–95 ms. Card coverage: 721 ms, down from 1,207 ms
  after reshaping its subqueries.
- **Dashboard:** no longer runs the checks.
- **Data-quality scan:** 8.5 s, down from 122 s. Two changes did this:
  - one index, `employees (date_of_birth, lower(full_name))`, for the
    duplicate check (80 s → 8.6 s);
  - batched "seen again" updates.
  The over-capacity check was rewritten as grouped joins (7.7 s → 1.7 s).

No other index was justified by the plans. This is not a load test:
concurrency at production volume is still to be measured on production-like
hardware.

## Not done here

- Server-side PDF rendering with embedded fonts.
- An SMS gateway.
- External monitoring and alerting integration.
- Load testing at production concurrency.
- A restore drill.
- Human UAT with business users.
- Business sign-off.

Most of these are infrastructure or people, not code. They are tracked in the
readiness checklist (`docs/runbook/core-readiness-checklist.md`).
