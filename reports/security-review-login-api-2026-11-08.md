# Security review — login flow, API endpoints, XSS / SQLi / access control

**Date:** 2026-11-08
**Scope:** Authentication flow, API endpoints, and the SQL-injection / cross-site-scripting / broken-access-control classes, against the local dev app (`http://127.0.0.1:8000`, `APP_ENV=local`, MySQL `psemsdb`).
**Method:** Static source review plus live probes against localhost only. No credentialed exploitation was performed.

## Verdict

The app is well-defended on every class you asked about. SQL injection: none found — raw SQL uses bound parameters or hardcoded identifiers. Access control: strong, layered middleware on both API and web routes; unauthenticated API calls return 401. XSS: rich-text output is sanitized on both server (HTMLPurifier) and client (DOMPurify), and the login flow does not reflect input.

Two real weaknesses are worth fixing, both defense-in-depth rather than live exploits:

1. **CSP ships `script-src 'unsafe-inline'` in production** — the one finding I'd prioritize.
2. **A latent stored-XSS footgun** in the Court admin case view that renders `description` as raw HTML, safe today only because the write path happens to purify.

## Status (2026-11-08)

- **Finding 1 fixed.** `script-src` is now `'self' 'nonce-…'` with a fresh nonce per request, in every environment. The layout's theme script, the Court letter preview and the three reception slips carry the nonce; their `onclick` handlers became listeners. Verified live: the login page boots with no console errors, and an injected `<script>` or `onerror` handler does not run.
- **Finding 2 fixed.** The Court admin case page purifies description and relief on every render; the raw `description_html` assignment is gone.
- **Finding 3 open.**

## Findings

### 1. CSP allows `'unsafe-inline'` scripts in production — Medium

**Evidence.** Live response headers on `/login` include a real CSP, but with `script-src 'self' 'unsafe-inline'`. The source confirms this is not dev-only:

```
app/Http/Middleware/SecurityHeaders.php:86
  $scriptSrc = $isLocal
      ? "script-src 'self' 'unsafe-inline' {$viteHttp}"
      : "script-src 'self' 'unsafe-inline'";   // production also keeps unsafe-inline
```

The code already carries a note to fix this (lines 67-68). `'unsafe-inline'` negates CSP's main protection: if any XSS payload ever reaches the DOM, CSP will not stop inline script execution. The rest of the policy is good (`frame-ancestors 'none'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`).

**Fix.** Adopt nonce-based CSP using Laravel's `Vite::useCspNonce()`, then drop `'unsafe-inline'` from `script-src`. Add `'strict-dynamic'` so bundled scripts still load without a host allowlist. Keep `'unsafe-inline'` only inside the `$isLocal` branch for the Vite dev client.

### 2. Court admin case view renders stored `description` as raw HTML — Medium (latent)

**Evidence.** The admin case `show()` assigns the stored case body straight to an `_html` field with no sanitization:

```
Modules/Court/Http/Controllers/Admin/CaseController.php:498
  $case->description_html = (string) ($case->description ?? '');
```

That value flows through `caseDetailProps()` (line 841 reads it back and only purifies when it is *empty*), into the Inertia payload (line 912), and is rendered by `DocumentSheet` with `dangerouslySetInnerHTML` (`resources/js/Pages/Court/Cases/Show/DocumentSheet.tsx`, and `DetailsSection.tsx:103`). A comment at line 447 asserts this is "safe" because the field "was already purified on applicant save."

That assertion holds **today**: the applicant write path sanitizes on save (`ApplicantCaseController::cleanHtml` → `Purifier::clean`, lines 226/916). So this is not a live vulnerability. But the safety depends entirely on every writer purifying before store. The relief field on the same screen is re-purified on read (lines 846-849); `description` is not. One new write path that forgets to purify turns this into stored XSS executing in a reviewing court officer's browser — a privilege escalation, since the victim is higher-privileged than the applicant author.

**Fix.** Purify at the render boundary, so the view is safe regardless of write-time behavior. Change line 841 to always clean:

```php
$descriptionHtml = Purifier::clean((string) ($case->description_html ?? $case->description ?? ''), 'cases');
```

Then delete the raw assignment at line 498 and the "safe to render raw" comment.

### 3. Applicant reply `description` stored unsanitized — Low

**Evidence.** Unlike the main case controller, reply descriptions are stored raw:

```
Modules/Court/Http/Controllers/Applicant/ApplicantResponseReplyController.php:101,177
  'description' => $data['description'],   // no Purifier::clean
```

Every current render escapes it before display — the applicant view uses a text node with `whitespace-pre-line` (`Replies/Show.tsx:56`), the admin case view escapes each line via `plainTextHtml` → `escapeHtml` (`ResponsesSection.tsx:53-58`), and the record view re-purifies (`RecordController.php:302`). So there is no live XSS. It is listed as Low because the stored value is dirty and its safety, like finding 2, lives only in the render layer.

**Fix.** Sanitize on write for consistency with the case controller: run `description` through the same `cleanHtml` helper in `store()` and `update()`.

## What was checked and found solid

**SQL injection — none found.** Every `whereRaw` / `selectRaw` / `DB::raw` I reviewed either binds user values as parameters (e.g. `whereRaw('lower(email) = ?', [$email])`) or interpolates only hardcoded identifiers and enum-derived expressions. The one dynamic-column helper, `DashboardChartService::employeeDistribution($scope, $column)`, is only ever called with the literals `'gender'` and `'employment_type'` (lines 33-34) — no request input reaches it. Date-bucket and CASE expressions are built from internal enums, not from parameters.

**Login flow — solid.** `AuthenticatedSessionController` and `LoginRequest` use `Auth::attempt` with validated input, regenerate the session on success, and force Argon2id rehashing. `LoginThrottle` applies three failure limits (identifier+IP, identifier-wide, and per-IP spray protection) and only counts failures. Live probe: a `<script>` payload in the `email` query parameter was not reflected.

**Access control — strong and layered.** API routes stack `auth:sanctum`, scoped tokens (`api.scope:…`), provider binding (`provider.scope`), per-device auth, and rate limits. Web provider-portal routes gate each action with `provider.permission:*`. Live probe: unauthenticated `GET /api/v1/employees/1/entitlements` and `/api/v1/organizations` both returned **401**. Organization-scoped roles fail closed per the recorded ADR 0003 design.

**Rich text — defended in depth.** The admin rich-text viewer (`RichTextViewer.tsx`), public content (`PublicPage.tsx`), and court manual (`CourtManualContent.tsx`) all sanitize with DOMPurify client-side against an allowlist, on top of server-side HTMLPurifier on save and on send.

**Security headers — present.** Live `/login` returns `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, a `Permissions-Policy`, and a CSP (weakened only by finding 1). Session cookie `agelgay_session` is `HttpOnly; SameSite=Lax`.

## Suggested priority

1. Finding 1 (CSP nonce migration) — removes the one production-wide weakness and backstops any future XSS slip.
2. Finding 2 (purify at render in the Court admin view) — closes the latent footgun.
3. Finding 3 (sanitize reply on write) — consistency hardening.
