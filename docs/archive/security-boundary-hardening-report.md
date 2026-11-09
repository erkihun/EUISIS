# EUISIS — Security Boundary Hardening Report

Authorized security review, 2026-09-24. Follows
`security-assessment-report.md` (phase 1) and `security-assessment-phase2.md`
(phase 2). Findings cleared there are not repeated unless the code changed.

Scope: organization isolation, database roles and PostgreSQL RLS, frontend
secret exposure, storage, rate limiting and spend control, AI/model input,
logging, configuration. Every confirmed weakness has a regression test in
`tests/Feature/Security/BoundaryHardeningTest.php` unless stated otherwise.

---

# Executive Summary

| ID | Severity | Finding | Status |
|---|---|---|---|
| SBH-006 | **HIGH** | Two `.env` backups with `APP_KEY`, DB, Redis and mail credentials tracked in git and pushed to `origin/main` | **FIXED in repo — ROTATION_REQUIRED** |
| SBH-003 | MEDIUM | No application-wide SMS spend cap or usage metering | FIXED |
| SEC-013 | MEDIUM (reopened, was ACCEPTED-RISK) | Admin-uploaded employee photos on the public disk | FIXED (+ migration command) |
| SBH-001 | LOW | `/transport/scan` page had no authorization | FIXED |
| SBH-002 | LOW | Official seal / signature artwork downloadable by every signed-in user and shared on every page | FIXED |
| SBH-004 | LOW | SMS provider URL (possibly carrying a key) could be written to the error log | FIXED |
| SBH-005 | LOW | Contact-verification code posted under a field name the error logger does not redact | FIXED |
| SBH-007 | LOW | `CORS_ALLOWED_ORIGINS=*` with credentials would reflect any origin | FIXED (guard) |
| SEC-009 | MEDIUM (revised) | Guzzle advisories — a fixed release now exists | OPEN |
| SEC-010 | MEDIUM | External API has no per-application organization scope | NEEDS_DECISION |
| SEC-011 | LOW | `.env.example` ships `SESSION_SECURE_COOKIE=false` | FIXED (session pass: Secure by default over HTTPS; see `session-management.md`) |
| SBH-008 | INFORMATIONAL | PostgreSQL RLS not implemented; production DB roles unverified | NEEDS_DECISION |
| SEC2-005 | HIGH (phase 2) | Self-registration takeover | Verified **FIXED** (OTP possession proof) |

No cross-organization data access was found. No AI/model integration exists,
so the AI sections record that fact rather than findings.

---

# Row-Level Security

## Application-level scope (what enforces isolation today)

Chain verified on every surface reviewed:

```
authenticated user / application
  -> OrganizationScopeService (allowed organization ids; Super Admin / City Admin unrestricted)
  -> query constraint (applyOrganizationScope / DailyActivityCoverage / policy)
  -> object policy on the bound record (IDOR gate)
  -> resource / presenter (whitelisted fields)
```

Client-submitted ids only **narrow** a query that is already scoped; an id
outside scope returns 403 or matches nothing.

Surfaces re-verified in this pass (new or changed since phase 2):

| Surface | Scope mechanism | Evidence |
|---|---|---|
| Employee index filters | `canAccess()` on `organization_id` / unit; option lists scoped | `OrganizationalAdminScopeTest` (repaired, now green) |
| Daily Activity register, review, reports, exports | `DailyActivityCoverage` on the log's snapshot organization + policy | `DailyActivityModuleTest` 19, 20, 26 |
| Daily Activity evidence download | log `view` policy | `DailyActivityModuleTest` 20 |
| Employee self-service (profile, documents, requests, notifications, photo) | employee resolved from the account; no employee id accepted | `EmployeeSelfServiceTest`, `EmployeePortalTest` |
| Correction review, reprint queue | `applyOrganizationScope` on current assignment; `update` policy | code review |
| Print snapshot prepare/confirm | `print`/`reprint` policy inside the service (`canAccessEmployee`) | code review |
| Notifications feed / read | `$user->notifications()` only | `NotificationBellTest` |
| My Services (transport) | employee from the account | `EmployeePortalTest` |
| Admin pages by URL | route middleware / policy | **SBH-001 sweep** — 50+ parameter-free admin pages checked as a plain employee |

## PostgreSQL RLS — recommendation (SBH-008, NEEDS_DECISION)

Production targets PostgreSQL (`docs/infrastructure-security.md`); development
runs MySQL and the test suite SQLite. RLS policies therefore cannot be
exercised by this suite, and enabling untested policies risks either
silently empty pages or a false sense of protection. **RLS was not enabled.**

Classification:

| Table | Class | Reason |
|---|---|---|
| employees, employee_assignments | **B** app scope + RLS | core PII, scoped by current assignment |
| id_cards, id_card_print_snapshots | **B** | forgery-relevant, per organization |
| employee_documents | **B** | sensitive files metadata |
| daily_activity_logs / items / attachments | **B** | scoped by snapshot `organization_id` |
| employee_correction_requests | **B** | contains requested identity values |
| service_transactions, cafeteria/transport transactions | **B** | per employee / provider |
| organizations, organization_units, positions | **A** app scope | structure is read broadly by design |
| audit_logs | **A** | global audit readers exist by role |
| permissions, roles, system_settings, code_rules | **C** global/system | not organization data |

Design if adopted:

- Policies keyed on a transaction-local setting set per request with
  `SELECT set_config('app.org_ids', $1, true)` — **bound parameter**, never
  string concatenation; `true` = transaction-local, so pooled connections
  cannot carry one request's scope into the next.
- Deny by default: `USING (organization_id = ANY (string_to_array(current_setting('app.org_ids', true), ',')::uuid[]))`;
  a missing setting yields `NULL` → no rows.
- Unrestricted roles, queue workers and CLI use a separate DB role with
  `BYPASSRLS`, reached only through code paths that already authorize.
- Must be validated on a PostgreSQL staging copy with the existing scope
  tests re-pointed at it before production use.

---

# Organization Isolation

No IDOR/BOLA was found. The only unauthorized page (SBH-001) exposed
operational transport data, not another organization's employees. The
repaired `OrganizationalAdminScopeTest` again asserts that a scoped admin's
employee filter options contain only its own organization.

---

# Database Role Security (SBH-008)

Production database roles could not be inspected from this environment.
Required configuration:

| Role | Privileges |
|---|---|
| `euisis_app` (runtime) | `CONNECT`; `SELECT/INSERT/UPDATE/DELETE` on application tables; `USAGE` on sequences. **No** `SUPERUSER`, `BYPASSRLS`, `CREATEROLE`, `CREATEDB`, schema ownership or extension creation. |
| `euisis_migrate` (deploy only) | owns the schema; used by `php artisan migrate` in the pipeline, never by the web process |
| `euisis_report` (optional) | `SELECT` on reporting views only |
| `euisis_backup` | `pg_dump` read access; credentials stored outside the app server |

Verify with `\du` and `SELECT rolname, rolsuper, rolbypassrls FROM pg_roles;`.

---

# Frontend Secret Exposure

- Only `VITE_APP_NAME` reaches the bundle; `import.meta.env` is read for
  `DEV` and that name only.
- Built bundle (`public/build`): no server variable names, no key-shaped
  strings (AWS, private keys, `sk-`, Slack, Telegram bot tokens, `base64:`
  app keys), **no source maps**.
- Shared Inertia props: the user's own identity, role and permission names,
  an allowlisted settings set (`SharedBrandingPropTest`), flash. The one
  secret-bearing value, `flash.generated_token`, is the deliberate one-time
  API token display.
- Browser storage holds UI preferences and scan timestamps only; no tokens,
  national IDs or documents.
- **SBH-002**: `idCardTemplate(s)` (seal and signature URLs) was shared with
  every signed-in user; now only with accounts that render cards.

## SBH-006 — secrets committed to git (HIGH)

| Field | Detail |
|---|---|
| Component | repository root |
| Evidence | `.env.backup-appurl`, `.env.backup-mail` tracked since commit `19ab6b8`, present on `origin/main` (`github.com/erkihun/EUISIS`). Non-empty: `APP_KEY`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_USERNAME`, `MAIL_PASSWORD`. Values not reproduced here. |
| Attack scenario | Anyone with read access to the repository or a clone obtains the application key and service credentials. |
| Impact | `APP_KEY` forges signed URLs and session/cookie encryption and **decrypts encrypted columns including `national_id`**; DB/Redis/mail credentials give direct access if those services are reachable. |
| Root cause | `.gitignore` excluded only the exact names `.env`, `.env.backup`, `.env.production`. |
| Fix | Files removed from the index (`git rm --cached`, local copies kept); `.gitignore` now ignores `.env.*` except `.env.example`. |
| Regression test | `SBH-006 no environment file other than the example is tracked by git` |
| Status | **FIXED in repository — ROTATION_REQUIRED** |

**Required actions (not performed — they affect production and shared history):**

1. Rotate the DB, Redis and mail passwords.
2. Rotate `APP_KEY` using `APP_PREVIOUS_KEYS` so existing encrypted data
   (`national_id`, encrypted settings) stays readable, then re-encrypt.
3. Treat the old values as public even after a history rewrite; decide
   whether to purge them from history (`git filter-repo`) and force-push.
4. Commit the index change so the files leave `main`.

---

# Storage Security

| Disk | Class | Contents |
|---|---|---|
| `local` (`storage/app/private`, signed `serve`) | PRIVATE | employee photos (`employee-photos/`), daily activity evidence, transfer documents, change-request attachments, ID card template artwork, print snapshots, exports |
| `public` | PUBLIC | organization logos, system branding assets, public-site media, **legacy** employee photos until migrated |
| `s3` | configured, **unused** | no code path writes to it; no bucket policy to review |

**SEC-013 (reopened, FIXED).** Self-service photo uploads were already
private; admin uploads still wrote to the public disk, reachable at
`/storage/...` with no authentication. All photo writes now go through
`App\Support\EmployeePhotoStorage` to the private disk and are served only
through authorized routes. `php artisan employees:privatize-photos
[--dry-run]` moves existing public photos (record updated before the public
copy is deleted; re-runnable). **Run it once per environment.**

Downloads re-checked: every download endpoint authorizes the record (policy
or own-employee resolution) and checks path traversal and existence before
streaming with `nosniff` and `private, no-store`.

Uploads: sniffed-MIME allowlists and size caps on every upload; no SVG or
HTML accepted anywhere; stored names are random with MIME-derived
extensions.

Not verified: orphan-file cleanup and retention for soft-deleted records
(no lifecycle job exists; files of soft-deleted records are kept, which is
the safe default for audit).

---

# Rate Limiting

Existing, verified multi-dimensional limits (unchanged): login (per
email+IP), provider login, ID Checker send/verify (card+IP and IP),
registration OTP (employee number+IP and IP), contact verification (per
user), feedback (token+IP and IP), exports, API (per application/user).

Gap closed: **per-caller limits do not bound the total** — see SBH-003.

---

# Spend / Quota Controls

**SBH-003 (MEDIUM, FIXED).** SMS is the only paid external service. Card
UUIDs are printed on cards, so rotating IPs across many cards stayed under
every per-key limit while sending unlimited paid SMS.

Implemented:

- `external_service_usages` — one row per paid attempt: service, provider,
  purpose, user, **SHA-256 recipient hash** (no number, no message text),
  status, refusal reason.
- `ExternalUsageBudgetService` — `reserve()` checks global daily cap,
  monthly cap and per-recipient daily cap **under a cache lock** and writes a
  `reserved` row before the call, so parallel requests cannot all pass the
  same check; `settle()` marks sent/failed (failed attempts still count).
  Warns at 80% of the daily cap.
- `BudgetedSmsGateway` is now the bound `SmsGateway`, so registration OTP,
  the ID Checker and contact verification are all capped without caller
  changes; a refused send returns `false`, which every caller already
  handles as "not delivered".
- Config `security.external_usage.sms` (`SMS_ENABLED` kill switch,
  `SMS_DAILY_CAP=500`, `SMS_MONTHLY_CAP=10000`,
  `SMS_PER_RECIPIENT_DAILY_CAP=8`); `security:production-check` fails when
  caps are unset.
- Sends are synchronous (not queued), so queue retries cannot duplicate
  charges.

**Provider hard cap — required.** Configure a hard spending limit in the SMS
provider account where supported; the application cap does not protect
against a leaked provider key used outside EUISIS.

Not applicable: no AI, OCR, maps or paid verification APIs exist. Email is
sent through the configured mailer; no per-message cost model exists.

---

# AI / Model Input Security

**No AI or model integration exists.** Search of `app/`, `config/`,
`routes/`, `resources/js`, `composer.json` and `package.json` for provider
SDKs, clients and model/LLM/embedding usage found none; the only outbound
HTTP client is the SMS gateway. Sections on prompt injection, tool
authorization, model output handling, data minimisation, retrieval
scoping, prompt logging and AI response rendering have **no attack
surface today**.

Requirements for any future integration are recorded in "Remaining
Risks". Existing controls that would apply: every action is authorized
server-side by policies, and CMS/HTML output already passes
`SafeContentRenderer` (CommonMark `html_input=strip` + HTMLPurifier).

# Prompt Injection

Not applicable (no model integration). See above.

# Tool Authorization

Not applicable (no model integration). All state-changing actions are
authorized in backend policies and services, independent of any client.

---

# Logging / Secret Redaction

- **SBH-004 (FIXED)**: `HttpSmsGateway` logged `$exception->getMessage()`;
  HTTP connection errors echo the request URL, and the SMS API URL is an
  encrypted setting that may embed a key. Query strings are now stripped.
- **SBH-005 (FIXED)**: the new contact-verification form posted its OTP as
  `code`, which the error logger deliberately does not redact (it is also a
  business field). The endpoint now accepts only `otp`, which is redacted.
- Audit log redaction extended (defense in depth): `otp`, `otp_hash`,
  `code_hash`, `recovery_code(s)`, `access_token`, `refresh_token`,
  `plain_text_token`, `client_secret`, `authorization`, applied at any
  nesting depth. No current caller passes these; model `$hidden` lists
  already keep passwords, 2FA secrets and national IDs out of audit arrays.
- SMS logs mask numbers; OTP bodies are logged only in `local`/`testing`.

Errors: `APP_DEBUG=false` in `.env`; users see a reference id only
(`ERR-…`); `security:production-check` fails a production deploy with
debug on.

CORS: explicit origin list, empty by default. **SBH-007**: a `*` entry is
now dropped, because with `supports_credentials` the CORS layer would
reflect any origin.

CSP / security headers: unchanged, covered by `SecurityHeadersTest`.

Redis: configured with a password (value not reviewed); network exposure
is an infrastructure control (`docs/infrastructure-security.md`). Cache and
session stores are file/database in this environment.

---

# Findings

## SBH-001 — Transport scan page unauthorized (LOW) — FIXED
- **Evidence**: `GET /transport/scan` had no permission middleware; the POST required `transport-scan.create`.
- **Attack**: any signed-in account (including a plain employee) opens the URL.
- **Impact**: list of all active transport providers, routes and today's trip schedule.
- **Fix**: `can:transport-scan.create` on the page; sidebar gate aligned.
- **Test**: `SBH-001 a plain employee cannot open admin pages by URL` (sweeps every parameter-free admin GET page).

## SBH-002 — Card artwork exposed to every account (LOW) — FIXED
- **Evidence**: `IdCardTemplateController@background` allowed any authenticated user for the active template; `HandleInertiaRequests` shared `seal_url`/`signature_url` with everyone.
- **Impact**: official seal and signature originals (forgery material) to accounts with no card duties. Mitigated in practice because cardholders hold printed copies.
- **Fix**: requires `id-cards.view`/`cards.view` (active template) or `id_card_templates.view`; props shared only with those accounts.
- **Tests**: `SBH-002 …`; `IdCardTemplateManagementTest` updated to the new boundary.

## SBH-003 — Unbounded paid SMS (MEDIUM) — FIXED
See "Spend / Quota Controls". **Tests**: four `SBH-003` tests (binding, global cap with metering and no stored numbers, per-recipient cap, kill switch).

## SEC-013 — Employee photos on the public disk (MEDIUM) — FIXED
See "Storage Security". **Tests**: two `SEC-013` tests (private storage + authorized serving; legacy migration).

## SBH-004 — Provider URL in error log (LOW) — FIXED
**Test**: `SBH-004 an SMS provider key in the request URL never reaches the log`.

## SBH-005 — Contact OTP under an unredacted field (LOW) — FIXED
**Test**: `SBH-005 …`.

## SBH-006 — Secrets tracked in git (HIGH) — FIXED in repo, ROTATION_REQUIRED
See "Frontend Secret Exposure".

## SBH-007 — CORS wildcard with credentials (LOW) — FIXED
**Test**: `a wildcard CORS origin is ignored …`.

## SBH-008 — RLS and DB roles (INFORMATIONAL) — NEEDS_DECISION
See "Row-Level Security" and "Database Role Security".

## SEC-009 — Guzzle advisories (MEDIUM) — OPEN
`composer audit --locked`: 9 advisories on `guzzlehttp/guzzle` 7.10.0 (1
high, 8 medium; proxy/cookie handling). A fixed release now exists
(7.15.5). The narrowest update also moves `laravel/framework` 12.58 →
12.69 and Symfony patch releases, so it was not applied silently. Exposure
is limited: Guzzle only makes outbound calls to the configured SMS
provider. Apply with
`composer update guzzlehttp/guzzle guzzlehttp/psr7 guzzlehttp/promises -W`
followed by the full test suite. `npm audit --omit=dev`: 0 vulnerabilities.

---

# Fixes Applied

| Area | Files |
|---|---|
| SBH-001 | `routes/web.php`, `resources/js/Components/AppSidebar.tsx` |
| SBH-002 | `app/Http/Controllers/Web/IdCardTemplateController.php`, `app/Http/Middleware/HandleInertiaRequests.php` |
| SBH-003 | `database/migrations/2026_09_24_100000_create_external_service_usages_table.php`, `app/Services/Security/ExternalUsageBudgetService.php`, `app/Services/Sms/BudgetedSmsGateway.php`, `app/Providers/AppServiceProvider.php`, `config/security.php`, `.env.example`, `app/Console/Commands/SecurityProductionCheck.php` |
| SEC-013 | `app/Support/EmployeePhotoStorage.php`, `app/Http/Controllers/Web/EmployeeController.php`, `app/Services/Employees/EmployeeProfileUpdateService.php`, `app/Console/Commands/PrivatizeEmployeePhotos.php` |
| SBH-004 | `app/Services/Sms/HttpSmsGateway.php` |
| SBH-005 | `app/Http/Controllers/Employee/EmployeeSelfServiceController.php`, `app/Services/Employees/EmployeeContactVerificationService.php`, `resources/js/Components/employees/portal/ContactChange.tsx` |
| SBH-006 | `.gitignore`; `.env.backup-appurl`, `.env.backup-mail` untracked |
| SBH-007 | `config/cors.php` |
| Audit redaction | `app/Actions/Audit/WriteAuditLogAction.php` |

# Tests

New: `tests/Feature/Security/BoundaryHardeningTest.php` — 15 tests.
Updated to the tightened or current contract: `IdCardTemplateManagementTest`
(SBH-002), `OrganizationalAdminScopeTest` (stale prop names; boundary
unchanged), `EmployeeSelfServiceTest` (`otp` field).

Full suite: **2216 passed, 16 failed**. All 16 failures predate this review
and are unrelated to it: 3 `RegistrationTest` (fixture omits required
`first_name`), 13 ID-card rendering/layout tests. The two
`OrganizationalAdminScopeTest` failures present at the start are fixed.

`vendor/bin/pint` clean on changed files; `tsc --noEmit` clean;
`npm run build` succeeds; `php artisan route:list` — 768 routes.

# Remaining Risks

1. **SBH-006 rotation** — until credentials and `APP_KEY` are rotated, the
   committed values must be assumed usable.
2. **SEC-010** — any integration with `employees.basic_read` reads the
   whole directory.
3. **SEC-009** — Guzzle fix available but not applied.
4. **RLS / DB roles** — isolation relies on application code; production
   DB role privileges are unverified.
5. **SEC-011** — fixed in the session pass: the cookie is Secure by default
   whenever `APP_URL` is https (`docs/session-management.md`).
6. **Legacy public photos** remain public until
   `employees:privatize-photos` is run in each environment.
7. **External API quotas** — per-minute rate limit only; no daily quota per
   application (no paid backend cost is involved today).
8. **Future AI features** — if introduced: server-side keys only; untrusted
   content delimited as data; retrieval filtered by the same organization
   scope before any model call; all tool actions executed through existing
   policies; outputs sanitized (reuse `SafeContentRenderer`); prompts not
   logged; usage metered through `ExternalUsageBudgetService`.
