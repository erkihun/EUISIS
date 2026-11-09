# Security Remediation Roadmap — EUISIS
**Generated:** 2026-06-02  
**Input:** `docs/security-gap-analysis.md`, `docs/security-score-report.md`  
**Owner:** Lead Developer + Security Lead

---

## Priority Levels
- **IMMEDIATE** — Must fix before production deployment; blocks go-live
- **HIGH** — Fix before first public/pilot use (within 2 weeks of deploy)
- **MEDIUM** — Fix within first 30 days of operation
- **OPERATIONAL** — Ongoing process improvements

---

## IMMEDIATE — Block Production

### REM-01: Fix Security Test Infrastructure (SQLite MODIFY COLUMN)
- **Gap reference:** GAP-M01
- **Risk level:** Critical — security controls unverifiable in CI
- **Files involved:**
  - Find the migration file in `database/migrations/` that contains `ALTER TABLE sessions MODIFY COLUMN`
  - Likely introduced when `user_id` column type was changed
- **Recommended fix:**
  ```php
  // Instead of raw MySQL ALTER, use Laravel schema builder:
  Schema::table('sessions', function (Blueprint $table) {
      $table->string('user_id', 36)->nullable()->change();
  });
  // Requires: composer require doctrine/dbal (or use native column modifiers in Laravel 11+)
  ```
  In Laravel 12 with native column modifiers (no Doctrine): `$table->uuid('user_id')->nullable()->change();`
- **Effort:** S (Small — 1-2 hours)
- **Acceptance:** `php artisan test --filter Security` passes with 0 failures

### REM-02: Confirm APP_DEBUG=false in Production .env
- **Gap reference:** C-02
- **Risk level:** Critical — debug mode leaks stack traces, file paths, SQL
- **Files involved:** Production `.env` file (not committed to git)
- **Recommended fix:** Set `APP_DEBUG=false` and `APP_ENV=production` in production `.env`; add to deployment checklist
- **Effort:** S
- **Acceptance:** `php artisan about` shows `Debug Mode ... NOT ENABLED`

### REM-03: Restore or Verify Deleted Transfer Action Files
- **Gap reference:** GAP-I01
- **Risk level:** High — 7 authorization/business-rule Action files deleted; transfer workflow state machine may be broken
- **Files involved:**
  - `app/Actions/Transfers/ApproveEmployeeTransferAction.php` (deleted)
  - `app/Actions/Transfers/CancelEmployeeTransferAction.php` (deleted)
  - `app/Actions/Transfers/CompleteEmployeeTransferAction.php` (deleted)
  - `app/Actions/Transfers/ConfirmCurrentOrganizationTransferAction.php` (deleted)
  - `app/Actions/Transfers/ConfirmReceivingOrganizationTransferAction.php` (deleted)
  - `app/Actions/Transfers/RejectEmployeeTransferAction.php` (deleted)
  - `app/Actions/Transfers/SubmitEmployeeTransferAction.php` (deleted)
- **Recommended fix:** If these are replaced by new actions (e.g., under `app/Actions/Vacancy/`), document the replacement. If not replaced, restore from `git stash` or prior commit and ensure each action: (a) checks authorization via Policy, (b) writes an audit log entry, (c) enforces state machine transitions
- **Effort:** M (Medium — depends on whether replacement exists)
- **Acceptance:** All transfer workflow tests pass; audit logs written on each transition

---

## HIGH — Fix Before Pilot Use

### REM-04: Create config/cors.php with Restrictive Origins
- **Gap reference:** GAP-D02, GAP-E01
- **Risk level:** High — API cross-origin requests unrestricted
- **Files involved:** Create `config/cors.php`; verify `bootstrap/app.php` loads `HandleCors`
- **Recommended fix:**
  ```php
  // config/cors.php
  return [
      'paths' => ['api/*', 'sanctum/csrf-cookie'],
      'allowed_methods' => ['GET', 'POST'],
      'allowed_origins' => [env('APP_URL', 'https://euisis.addisababa.gov.et')],
      'allowed_origins_patterns' => [],
      'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization'],
      'exposed_headers' => [],
      'max_age' => 0,
      'supports_credentials' => true,
  ];
  ```
- **Effort:** S
- **Acceptance:** Cross-origin request from non-whitelisted origin returns 403

### REM-05: Add File Upload Validation (MIME type + size)
- **Gap reference:** GAP-D03, GAP-F02
- **Risk level:** High — unrestricted file upload
- **Files involved:**
  - Identify upload fields in `app/Http/Controllers/Web/EmployeeController.php`
  - `app/Http/Controllers/ProfileController.php`
  - `app/Http/Controllers/Web/UserController.php`
  - Create or update corresponding Form Request classes
- **Recommended fix:**
  ```php
  // In the relevant Form Request authorize() and rules():
  'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
  'signature_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:512'],
  ```
- **Effort:** S
- **Acceptance:** Upload of `.php`, `.svg`, `.exe` files rejected with 422

### REM-06: Reduce Sanctum Token TTL
- **Gap reference:** GAP-A03
- **Risk level:** High — 30-day tokens too long-lived for service devices
- **Files involved:** `config/sanctum.php`, `.env.example`
- **Recommended fix:** Reduce to 8 hours (480 minutes) for service provider API tokens; implement token refresh endpoint or cron-based rotation
  ```
  # .env.example
  SANCTUM_TOKEN_EXPIRATION_MINUTES=480
  ```
- **Effort:** S
- **Acceptance:** API tokens expire after 8 hours; provider devices re-authenticate daily

### REM-07: Enable Session Encryption and Secure Cookie in Production
- **Gap reference:** GAP-A01, GAP-A02
- **Risk level:** High
- **Files involved:** Production `.env`, `.env.example` comments
- **Recommended fix:**
  ```
  SESSION_ENCRYPT=true
  SESSION_SECURE_COOKIE=true  # only when HTTPS is active
  SESSION_SAME_SITE=strict    # upgrade from lax for government portal
  ```
- **Effort:** S
- **Acceptance:** Session cookie has `Secure; HttpOnly; SameSite=Strict` flags in prod response headers

### REM-08: Add PII Redaction to WriteAuditLogAction
- **Gap reference:** GAP-C03
- **Risk level:** High — PII may be stored in audit logs
- **Files involved:** `app/Actions/Audit/WriteAuditLogAction.php`
- **Recommended fix:**
  ```php
  private const SENSITIVE_KEYS = ['national_id', 'national_id_hash', 'password', 'two_factor_secret',
      'two_factor_recovery_codes', 'phone_number', 'remember_token'];

  private function redact(?array $values): ?array {
      if ($values === null) return null;
      return array_map(fn($k, $v) => in_array($k, self::SENSITIVE_KEYS, true) ? '[REDACTED]' : $v,
          array_keys($values), $values);
      // or: return collect($values)->map(fn($v, $k) => in_array($k, self::SENSITIVE_KEYS) ? '[REDACTED]' : $v)->all();
  }
  ```
  Apply `$this->redact($oldValues)` and `$this->redact($newValues)` before persisting.
- **Effort:** S
- **Acceptance:** Audit log entries for employee update do not contain plaintext national_id

---

## MEDIUM — Fix Within 30 Days

### REM-09: Implement Nonce-Based CSP (Remove `unsafe-inline`)
- **Gap reference:** GAP-D04
- **Risk level:** Medium
- **Files involved:** `app/Http/Middleware/SecurityHeaders.php`, `resources/views/app.blade.php`, `vite.config.js`
- **Recommended fix:**
  1. Call `Vite::useCspNonce()` in `app.blade.php`
  2. Read the nonce in `SecurityHeaders.php` via `Vite::cspNonce()`
  3. Replace `'unsafe-inline'` with `'nonce-{$nonce}'` in `script-src`
  4. Add `@nonce` attribute to any `<script>` tags in blade files
- **Effort:** M
- **Acceptance:** CSP header contains `nonce-` instead of `unsafe-inline`; Lighthouse CSP score improves

### REM-10: Replace `dangerouslySetInnerHTML` in Pagination Components
- **Gap reference:** GAP-M01 (listed as M-01 in score report)
- **Risk level:** Medium
- **Files involved:**
  - `resources/js/Pages/HierarchyVersions/Index.tsx:288`
  - `resources/js/Pages/Transfers/Announcements/Index.tsx:315`
- **Recommended fix:** Create a `PaginationLink` component that decodes `&laquo;`/`&raquo;` HTML entities safely using `he` library or a simple decode function instead of raw HTML injection
- **Effort:** S
- **Acceptance:** No `dangerouslySetInnerHTML` in codebase (`grep -rn dangerouslySetInnerHTML resources/ --include="*.tsx"` returns 0)

### REM-11: Move Profile/Employee Photos to Private Disk with Authorization
- **Gap reference:** GAP-C02
- **Risk level:** Medium
- **Files involved:** `app/Models/User.php:116`, `app/Models/Employee.php:78`; `app/Http/Controllers/Web/EmployeeController.php`
- **Recommended fix:**
  ```php
  // Store to private disk
  $path = $request->file('photo')->store('employee-photos', 'local');
  // Serve via authorized controller endpoint
  public function photo(Employee $employee): Response {
      $this->authorize('view', $employee);
      return Storage::disk('local')->response($employee->photo_path);
  }
  ```
- **Effort:** M
- **Acceptance:** `/storage/employee-photos/xxx.jpg` returns 404; authorized photo endpoint returns image

### REM-12: Fix `CalendarService::formatDate` Unit Test
- **Gap reference:** L-05 in score report
- **Risk level:** Low
- **Files involved:** `tests/Unit/EthiopianCalendarServiceTest.php:130` or `app/Services/Calendar/CalendarService.php`
- **Recommended fix:** Either fix the test expectation to match the actual ISO format returned, or fix `formatDate('en')` to always return ISO-8601 for `en` locale
- **Effort:** S
- **Acceptance:** `php artisan test --filter EthiopianCalendarServiceTest` shows 0 failures

### REM-13: Add Sanctum Token Prefix
- **Gap reference:** GAP-E02
- **Risk level:** Low
- **Files involved:** `.env.example`, production `.env`
- **Recommended fix:** `SANCTUM_TOKEN_PREFIX=euisis_`
- **Effort:** S
- **Acceptance:** New tokens begin with `euisis_` prefix

### REM-14: Use sessionStorage Instead of localStorage for Scan History
- **Gap reference:** GAP-C04
- **Risk level:** Medium (shared terminal risk)
- **Files involved:** `resources/js/Pages/Cafeteria/Scan.tsx:232-250`
- **Recommended fix:** Replace `localStorage` with `sessionStorage` for `SCAN_HISTORY_KEY`, ensuring data is cleared when the browser tab is closed; add explicit `sessionStorage.removeItem(SCAN_HISTORY_KEY)` on logout
- **Effort:** S
- **Acceptance:** Scan history does not persist across browser sessions on shared terminals

### REM-15: Expand MFA to Additional Privileged Roles
- **Gap reference:** GAP-A04
- **Risk level:** Medium
- **Files involved:** `.env.example`, `config/security.php`
- **Recommended fix:**
  ```
  MFA_REQUIRED_ROLES="Super Admin,City Admin,HR Manager,Cafeteria Admin,Security Officer"
  ```
- **Effort:** S
- **Acceptance:** Users with HR Manager role are challenged by `RequireMfa` middleware

---

## OPERATIONAL — Ongoing

### REM-OP01: Implement Audit Log Retention Job
- **Gap reference:** GAP-G02
- **Recommended action:** Create `app/Console/Commands/PurgeOldAuditLogs.php` that deletes entries older than 2 years; schedule in `routes/console.php` as monthly job
- **Effort:** S

### REM-OP02: Add Composer Audit to CI Pipeline
- **Gap reference:** Composer audit could not run (network timeout in audit)
- **Recommended action:** Add `composer audit --no-interaction` step to CI workflow (GitHub Actions or equivalent); fail build on high/critical advisories
- **Effort:** S

### REM-OP03: Document Audit Log Retention Policy
- **Recommended action:** Add retention policy to `docs/backup-and-disaster-recovery.md`
- **Effort:** S

### REM-OP04: Add Disk Usage Guidelines to Architecture Docs
- **Gap reference:** GAP-F03
- **Recommended action:** Update `docs/architecture/security.md` with section: "Always use the `local` (private) disk for PII files; use `public` disk only for non-sensitive assets"
- **Effort:** S

### REM-OP05: Schedule Regular Penetration Testing
- **Recommended action:** Commission external pentest before production launch and annually thereafter; focus areas: QR replay, provider portal isolation, transfer workflow authorization
- **Effort:** L

---

## Remediation Summary

| Priority | Count | Estimated Total Effort |
|---|---|---|
| IMMEDIATE | 3 | ~1 day |
| HIGH | 5 | ~2 days |
| MEDIUM | 7 | ~3-4 days |
| OPERATIONAL | 5 | Ongoing |
| **TOTAL** | **20** | **~1 week sprint** |
