# Addis One Security Audit Report

**Project:** Addis Ababa City Administration Addis Ababa Integrated Employee Management & Service System  
**Stack:** Laravel 12.58 / PHP 8.2 / Inertia.js / React TypeScript / PostgreSQL  
**Audit Date:** 2026-05-25  
**Auditor:** Senior Laravel Security Architect (automated + manual review)

---

## Executive Summary

Addis One has a well-structured security foundation built on Laravel Breeze, Spatie Permission (RBAC), a custom organisation-scope enforcement layer, and a comprehensive policy-per-model pattern. The authentication stack (CSRF, session regeneration on login, rate-limited login, proper logout invalidation) is correctly implemented. The API layer is protected with Sanctum tokens, ability-scoping, and custom provider-scope middleware with audit logging on denial.

Key strengths: layered authorization (permission + organisation scope), safe error rendering (no stack traces to end users), correlation-ID tracing, and a broad audit-log event bus.

**Outstanding gaps before production deployment:**

1. Security response headers were absent (now fixed — `SecurityHeaders` middleware added).
2. `APP_DEBUG=true` in `.env.example` defaulted developers toward an insecure baseline (now corrected to `false`).
3. `SESSION_SECURE_COOKIE` was not documented in `.env.example` (now added with production note).
4. No Content-Security-Policy header is defined — highest-impact remaining item.
5. The public registration route (`/register`) is active; for a closed government system this should be disabled or gated.
6. Password minimum strength (min 8 chars) is weak for a government identity system handling national IDs.
7. `SESSION_ENCRYPT=false` in `.env.example` — should be `true` in production.
8. Admin Multi-Factor Authentication is not implemented.
9. `npm audit` and `composer audit` could not be verified (network offline during audit).

---

## Audit Table

| Security Area | Current Status | Risk Level | Finding | Required Fix | Implemented? |
|---|---|---|---|---|---|
| **Authentication — Login** | Implemented | Low | `LoginRequest` uses `RateLimiter` (5 attempts), fires `Lockout` event, includes IP+email throttle key. Session regenerated on login. | None — correct. | N/A |
| **Authentication — Logout** | Implemented | Low | `Auth::guard('web')->logout()`, session invalidated, CSRF token regenerated. | None — correct. | N/A |
| **Authentication — Registration** | Partial | Medium | `RegisteredUserController` and `/register` route are active (Breeze default). For a closed government system, self-registration must be disabled or restricted to admin-only user creation. | Disable or gate `/register` to Super Admin only. | No — manual action required. |
| **Authentication — Password Strength** | Partial | Medium | Password rule is `required, string` with min 8 chars (Breeze default). No complexity requirements (uppercase, digit, symbol). | Add Laravel `Password::min(12)->mixedCase()->numbers()->symbols()` rule. | No — manual action required. |
| **Authentication — MFA** | Missing | High | No multi-factor authentication is implemented for any account, including Super Admin. | Implement TOTP MFA (e.g. `pragmarx/google2fa-laravel`) for at minimum Super Admin and City Admin roles. | No — manual action required. |
| **Authorization — RBAC** | Implemented | Low | Spatie Permission with `hasRole` / `can` checks. `Gate::before` grants Super Admin full access. All controllers call `$this->authorize()` or `Gate::authorize()`. | None. | N/A |
| **Authorization — Organisation Scope** | Implemented | Low | `OrganizationScopeService::canAccessEmployee` enforced in every policy method. `EnsureProviderServiceScope` middleware enforces it for the API layer. Denials are audit-logged. | None. | N/A |
| **Authorization — Policy Coverage** | Implemented | Low | 27 policies registered via `Gate::policy()` in `AppServiceProvider`. All key models are covered. | None. | N/A |
| **Session Security — httponly** | Implemented | Low | `http_only => env('SESSION_HTTP_ONLY', true)` — defaults correctly to `true`. | None. | N/A |
| **Session Security — same_site** | Implemented | Low | `same_site => env('SESSION_SAME_SITE', 'lax')` — correct default. | None. | N/A |
| **Session Security — secure cookie** | Partial | High | `secure => env('SESSION_SECURE_COOKIE')` — no default; resolves to `null` (false) if env var absent. `.env.example` now documents `SESSION_SECURE_COOKIE=false` with production note. | Set `SESSION_SECURE_COOKIE=true` in production `.env` with HTTPS enforced. | Partially (doc fix applied). |
| **Session Security — encryption** | Partial | Medium | `SESSION_ENCRYPT=false` in `.env.example`. Should be `true` in production. `.env.example` now documents this. | Set `SESSION_ENCRYPT=true` in production `.env`. | Partially (doc fix applied). |
| **Session Security — driver** | Implemented | Low | `SESSION_DRIVER=database` — server-side sessions. No sensitive data in cookie. | None. | N/A |
| **CSRF Protection** | Implemented | Low | Laravel's default `VerifyCsrfToken` middleware active on all web routes. Inertia sends `X-XSRF-TOKEN` automatically. | None. | N/A |
| **Data Protection — Passwords** | Implemented | Low | `password` cast to `hashed` in `User` model. `BCRYPT_ROUNDS=12` in `.env.example`. Hidden from serialization. | None. | N/A |
| **Data Protection — Sensitive Fields** | Partial | Medium | `national_id` and `phone_number` are stored in plain text in the `users` table. Employee `national_id` is also stored plain. At-rest encryption of PII is not implemented. | Use Laravel encrypted casts or column-level encryption for `national_id`, `phone_number`. | No — manual action required. |
| **Application Security — Injection** | Implemented | Low | All database queries use Eloquent ORM with parameter binding. No raw query interpolation found. | None. | N/A |
| **Application Security — XSS** | Implemented | Low | Inertia/React renders via JSX (auto-escaping). Laravel Blade not used for dynamic output. | None. | N/A |
| **Application Security — Security Headers** | Implemented (now) | Medium | No security headers were set before this audit. `SecurityHeaders` middleware now sets `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `X-XSS-Protection`. | No CSP header yet. | Yes — `SecurityHeaders` middleware added. |
| **Application Security — CSP** | Missing | High | No `Content-Security-Policy` header is defined. Mitigated somewhat by React/Inertia SPA model, but still a best-practice gap. | Add a CSP header to `SecurityHeaders` tailored to the Vite asset URLs. | No — manual action required. |
| **Application Security — Error Disclosure** | Implemented | Low | Custom exception handlers in `bootstrap/app.php` return localised error messages with correlation IDs only. Raw exception details never exposed in production (`config('app.debug')` check in catchall). | None. | N/A |
| **API Security — Authentication** | Implemented | Low | API routes use `auth:sanctum`. Sanctum token abilities checked via `can('provider:access')` or wildcard. | None. | N/A |
| **API Security — Rate Limiting** | Implemented | Low | `throttle:api` middleware; per-minute limit configurable via `SystemSettings` (default 120/min, min 30/min). Bound to authenticated user ID or IP. | None. | N/A |
| **API Security — Scope Enforcement** | Implemented | Low | `EnsureProviderServiceScope` middleware enforces provider/service-type scopes with audit logging. | None. | N/A |
| **File Storage — Profile Photos** | Partial | Medium | Profile photos stored at `public/storage/{path}` — publicly accessible by URL. Employee photos similarly accessible. No access control on stored files. | Move sensitive employee photos to a private disk with signed URL access, or restrict to authenticated requests only. | No — manual action required. |
| **Audit Logging** | Implemented | Low | `AuditLog` model with event types. `WriteAuditLogAction` called across providers, ID cards, transfers, and RBAC denials. `AuditLogPolicy` restricts access. | None. | N/A |
| **Rate Limiting — Login** | Implemented | Low | 5 attempts per email+IP. `Lockout` event fired. | None. | N/A |
| **Rate Limiting — API** | Implemented | Low | Configurable via system settings. | None. | N/A |
| **Dependency Security — Composer** | Unknown | Medium | `composer audit` failed: network offline during audit. Packages pinned at Laravel 12.58, Sanctum 4.x, Spatie Permission 6.21. All are current stable releases as of audit date. | Run `composer audit` with network access before deployment. | Pending network access. |
| **Dependency Security — npm** | Unknown | Medium | `npm audit` failed: network offline during audit. Packages include Vite 6, React 18, Axios 1.7, html2canvas 1.4, recharts 3.8. | Run `npm audit` with network access before deployment. | Pending network access. |
| **Production Config — APP_DEBUG** | Partial | Critical | `APP_DEBUG=true` was the default in `.env.example`. Now set to `false` in `.env.example`. Running instance shows `DEBUG: ENABLED` from `php artisan about`. | Ensure `.env` has `APP_DEBUG=false` before any production deployment. | Yes — `.env.example` corrected. |
| **Production Config — APP_ENV** | Partial | High | Current runtime: `Environment: local`. `.env.example` now documents production value. | Set `APP_ENV=production` in production `.env`. | Yes — `.env.example` annotated. |
| **Production Config — Config Cache** | Missing | Medium | `php artisan about` shows config, routes, and events are NOT cached. | Run `php artisan config:cache`, `route:cache`, `event:cache` before deployment. | No — deployment step. |
| **Infrastructure — HTTPS** | Not Applicable | High | Not enforced at application level. `force_https` system setting exists and calls `URL::forceScheme('https')` when enabled. | Configure HTTPS at load balancer/Nginx level. Set `force_https=true` in system settings for production. | No — infrastructure action. |
