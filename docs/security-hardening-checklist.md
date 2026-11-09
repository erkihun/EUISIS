# Addis One Security Hardening Checklist

**Project:** Addis One — Addis Ababa City Administration Addis Ababa Integrated Employee Management & Service System  
**Last updated:** 2026-05-25

Items marked [x] are already implemented. Items marked [ ] require action before or after production deployment.

---

## 1. Authentication

- [x] Login rate limiting: 5 attempts per email + IP (`app/Http/Requests/Auth/LoginRequest.php`)
- [x] `Lockout` event fired on rate-limit breach (`LoginRequest::ensureIsNotRateLimited`)
- [x] Session regenerated on successful login (`AuthenticatedSessionController::store`)
- [x] Session invalidated and CSRF token rotated on logout (`AuthenticatedSessionController::destroy`)
- [x] Passwords hashed with bcrypt rounds=12 (`.env.example`, `User` model cast)
- [x] Password reset tokens expire in 60 minutes (`config/auth.php`)
- [x] Password reset throttled (60-second cooldown per `config/auth.php`)
- [ ] **Disable or admin-gate the `/register` route** — self-registration must not be available in a closed government system (`routes/auth.php` — remove or wrap in Super Admin gate)
- [ ] **Strengthen password rules** — change `LoginRequest` and user creation/update requests to use `Password::min(12)->mixedCase()->numbers()->symbols()` (`app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Requests/` store/update requests)
- [ ] **Implement TOTP MFA** for Super Admin and City Admin roles (recommend `pragmarx/google2fa-laravel`; enforce via middleware on sensitive routes)
- [ ] **Account lockout notifications** — notify user by email when their account is rate-limited

---

## 2. Authorization & RBAC

- [x] Spatie Permission RBAC with 27 policies registered (`app/Providers/AppServiceProvider.php`)
- [x] `Gate::before` grants Super Admin unconditional access
- [x] Every policy enforces `OrganizationScopeService::canAccessEmployee` for resource-level checks (`app/Policies/`)
- [x] API routes enforce `EnsureProviderServiceScope` middleware with audit log on denial (`app/Http/Middleware/EnsureProviderServiceScope.php`)
- [x] `AuditLogPolicy` restricts audit log access to authorised users
- [ ] **Verify no `Gate::policy` is missing** — run `php artisan gate:list` after adding new models and ensure every model has a corresponding policy
- [ ] **Restrict Super Admin count** — enforce a maximum of 2-3 Super Admin accounts via seeder or database constraint; document the count in the admin runbook

---

## 3. Session Security

- [x] Session driver: `database` — server-side, no data in cookie (`config/session.php`)
- [x] `http_only = true` — JavaScript cannot read the session cookie (`config/session.php`)
- [x] `same_site = lax` — CSRF mitigation for cross-site requests (`config/session.php`)
- [ ] **Set `SESSION_SECURE_COOKIE=true` in production** — requires HTTPS to be active (`.env` production)
- [ ] **Set `SESSION_ENCRYPT=true` in production** — encrypts session data at rest in the database (`.env` production)
- [ ] **Set session lifetime** — default is 120 minutes; review whether this is appropriate for the role sensitivity; Super Admin sessions should consider shorter lifetimes via `SystemSettings` (`security.session_timeout_minutes`)

---

## 4. Security Headers

- [x] `X-Frame-Options: SAMEORIGIN` (`app/Http/Middleware/SecurityHeaders.php`)
- [x] `X-Content-Type-Options: nosniff` (`app/Http/Middleware/SecurityHeaders.php`)
- [x] `Referrer-Policy: strict-origin-when-cross-origin` (`app/Http/Middleware/SecurityHeaders.php`)
- [x] `Permissions-Policy: camera=(), microphone=(), geolocation=()` (`app/Http/Middleware/SecurityHeaders.php`)
- [x] `X-XSS-Protection: 1; mode=block` (`app/Http/Middleware/SecurityHeaders.php`)
- [ ] **Add Content-Security-Policy header** — add to `SecurityHeaders::handle()`; minimum policy: `default-src 'self'; script-src 'self' 'nonce-{vite_nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'` (adjust for Vite nonce support)
- [ ] **Add Strict-Transport-Security (HSTS)** — `Strict-Transport-Security: max-age=31536000; includeSubDomains` (add to `SecurityHeaders` once HTTPS is confirmed on production)

---

## 5. Data Protection & Encryption

- [x] `password` field uses `hashed` cast and is in `$hidden` (`app/Models/User.php`)
- [x] `remember_token` is in `$hidden` (`app/Models/User.php`)
- [x] Exception handlers never expose raw stack traces to end users (`bootstrap/app.php`)
- [x] Correlation IDs provided for incident tracing without exposing internals
- [ ] **Encrypt `national_id` and `phone_number` columns** — these are PII; use Laravel's `encrypted` cast on `User` model and `Employee` model for `national_id`, `phone_number`, `date_of_birth`
- [ ] **Database encryption at rest** — enable PostgreSQL transparent data encryption or filesystem encryption on the database volume
- [ ] **Encrypt backups** — all PostgreSQL dump files must be GPG-encrypted before transfer to backup storage

---

## 6. File Storage

- [ ] **Move employee photos to private disk** — currently stored under `public/storage/` and accessible without authentication; move to `local` or `s3` private disk and serve via signed URL through an authenticated controller
- [ ] **Validate upload MIME types server-side** — ensure file upload requests validate `mimes:jpg,jpeg,png,webp` and `max:2048` on the server in addition to the frontend hint
- [ ] **Prevent path traversal in file paths** — ensure all file path inputs are sanitised and resolved relative to storage root

---

## 7. API Security

- [x] All API routes require `auth:sanctum` (`routes/api.php`)
- [x] API routes have `throttle:api` with configurable rate limit (`app/Providers/AppServiceProvider.php`)
- [x] Provider scope enforced by `EnsureProviderServiceScope` middleware
- [x] Sanctum token abilities scoped to `provider:access`
- [ ] **Set Sanctum token expiration** — add `'expiration' => 60 * 24 * 30` (30 days) or shorter in `config/sanctum.php`; currently tokens do not expire
- [ ] **Audit all Sanctum token issuance points** — ensure tokens are issued only through a controlled, authenticated flow and are revocable

---

## 8. Dependency Management

- [ ] **Run `composer audit` before every deployment** (requires network access)
- [ ] **Run `npm audit --audit-level=moderate` before every deployment**
- [ ] **Update dependencies monthly** — pin minor versions in `composer.json` and run `composer update` on a schedule
- [ ] **Review `html2canvas` dependency** — `html2canvas` 1.4 is unmaintained; evaluate replacement with a server-side SVG-to-PNG pipeline (already partially implemented via `IdCardExportController`)

---

## 9. Logging & Monitoring

- [x] Application audit log via `AuditLog` model with event type enum
- [x] `ErrorLoggingService` logs unexpected exceptions with correlation ID
- [ ] **Set `LOG_LEVEL=warning` in production** — `LOG_LEVEL=debug` will write potentially sensitive request data to log files
- [ ] **Use `LOG_STACK=daily` in production** — rotate logs daily and retain for 30 days minimum (`config/logging.php` `days` setting)
- [ ] **Ensure logs are not world-readable** — `storage/logs/` must have `chmod 640` or tighter on the production server
- [ ] **Forward logs to a SIEM or centralised log system** — integrate with syslog, ELK, or a managed logging service for intrusion detection

---

## 10. Production Configuration

- [ ] Set `APP_ENV=production` in `.env`
- [ ] Set `APP_DEBUG=false` in `.env`
- [x] `config/app.php` defaults `debug` to `false` when env var is absent
- [ ] Run `php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache`
- [ ] Set `APP_URL` to the actual HTTPS domain
- [ ] Set `SESSION_SECURE_COOKIE=true`
- [ ] Set `SESSION_ENCRYPT=true`
- [ ] Set `LOG_LEVEL=warning`
- [ ] Verify `BCRYPT_ROUNDS=12` (already in `.env.example`)
- [ ] Remove `laravel/telescope` and `laravel/pail` from production if installed
