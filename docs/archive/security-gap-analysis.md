# Security Gap Analysis — EUISIS
**Generated:** 2026-06-02  
**Methodology:** File inspection, grep pattern analysis, test execution  
**Reference:** `docs/security-score-report.md` for category scores

---

## 1. Authentication & Session Security

### GAP-A01 — Session encryption disabled in .env.example
- **Found:** `SESSION_ENCRYPT=false` in `.env.example:43`; `config/session.php:50` reads from env
- **Missing:** Production deploy checklist does not enforce this value; default leaves session data readable on disk
- **Risk:** If database session table is compromised, session data is readable in plaintext
- **Fix file:** `.env.example` + production `.env`; add to `docs/production-security-checklist.md`

### GAP-A02 — SESSION_SECURE_COOKIE defaults false
- **Found:** `.env.example:47` `SESSION_SECURE_COOKIE=false`
- **Missing:** No server-level HTTPS redirect enforced at application layer (depends on nginx/load balancer config)
- **Risk:** Session cookie transmitted over HTTP if HTTPS not enforced at infrastructure layer, enabling session hijacking
- **Fix file:** `.env.example` + nginx/Apache config

### GAP-A03 — Sanctum token TTL 30 days
- **Found:** `config/sanctum.php:53` `'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 60 * 24 * 30)`; `.env.example:61` `SANCTUM_TOKEN_EXPIRATION_MINUTES=43200`
- **Missing:** No token rotation mechanism or refresh endpoint in `routes/api.php`
- **Risk:** A stolen API token remains valid for 30 days; service provider devices that are lost/stolen remain authenticated for a month
- **Fix file:** `config/sanctum.php`, `.env.example`

### GAP-A04 — MFA only enforced for Super Admin and City Admin roles
- **Found:** `config/security.php:32-33` defaults `MFA_REQUIRED_ROLES` to "Super Admin,City Admin"
- **Missing:** Roles such as "HR Manager" or "Cafeteria Admin" that have access to employee PII are not required to use MFA
- **Risk:** Privileged users below the two top roles can be phished without a second factor
- **Fix file:** `config/security.php`, `.env.example` — expand `MFA_REQUIRED_ROLES`

---

## 2. Authorization & Access Control

### GAP-B01 — `EnsureAdminAccess` only checks `user_type` field
- **Found:** `app/Http/Middleware/EnsureAdminAccess.php:22` — checks `$user->user_type === 'provider'` only
- **Missing:** Does not check active status, role validity, or organization scope at the middleware level; relies on downstream policies
- **Risk:** A user with `user_type != 'provider'` but no assigned roles reaches admin routes; policies are the last line of defense
- **Fix file:** `app/Http/Middleware/EnsureAdminAccess.php` — add `isActive()` check

### GAP-B02 — `cafeteria/portal` redirect routes lack real auth guard
- **Found:** `routes/web.php:175-187` — `cafeteria.portal.*` routes use `Route::prefix('cafeteria/portal')` with no `auth:` middleware; they only redirect
- **Missing:** A crafted POST to `cafeteria/portal/login` hits the redirect closure without any guard
- **Risk:** Low — redirects are harmless — but an attacker could discover legacy paths and try to craft bypass attempts
- **Fix file:** `routes/web.php` — add `->middleware('guest')` to redirect group or remove entirely

### GAP-B03 — No `viewAny` scope enforcement on bulk export routes
- **Found:** Provider transaction export routes in `routes/web.php:110-116` are inside the `auth:provider` group but rely on `CafeteriaTransactionPolicy::exportProviderTransactions`
- **Missing:** Bulk CSV/XLSX exports could include cross-provider data if provider scope is misconfigured
- **Risk:** Data leakage across provider accounts if `CafeteriaProviderAccessService::canAccessProvider` has a bug
- **Fix file:** `app/Http/Controllers/ProviderPortal/ProviderTransactionController.php` — add explicit provider-scoped query scope

---

## 3. Data Protection & Privacy

### GAP-C01 — Employee `national_id` uses custom accessor, not Laravel `encrypted` cast
- **Found:** `app/Models/Employee.php:58-74` — uses `getNationalIdAttribute()` / `setNationalIdAttribute()` custom accessor pair
- **Missing:** Laravel native `'national_id' => 'encrypted'` cast would handle encrypt/decrypt transparently; custom accessor is correct but deviates from the pattern used in `User.php:75`
- **Risk:** Low — functional behavior is equivalent; risk is maintainability and potential for future PRs to bypass the accessor
- **Fix file:** `app/Models/Employee.php` — migrate to Laravel `encrypted` cast

### GAP-C02 — Profile photos served via public storage without authorization
- **Found:** `app/Models/User.php:116` returns `'/storage/'.$this->profile_photo_path`; `app/Models/Employee.php:78` returns `'/storage/'.$this->photo_path`
- **Missing:** Photos accessible to anyone who knows the UUID-based filename; no signed URL or auth gate on `/storage/` path
- **Risk:** Moderate — employee photos (PII) are publicly accessible by filename enumeration if UUID is predictable
- **Fix file:** Move profile photos to private disk; serve via signed temporary URL from `IdCardExportController` pattern

### GAP-C03 — Audit log may record PII in old/new values
- **Found:** `app/Actions/Audit/WriteAuditLogAction.php:30-31` — `$oldValues` and `$newValues` stored as-is
- **Missing:** No redaction of sensitive keys (e.g., `national_id`, `password`, `two_factor_secret`) before persisting
- **Risk:** If a caller passes plaintext `national_id` in old/new values, PII is persisted in the audit log in plaintext
- **Fix file:** `app/Actions/Audit/WriteAuditLogAction.php` — add `$sensitiveKeys = ['national_id', 'password', 'two_factor_secret', 'phone_number']` redaction step

### GAP-C04 — Cafeteria scan history stored in localStorage
- **Found:** `resources/js/Pages/Cafeteria/Scan.tsx:236,250` — scan history persisted to `localStorage`
- **Missing:** No expiry enforcement at write time (only 30-day filter on read); shared cafeteria terminal users could read each other's scan history
- **Risk:** On shared kiosk/terminal devices, employee scan records visible to next user
- **Fix file:** `resources/js/Pages/Cafeteria/Scan.tsx` — use `sessionStorage` or server-side history only; add explicit clear on logout

---

## 4. Application Security

### GAP-D01 — `dangerouslySetInnerHTML` in pagination components
- **Found:**
  - `resources/js/Pages/HierarchyVersions/Index.tsx:288`
  - `resources/js/Pages/Transfers/Announcements/Index.tsx:315`
  - Both render `link.label` from Laravel pagination JSON
- **Missing:** `link.label` is generated server-side by Laravel's `LengthAwarePaginator` and contains `&laquo;` and `&raquo;` HTML entities for previous/next — not user-supplied, but still a pattern to eliminate
- **Risk:** Low in current form (server-controlled strings); risk increases if custom paginator label is ever rendered
- **Fix file:** Replace with React component that uses `dangerouslySetInnerHTML` only for explicitly trusted HTML, or decode entities client-side

### GAP-D02 — Missing CORS configuration
- **Found:** `config/cors.php` does not exist; `config/` directory listing confirms absence
- **Missing:** Laravel's built-in CORS middleware (`Illuminate\Http\Middleware\HandleCors`) is likely active with defaults that allow all origins on API routes
- **Risk:** Any website can make credentialed cross-origin API requests to EUISIS API if CORS defaults are permissive
- **Fix file:** Create `config/cors.php` with `allowed_origins` restricted to production domain; verify `bootstrap/app.php` registers `HandleCors`

### GAP-D03 — No file upload validation (MIME/size)
- **Found:** No MIME type or file size rules found across `app/Http/Requests/` for profile photo or employee photo upload
- **Missing:** Rules like `'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048']`
- **Risk:** Unrestricted file uploads could allow SVG with embedded scripts, oversized files causing DoS
- **Fix file:** Identify upload endpoints and add validation (likely in `EmployeeController`, `ProfileController`, `UserController`)

### GAP-D04 — CSP uses `'unsafe-inline'` in script-src
- **Found:** `app/Http/Middleware/SecurityHeaders.php:66` — `"script-src 'self' 'unsafe-inline'"`; comment on line 61 acknowledges this as a hardening TODO
- **Missing:** Nonce-based CSP using `Vite::useCspNonce()`
- **Risk:** XSS vulnerabilities can be exploited via inline script injection; `'unsafe-inline'` defeats the protective purpose of CSP's script-src
- **Fix file:** `app/Http/Middleware/SecurityHeaders.php`, `resources/views/app.blade.php`, `vite.config.js`

---

## 5. API & Service Security

### GAP-E01 — CORS policy absent for API routes
- **Found:** `routes/api.php:13` — API group uses `auth:sanctum` and `throttle:api` but no CORS middleware is explicitly applied
- **Missing:** Without `config/cors.php`, Laravel uses framework defaults (all origins allowed)
- **Risk:** Provider API endpoints (`/api/v1/*`) accessible cross-origin from attacker-controlled websites
- **Fix file:** Create `config/cors.php`; restrict `allowed_origins` and `allowed_methods`

### GAP-E02 — No SANCTUM_TOKEN_PREFIX set
- **Found:** `config/sanctum.php:68` `'token_prefix' => env('SANCTUM_TOKEN_PREFIX', '')`
- **Missing:** Token prefix enables GitHub and other platforms to auto-detect committed tokens
- **Risk:** Low operational impact; risk is that a developer accidentally committed token goes undetected
- **Fix file:** `.env.example` — set `SANCTUM_TOKEN_PREFIX=euisis_`

---

## 6. File Upload & Storage Security

### GAP-F01 — No signed or authorized file download mechanism
- **Found:** No `Storage::temporaryUrl()`, `temporarySignedRoute()`, or `response()->download()` calls found in app
- **Missing:** Export files (PDFs, PNGs, CSVs) are streamed directly from controller responses inside auth middleware — this is acceptable but means no download token/expiry mechanism
- **Risk:** Download links do not expire; if a URL leaks it remains valid as long as the session is valid
- **Fix file:** Consider `temporarySignedRoute()` for export endpoints if future requirements mandate link sharing

### GAP-F02 — No MIME/size validation on uploaded files
- **Duplicates GAP-D03** — see above

### GAP-F03 — Default disk is `local` (private) but public disk symlink exists
- **Found:** `config/filesystems.php:33` local disk root is `storage/app/private`; `storage/app/public` symlinked to `public/storage`
- **Missing:** Documentation/enforcement of which paths go to which disk; a developer could accidentally use `Storage::disk('public')` for sensitive files
- **Risk:** Low — config is correct; risk is human error in future development
- **Fix file:** `docs/architecture/security.md` — add disk usage guidelines

---

## 7. Audit Logging & Accountability

### GAP-G01 — PII redaction in audit log old/new values
- **Covered in GAP-C03** — see above

### GAP-G02 — No audit log retention policy enforcement
- **Found:** `docs/backup-and-disaster-recovery.md` covers DB backup but does not specify audit log retention period
- **Missing:** Policy for how long audit logs are retained, when they are purged, and who can access them
- **Risk:** Unlimited growth of audit log table; potential GDPR/data minimization compliance issue
- **Fix file:** Add audit log retention section to `docs/backup-and-disaster-recovery.md`; implement scheduled `AuditLog::where('created_at', '<', now()->subYears(2))->delete()` job

---

## 8. Provider & Cafeteria Security

### GAP-H01 — `cafeteria/portal` redirect routes have no guard
- **Covered in GAP-B02** — see above

### GAP-H02 — QR replay prevention not visible in code review
- **Found:** `app/Services/Cafeteria/CafeteriaQrScanService.php` exists; `throttle:60,1` on scan route
- **Missing:** Explicit idempotency check (e.g., `SELECT ... WHERE scanned_at > now()-30sec`) not confirmed; need to verify `CafeteriaQrScanService` prevents double-scan within same day window
- **Risk:** If replay prevention is absent, an employee QR code could be scanned twice on the same day, producing a double-subsidy record
- **Fix file:** Review `CafeteriaQrScanService.php` for idempotency window

---

## 9. Employee Transfer/Vacancy Security

### GAP-I01 — 7 Transfer Action files deleted
- **Found:** `git status` shows `D app/Actions/Transfers/ApproveEmployeeTransferAction.php`, `CancelEmployeeTransferAction.php`, `CompleteEmployeeTransferAction.php`, `ConfirmCurrentOrganizationTransferAction.php`, `ConfirmReceivingOrganizationTransferAction.php`, `RejectEmployeeTransferAction.php`, `SubmitEmployeeTransferAction.php`
- **Missing:** The transfer approval workflow actions that enforce business rules and authorization checks during state transitions
- **Risk:** If these actions have been replaced by new code (e.g., under `app/Actions/Vacancy/`), the risk is low; if they were deleted without replacement, state transition security may be bypassed
- **Fix file:** Verify replacement actions exist and cover same authorization + audit logic before committing deletion

---

## 10. Testing Infrastructure

### GAP-M01 — Security tests fail to execute (SQLite MODIFY COLUMN)
- **Found:** All tests in `tests/Feature/Security/` throw `SQLSTATE[HY000]: General error: 1 near "MODIFY": syntax error`
- **Root cause:** A migration uses MySQL-specific `MODIFY COLUMN` syntax which SQLite does not support; the `sessions` table migration tries `ALTER TABLE sessions MODIFY COLUMN user_id VARCHAR(36) NULL`
- **Missing:** Migration compatibility check; the fix is to use `$table->string('user_id', 36)->nullable()->change()` with Doctrine DBAL or a separate SQLite-compatible migration
- **Risk:** CI cannot verify any security control. A regression in `RequireMfa`, `SecurityHeaders`, or authorization policies would not be caught
- **Fix file:** The failing migration — likely in `database/migrations/` — find the one altering `sessions.user_id` and fix syntax
