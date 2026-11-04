# INSA Secure Website Management Standard — Compliance Audit

**Project:** EUISIS — Addis Ababa City Administration Employee Unified ID & Service Integration System  
**Standard:** INSA Secure Website Management Standard Version 1.0, 2014 EC  
**Auditor:** Senior Security Architect  
**Audit Date:** 2026-05-27  
**Review Cycle:** Quarterly

---

## Executive Summary

EUISIS is a government-critical Laravel 12 / React / Inertia v2 platform handling encrypted PII (national IDs, phone numbers), digital ID cards with QR codes, RBAC, multi-factor authentication, and a cafeteria subsidy module. This audit maps the system against all five phases of the INSA standard and both annexes. Overall posture is **GOOD** for a pre-production system; critical gaps are in hosting documentation and formal operational procedures.

| Phase | Status | Risk Level |
|---|---|---|
| Requirement Gathering & Analysis | Partial | Medium |
| Design | Implemented | Low |
| Implementation | Partial | Medium |
| Hosting | Documentation Only | High |
| Operation & Management | Documentation Only | High |

---

## Detailed Audit Table

| INSA Area | Requirement / Test | Current Status | Risk | Evidence | Required Fix | Implemented? | Notes |
|---|---|---|---|---|---|---|---|
| **Implementation** | All protected routes use `auth` middleware | Implemented | — | `routes/web.php` L61: `Route::middleware(['auth','verified','mfa'])` | — | ✅ | Profile routes use `auth` only (no MFA required for self-service edit) |
| **Implementation** | All state-changing web routes are CSRF protected | Implemented | — | Laravel `VerifyCsrfToken` in web middleware stack; TokenMismatchException handled in `bootstrap/app.php` | — | ✅ | |
| **Implementation** | API routes use Sanctum + rate limits | Implemented | — | `routes/api.php`: `auth:sanctum`, `throttle:api`, `provider.scope` | — | ✅ | |
| **Implementation** | Store/update actions use Form Requests | Implemented | — | All controllers use typed `FormRequest` subclasses | — | ✅ | |
| **Implementation** | Policies protect sensitive actions | Implemented | — | `AppServiceProvider` registers 39 policies; `Gate::before` for Super Admin | — | ✅ | |
| **Implementation** | Mass assignment controlled | Implemented | — | All models use `$fillable`; no `$guarded = []` | — | ✅ | |
| **Implementation** | No raw SQL with untrusted input | Implemented | — | Codebase uses Eloquent/query builder exclusively; no dangerous `DB::raw(untrusted)` found | — | ✅ | |
| **Implementation** | No `dangerouslySetInnerHTML` with untrusted data | Implemented | — | Grep across `resources/js` shows no `dangerouslySetInnerHTML` usage | — | ✅ | React escapes output by default |
| **Implementation** | No raw exception displayed to users | Implemented | — | `bootstrap/app.php` catchall handler returns generic Inertia error page; debug only in `APP_DEBUG=true` | — | ✅ | |
| **Implementation** | Validation for all user inputs | Implemented | — | Every POST/PATCH/PUT has a typed `FormRequest` | — | ✅ | |
| **Implementation** | File uploads validate MIME, extension, size, storage | Partial | Medium | Profile photo validates `image,mimes:jpg,jpeg,png,webp,max:4096`; employee photo not audited for `mimes` | Audit all upload FormRequests for `mimes` and `max` | ❌ | See `docs/archive/security-hardening-plan.md` §File Uploads |
| **Implementation** | Private files not stored in public web root | Partial | Medium | `production-security-checklist.md` flags employee photos on public disk | Migrate employee photos to private disk with signed URLs | ❌ | |
| **Implementation** | Sensitive fields encrypted/hidden | Implemented | — | `national_id`, `phone_number` cast as `encrypted`; `two_factor_secret` encrypted; `national_id` in `$hidden` | — | ✅ | |
| **Implementation** | ID card QR does not expose PII or token hash | Implemented | — | `CardTokenSecurityTest` verifies; `qr_payload` in `IdCardResource` gated behind `can('view')` | — | ✅ | token_hash never stored in QR; only opaque token |
| **Implementation** | national_id gated in API resource | Implemented | — | `EmployeeDetailResource` L18: gated behind `employees.viewPii` permission | — | ✅ | **Fixed in this audit** |
| **Implementation** | Cafeteria scans do not expose QR secrets | Implemented | — | `VerifyCardForServiceAction` returns minimal result set; `CardTokenSecurityTest` verifies | — | ✅ | |
| **Implementation** | Security-sensitive changes write audit logs | Implemented | — | `WriteAuditLogAction` used in card lifecycle, transfers, employee actions | — | ✅ | |
| **Implementation** | Passwords and secrets never logged | Implemented | — | `ErrorLoggingService` redacts `password`, `token`, `secret`, `key`, `national_id`, etc. | — | ✅ | |
| **Implementation** | APP_DEBUG false documented for production | Implemented | — | `production-security-checklist.md` gate; `.env.example` comment | — | ✅ | |
| **Design** | Secure architecture / defence in depth | Implemented | — | RBAC + org scope + MFA + encryption at rest + audit logs + rate limiting | — | ✅ | |
| **Design** | Least privilege (permissions per role) | Implemented | — | 39 fine-grained permissions; roles have minimum necessary set | — | ✅ | |
| **Design** | RBAC and organisation scope | Implemented | — | Spatie v6 permissions; `OrganizationScopeService`; `UserOrganizationScope` | — | ✅ | |
| **Design** | Data minimisation | Partial | Low | `auth` shared props omit national_id/phone; employee list resource omits PII; detail resource now gated | Ensure no unnecessary fields in any resource | Partial | |
| **Design** | Private storage for sensitive files | Partial | Medium | `filesystems.php` defines `private` disk; employee photos currently on public disk | Migrate to private disk | ❌ | |
| **Design** | Signed/stable ID card QR | Implemented | — | QR uses opaque token + SHA-256 hash; stable `public_card_uuid` for verification URL | — | ✅ | |
| **Design** | Secure session design | Implemented | — | Database sessions; `httpOnly=true`; `sameSite=lax`; documentation for `secure=true` in production | — | ✅ | |
| **Design** | Audit logging design | Implemented | — | `audit_logs` table; `AuditEventType` enum; `WriteAuditLogAction` | — | ✅ | |
| **Design** | Error handling design | Implemented | — | Typed exception handlers in `bootstrap/app.php`; generic user messages; `ErrorLoggingService` | — | ✅ | |
| **Hosting** | Hosting provider security capability documented | Missing | High | No hosting agreement on file | Document in `docs/hosting-security-requirements.md` | ✅ (doc) | |
| **Hosting** | SSL/TLS configured and monitored | Documentation Only | Medium | `production-security-checklist.md` references cert check | Automate cert expiry monitoring | ❌ | |
| **Hosting** | Network / OS security | Documentation Only | High | `docs/infrastructure-security.md` exists | Verify with hosting provider | ❌ | |
| **Hosting** | Physical security | Missing | Medium | Not documented | Add to hosting agreement | ✅ (doc) | |
| **Operation** | Formal access granting/revocation procedure | Missing | High | Admin creates accounts; no formal offboarding checklist | Document in `docs/operations-security-policy.md` | ✅ (doc) | |
| **Operation** | Quarterly vulnerability/risk assessment | Missing | High | No schedule on file | Schedule in `docs/operations-security-policy.md` | ✅ (doc) | |
| **Operation** | Patch management plan | Missing | Medium | No formal plan | `docs/patch-management-plan.md` created | ✅ (doc) | |
| **Operation** | Incident response plan | Missing | High | Referenced in checklist but no formal plan | `docs/incident-response-plan.md` created | ✅ (doc) | |
| **Operation** | Change management | Missing | Medium | No formal process | `docs/change-management-policy.md` created | ✅ (doc) | |
| **Operation** | Web monitoring for breach/misuse | Missing | Medium | `audit_logs` available but no automated alerting documented | Document alerting requirements | ❌ | |
| **Annex A** | SQL Injection — parameterised queries | Implemented | — | Eloquent ORM; no unsafe `DB::raw(user_input)` found | — | ✅ | |
| **Annex A** | Directory Traversal — file serving via DB ID | Implemented | — | File downloads use DB-looked-up paths, not raw URL parameters | — | ✅ | |
| **Annex A** | Session Management | Implemented | — | Regenerate on login; invalidate on logout; secure cookie docs | — | ✅ | |
| **Annex A** | XSS — React default escaping | Implemented | — | No `dangerouslySetInnerHTML` found | — | ✅ | |
| **Annex A** | CSRF | Implemented | — | Laravel CSRF all web routes; Inertia sends X-XSRF-TOKEN automatically | — | ✅ | |
| **Annex A** | HTTP Header Injection | Implemented | — | No user input passed directly to response headers found | — | ✅ | Content-Disposition filenames should use `rawurlencode` |
| **Annex A** | Mail Header Injection | Implemented | — | Laravel Mail used; email recipients validated by FormRequest `email` rule | — | ✅ | |
| **Annex A** | Lack of Authentication/Authorization | Implemented | — | All sensitive routes behind `auth` + policy gates + org scope | — | ✅ | |
| **Annex B** | Security headers (X-Frame-Options, XCTO, CSP) | Implemented | — | `SecurityHeaders` middleware — updated in this audit | — | ✅ | **Fixed in this audit** |
| **Annex B** | HSTS | Partial | Low | HSTS sent only over HTTPS (correct); production checklist documents requirement | Enable on hosting and verify | Partial | |
| **Annex B** | Rate limiting — login | Implemented | — | `LoginRequest::ensureIsNotRateLimited()` — 5 attempts | — | ✅ | |
| **Annex B** | Rate limiting — forgot password | Implemented | — | `throttle:5,1` added to `POST /forgot-password` | — | ✅ | **Fixed in this audit** |
| **Annex B** | Rate limiting — MFA challenge | Implemented | — | `throttle:5,1` on `POST /mfa/challenge` | — | ✅ | |
| **Annex B** | Rate limiting — public verification | Implemented | — | `throttle:30,1` on `/verify/card/{uuid}` | — | ✅ | |
| **Annex B** | Rate limiting — cafeteria scan | Implemented | — | `throttle:60,1` on `POST /cafeteria/scan` | — | ✅ | |
| **Annex B** | Username enumeration on password reset | Implemented | — | `PasswordResetLinkController` returns identical message whether email exists or not | — | ✅ | Laravel Password facade does this by default |
| **Annex B** | File upload — executable files blocked | Partial | Medium | Profile photo uses `mimes:jpg,jpeg,png,webp`; other upload paths need audit | Audit all upload handlers | ❌ | |
| **Annex B** | File upload — size limits | Partial | Medium | Profile photo has `max:4096`; system setting uploads need explicit limit | — | ❌ | |
| **Annex B** | robots.txt does not expose sensitive paths | Implemented | — | No `robots.txt` exposing admin paths found in `public/` | — | ✅ | |
| **Annex B** | Directory listing disabled | Documentation Only | Low | Must be verified at web server (Nginx) level | Set `autoindex off;` in Nginx config | ❌ (infra) | |
| **Annex B** | Source code not in document root | Implemented | — | Only `public/` is web-accessible; `composer.json`, `.env`, `app/` are above web root | — | ✅ | |
| **Annex B** | `.env` not web-accessible | Implemented | — | `.env` is above `public/`; Nginx must block `.env` via location block | — | ✅ (code); ❌ (infra) | |
| **Annex B** | HTTP TRACK/TRACE disabled | Documentation Only | Low | Must be disabled at Nginx level | `add_header X-Frame-Options DENY; # plus TraceEnable off` | ❌ (infra) | |
| **Requirement** | Security requirements documented | Partial | Medium | `docs/requirements-extracted.md` exists | `docs/security-requirements.md` created | ✅ (doc) | |
| **Requirement** | Business/security owner approval | Missing | Medium | No formal sign-off process documented | Add to `docs/security-requirements.md` | ✅ (doc) | |

---

## Critical Risks

| ID | Risk | Severity | Status |
|---|---|---|---|
| CR-1 | national_id exposed in EmployeeDetailResource to all authenticated users with `employees.view` | Critical | **Fixed** — gated behind `employees.viewPii` |
| CR-2 | No rate limiting on `POST /forgot-password` — susceptible to email flooding | High | **Fixed** — `throttle:5,1` added |
| CR-3 | No formal incident response plan | High | **Fixed** — document created |
| CR-4 | Employee photos stored on public disk | High | Open — migration to private disk required |
| CR-5 | No hosting agreement or security SLA documented | High | Open — hosting documentation created |
| CR-6 | SecurityHeaders missing HSTS, CSP, COOP, CORP | High | **Fixed** — SecurityHeaders.php updated |

---

## Implemented Fixes (This Audit)

1. **SecurityHeaders.php** — Added HSTS (HTTPS-only), CSP with `frame-ancestors 'none'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, X-Permitted-Cross-Domain-Policies, COOP, CORP. Changed X-Frame-Options from SAMEORIGIN to DENY. Removed deprecated X-XSS-Protection.
2. **EmployeeDetailResource.php** — `national_id` now gated behind `employees.viewPii` permission.
3. **EmployeePolicy.php** — Added `viewPii()` method.
4. **PermissionSeeder.php** — Added `employees.viewPii` permission.
5. **routes/auth.php** — Added `throttle:5,1` to `POST /forgot-password` and `POST /reset-password`.
6. **lang/en/security.php, lang/am/security.php** — Added INSA-required message keys.
7. **resources/js/i18n/en/security.ts, am/security.ts** — Added frontend i18n keys.
8. **tests/Feature/Security/** — Created `SecurityHeadersTest`, `AuthorizationTest`, `RateLimitingTest`.
9. **Documentation** — Created all 9 INSA compliance documents.

---

## Open Items (Not Fixed — Infrastructure/Operations)

| Item | Owner | Target Date |
|---|---|---|
| Migrate employee photos to private disk | Dev Team | Sprint +1 |
| Negotiate and sign hosting security agreement | Project Manager | — |
| Enable HTTP TRACK/TRACE blocking at Nginx | DevOps | Before Go-Live |
| Automated SSL certificate expiry monitoring | DevOps | Before Go-Live |
| Automated alerting on audit log anomalies | DevOps | Sprint +2 |
| Nonce-based CSP (replace `unsafe-inline`) | Dev Team | Post-MVP |
| Per-card rate limiting on public verification | Dev Team | Sprint +2 |

---

*Next audit due: 2026-08-27 (90 days)*
