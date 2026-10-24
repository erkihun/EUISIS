# INSA Annex B — Minimum Security Testing Checklist

**Project:** Addis One  
**Standard:** INSA Secure Website Management Standard v1.0 Annex B  
**Date:** 2026-05-27

Status: ✅ Pass | ❌ Fail | ⚠️ Partial | 🔲 Not Tested | N/A Not Applicable

---

## 1. Information Disclosure

| # | Test | Status | Notes |
|---|---|---|---|
| B1.1 | No extraneous files (`.bak`, `.old`, `.sql`) in public document root | ✅ | `public/` contains only `index.php`, `build/`, `storage` symlink |
| B1.2 | Directory listing disabled | 🔲 | Must be verified at Nginx level — `autoindex off;` required |
| B1.3 | Debug functionality not available in production | ✅ | `APP_DEBUG=false` documented; `bootstrap/app.php` passes to Ignition only in debug mode |
| B1.4 | Sensitive info not in logs / error responses | ✅ | `ErrorLoggingService` redacts `password`, `token`, `national_id`, `secret`, `key` |
| B1.5 | `robots.txt` does not expose admin/API paths | ✅ | No `public/robots.txt` with sensitive paths |
| B1.6 | Source code not in document root | ✅ | Only `public/` served; `app/`, `.env`, `composer.json` above web root |
| B1.7 | Internal addresses / IPs not disclosed in responses | ✅ | Error handler strips stack traces; generic error ID returned |
| B1.8 | `.env` not accessible via HTTP | ✅ | Above web root; Nginx must also add `location ~ /\.env { deny all; }` |

---

## 2. Privacy and Confidentiality

| # | Test | Status | Notes |
|---|---|---|---|
| B2.1 | Sensitive info not in URLs | ✅ | national_id, token_hash never in URL; `public_card_uuid` is opaque |
| B2.2 | No sensitive data in client-side storage (localStorage/sessionStorage) | ✅ | Inertia uses server sessions; no PII stored client-side |
| B2.3 | No sensitive cached pages (Cache-Control headers) | ⚠️ | Inertia sets `no-cache` for page visits; static assets cached by Vite hash — acceptable |
| B2.4 | Sensitive transmission uses HTTPS/TLS | ✅ | `SESSION_SECURE_COOKIE=true` required in production; `force_https` system setting |
| B2.5 | No mixed content | ✅ | CSP `upgrade-insecure-requests` not yet set; HTTPS enforced at proxy level |
| B2.6 | HSTS configured | ⚠️ | HSTS sent when request is HTTPS; must be verified in production deployment |
| B2.7 | Strong TLS (TLS 1.2+, no weak ciphers) | 🔲 | Server-level configuration — verify with `testssl.sh` before go-live |
| B2.8 | Approved cryptographic primitives | ✅ | AES-256-CBC (config `APP_CIPHER`); BCRYPT rounds=12; SHA-256 for hashes |

---

## 3. State Management

| # | Test | Status | Notes |
|---|---|---|---|
| B3.1 | Client-side state not trusted | ✅ | All state-changing operations validated server-side by Form Requests + Policies |
| B3.2 | Invalid state transitions rejected | ✅ | Card lifecycle enforces valid transitions via `DomainException`; transfer workflow uses status machine |

---

## 4. Authentication and Authorization

| # | Test | Status | Notes |
|---|---|---|---|
| B4.1 | Missing auth checks | ✅ | All routes behind `auth` middleware; policies enforced |
| B4.2 | Client-side auth not trusted | ✅ | `auth` shared props are display-only; gates enforced server-side |
| B4.3 | Predictable/default credentials | ✅ | Seed users use `Hash::make('password')`; production checklist requires change before go-live |
| B4.4 | Predictable auth tokens | ✅ | Sanctum tokens are random; QR tokens are `random_bytes(32)` |
| B4.5 | Obscurity-based authorization | ✅ | No security-by-obscurity; all authz via explicit gates/policies |
| B4.6 | IDOR / privilege escalation | ✅ | Org scope service prevents cross-org IDOR; policy gates verify ownership |
| B4.7 | Weak passwords | ✅ | Laravel `Rules\Password::defaults()` enforced on new password set |
| B4.8 | Account recovery flow | ✅ | Standard Laravel password reset; `throttle:5,1` on reset endpoints |
| B4.9 | Remember-me safety | ✅ | Laravel `remember_token` stored hashed; session regenerated on use |
| B4.10 | Fail-open conditions | ✅ | `Gate::before` only returns `true` for Super Admin, `null` for others (fail-closed default) |
| B4.11 | Plaintext password retrieval | ✅ | Password cast as `hashed`; reset sends token link, not plaintext |
| B4.12 | Username enumeration | ✅ | `PasswordResetLinkController` returns same message whether email exists or not |
| B4.13 | Unsafe credential distribution | ✅ | Seed passwords changed before production; no plaintext credentials in code |
| B4.14 | Missing rate limits | ✅ | Login (5), forgot-password (5), reset (5), MFA challenge (5), public verify (30) |
| B4.15 | Credential change re-authentication | ✅ | `ConfirmablePasswordController` requires password confirmation |
| B4.16 | Logout exists | ✅ | `POST /logout` invalidates session + token |
| B4.17 | Multi-stage workflows secure | ✅ | Transfer and card request workflows enforce step-by-step state machine |
| B4.18 | MFA for privileged roles | ✅ | `RequireMfa` middleware; TOTP enforced for `Super Admin`, `City Admin` |

---

## 5. User Input Management

| # | Test | Status | Notes |
|---|---|---|---|
| B5.1 | SQL injection | ✅ | Eloquent parameterised queries throughout; no `DB::raw(user_input)` |
| B5.2 | Path traversal | ✅ | File paths resolved via DB ID, not raw user input |
| B5.3 | XSS — stored/reflected/DOM | ✅ | React escapes output; no `dangerouslySetInnerHTML` found |
| B5.4 | Command injection | ✅ | No `shell_exec`, `exec`, `passthru` usage found |
| B5.5 | XML injection | N/A | No XML processing in app |
| B5.6 | SMTP injection | ✅ | Laravel Mail validates recipients; `email` validation rule in FormRequests |
| B5.7 | HTTP header injection | ✅ | No user input passed directly to response headers; `Content-Disposition` uses safe values |
| B5.8 | Expression language injection | N/A | No EL processing |
| B5.9 | Open redirect | ✅ | `intended()` redirects only within the app; no external redirect with user input |
| B5.10 | Fuzz request parameters | 🔲 | Manual/automated fuzzing not yet performed — schedule before go-live |

---

## 6. Session Management

| # | Test | Status | Notes |
|---|---|---|---|
| B6.1 | CSRF protection | ✅ | Laravel CSRF all web routes; Inertia sends X-XSRF-TOKEN |
| B6.2 | Session revocation on logout | ✅ | `$request->session()->invalidate()` + `regenerateToken()` |
| B6.3 | Session regeneration on login | ✅ | `$request->session()->regenerate()` after `Auth::attempt()` |
| B6.4 | Session regeneration after credential change | ⚠️ | Password update should regenerate session — verify `PasswordController` |
| B6.5 | Secure cookie flag | ⚠️ | `SESSION_SECURE_COOKIE=true` required in production (documented) |
| B6.6 | HttpOnly cookie flag | ✅ | `SESSION_HTTP_ONLY=true` (default) |
| B6.7 | SameSite cookie | ✅ | `SESSION_SAME_SITE=lax` |
| B6.8 | Predictable session identifiers | ✅ | Laravel uses cryptographically random session IDs |
| B6.9 | Session fixation | ✅ | `session()->regenerate()` on login prevents fixation |
| B6.10 | Periodic expiration | ✅ | `SESSION_LIFETIME=120` min (configurable via system settings) |
| B6.11 | Token disclosure in logs | ✅ | `ErrorLoggingService` redacts tokens |

---

## 7. File Upload

| # | Test | Status | Notes |
|---|---|---|---|
| B7.1 | Uploads not stored in document root | ✅ | Uploads go to `storage/`; `storage:link` creates symlink only for public files |
| B7.2 | Uploaded files not executable / interpreted | ✅ | `mimes:jpg,jpeg,png,webp` on profile photo; no PHP extension allowed |
| B7.3 | Uploads limited to designated directory | ✅ | Storage disk used; no arbitrary path allowed |
| B7.4 | Size restrictions | ⚠️ | Profile photo has `max:4096`; verify other upload points have explicit limits |
| B7.5 | Type validation (MIME + extension) | ⚠️ | Profile photo validated; employee document upload needs audit |

---

## 8. Content

| # | Test | Status | Notes |
|---|---|---|---|
| B8.1 | `Content-Type` headers set | ✅ | Laravel/Inertia sets `text/html; charset=UTF-8` on web responses |
| B8.2 | Charset definitions | ✅ | UTF-8 enforced throughout; Amharic content validated |
| B8.3 | Anti-content-sniffing header | ✅ | `X-Content-Type-Options: nosniff` in SecurityHeaders |

---

## 9. XML Processing

| # | Test | Status | Notes |
|---|---|---|---|
| B9.1 | XXE protection | N/A | No XML processing in application |
| B9.2 | DTD disabled | N/A | |
| B9.3 | Entity expansion disabled | N/A | |

---

## 10. Deployment

| # | Test | Status | Notes |
|---|---|---|---|
| B10.1 | Security updates applied | 🔲 | Run `composer audit` and `npm audit` before each deployment |
| B10.2 | No unsupported/EOL software | ⚠️ | PHP 8.4, Laravel 12, Node 24 LTS (22 LTS fallback; Node 20 is end of life) — verify before go-live (gate G3) |
| B10.3 | HTTP TRACK/TRACE disabled | 🔲 | Nginx-level — `add_header ... ; # TraceEnable Off` |
| B10.4 | Extraneous functionality removed | ✅ | `APP_ENV=production` removes dev routes |
| B10.5 | Default credentials removed | ⚠️ | Seed passwords must be changed before production |
| B10.6 | Web server vulnerabilities monitored | 🔲 | Requires monitoring setup |

---

## 11. Miscellaneous

| # | Test | Status | Notes |
|---|---|---|---|
| B11.1 | Anti-clickjacking | ✅ | `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` |
| B11.2 | Open redirect | ✅ | No open redirects found |
| B11.3 | Cross-domain access policy | ✅ | `X-Permitted-Cross-Domain-Policies: none` |
| B11.4 | Email rate limiting | ✅ | `throttle:5,1` on forgot-password |
| B11.5 | Resource-intensive function rate limiting | ✅ | Reports generate, cafeteria scan, API — all rate limited |
| B11.6 | DoS-prone endpoints rate limited | ✅ | API `throttle:api` (120/min adjustable); public verify 30/min |

---

## 12. Rate Limiting Summary

| Endpoint | Limit | Notes |
|---|---|---|
| `POST /login` | 5 per email+IP | `LoginRequest::ensureIsNotRateLimited()` |
| `POST /forgot-password` | 5/min/IP | `throttle:5,1` |
| `POST /reset-password` | 5/min/IP | `throttle:5,1` |
| `POST /mfa/challenge` | 5/min | `throttle:5,1` |
| `POST /mfa/setup/confirm` | 10/min | `throttle:10,1` |
| `GET /verify/card/{uuid}` | 30/min/IP | `throttle:30,1` |
| `POST /cafeteria/scan` | 60/min | `throttle:60,1` |
| `GET /api/v1/*` | 120/min (configurable) | `throttle:api` |
| `POST /email/verification-notification` | 6/min | `throttle:6,1` |

---

*Last reviewed: 2026-05-27*
