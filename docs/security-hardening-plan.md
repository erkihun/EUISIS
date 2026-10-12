# Security Hardening Plan

**Project:** Addis One  
**Date:** 2026-05-27  
**Review cycle:** Before each major release

This document lists concrete, prioritised hardening actions. Items marked ✅ are done; ❌ are open.

---

## Priority 1 — Critical (Before Go-Live)

### 1.1 Employee Photo Private Storage ❌

**Risk:** Employee photos are stored on the public disk (`storage/app/public`). Any user who guesses a photo path can access another employee's photo without authentication.

**Fix:**
1. Change photo upload storage to the `private` disk.
2. Create a signed URL route (`/employees/{employee}/photo`) protected by `EmployeePolicy::view`.
3. Run a one-time migration script to move existing photos.

**Files:** `app/Actions/Employees/RegisterEmployeeAction.php`, `app/Http/Controllers/Web/EmployeeController.php`, `config/filesystems.php`

---

### 1.2 Nonce-Based Content Security Policy ❌

**Risk:** The current CSP uses `'unsafe-inline'` for scripts, which reduces protection against injected scripts.

**Fix:**
1. Enable `Vite::useCspNonce()` in `AppServiceProvider`.
2. Pass the nonce to the Blade view via `@vite` with nonce support.
3. Update `SecurityHeaders.php` to use `script-src 'self' 'nonce-{nonce}'`.

**Reference:** [Laravel Vite CSP Nonce docs](https://laravel.com/docs/12.x/vite#content-security-policy-csp-nonce)

---

### 1.3 Hosting Security Agreement ❌

**Risk:** No formal hosting agreement documenting security responsibilities, breach handling, SLAs, and legal compliance.

**Fix:** See `docs/hosting-security-requirements.md` for the full checklist. Obtain signed agreement from hosting provider before production launch.

---

### 1.4 HTTP TRACE/TRACK Disabled ❌

**Risk:** HTTP TRACE method can be used for cross-site tracing (XST) attacks.

**Fix (Nginx):**
```nginx
if ($request_method = TRACE) {
    return 405;
}
```
Or: `limit_except GET POST PUT PATCH DELETE HEAD OPTIONS { deny all; }`

---

### 1.5 Default Seed Passwords Changed ❌

**Risk:** `UserSeeder` creates accounts with `Hash::make('password')`. If the seeder runs in production without password updates, accounts are compromised.

**Fix:** After first production deploy, immediately change all seeded account passwords and enable MFA. Add to `production-security-checklist.md`.

---

## Priority 2 — High (Sprint +1)

### 2.1 Employee Document Upload Audit ❌

Employee documents accept uploads. Verify:
- `mimes` validation is present
- `max` size limit is set
- Files stored on private disk
- No executable extensions allowed (`.php`, `.sh`, `.exe`, `.phtml`, `.js`)

**Files:** `app/Http/Requests/EmployeeDocumentUploadRequest.php` (verify existence)

---

### 2.2 Session Regeneration After Password Change ❌

After a user changes their password, the session should be regenerated to prevent session reuse attacks.

**Fix:** In `PasswordController::update()`, call `$request->session()->regenerate()` after the password is updated.

---

### 2.3 Content-Disposition Filename Sanitisation ❌

ID card PNG export routes return binary files with `Content-Disposition: attachment; filename="..."`. If a card number or employee name is interpolated directly into the filename, it could cause header injection.

**Fix:** Use `rawurlencode()` or limit the filename to alphanumeric characters only.

---

### 2.4 Per-Card Rate Limiting on Public Verification ❌

The public verification endpoint allows 30 requests/min per IP. An attacker can enumerate from different IPs. Add a per-card rate limiter (e.g., 10/min per `public_card_uuid`).

---

## Priority 3 — Medium (Sprint +2)

### 3.1 Automated SSL Certificate Expiry Monitoring ❌

Set up a monitoring check (e.g., `check_ssl_cert` via Nagios/Prometheus) to alert 30 days before certificate expiry.

### 3.2 Automated Audit Log Anomaly Alerting ❌

Configure a daily job to alert on:
- Unusual number of `login_failed` events
- Card access outside working hours
- High-frequency cafeteria scans from a single provider

### 3.3 robots.txt — Explicit Deny for Admin Paths ❌

Create `public/robots.txt`:
```
User-agent: *
Disallow: /admin/
Disallow: /api/
Disallow: /users/
Disallow: /audit-logs/
```
Note: `robots.txt` is a hint, not a security control.

### 3.4 Session Encryption in Production ❌

Set `SESSION_ENCRYPT=true` in production `.env`. This encrypts session data at rest in the database.

### 3.5 Dependency Vulnerability Scanning in CI ❌

Add to CI pipeline:
```yaml
- run: composer audit --no-dev
- run: npm audit --audit-level=moderate
```

---

## Already Implemented (This Audit)

| Item | Files Changed |
|---|---|
| Security headers (HSTS, CSP, COOP, CORP, X-Permitted-Cross-Domain-Policies) | `SecurityHeaders.php` |
| national_id gated behind `employees.viewPii` | `EmployeeDetailResource.php`, `EmployeePolicy.php` |
| Forgot-password rate limiting | `routes/auth.php` |
| Reset-password rate limiting | `routes/auth.php` |
| Security i18n keys (EN + AM) | `lang/en/security.php`, `lang/am/security.php`, `resources/js/i18n/` |
| Security test suite | `tests/Feature/Security/` |

---

*Owner: Security Lead*  
*Next review: 2026-08-27*
