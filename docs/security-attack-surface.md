# EUISIS — Attack Surface Map

Generated during the authorized security assessment of 2026-09-21.
Source of truth: `php artisan route:list` (693 routes) plus middleware inspection.

## Method

Routes were classified by their **effective middleware stack**, not by path
convention. A route counts as authenticated only when
`Illuminate\Auth\Middleware\Authenticate` or an `auth:<guard>` alias is present
in its resolved stack.

## Summary

| Class | Count | Gate |
|---|---|---|
| Total routes | 693 | — |
| Authenticated (any guard) | 661 | `Authenticate` / `auth:<guard>` |
| Unauthenticated | 32 | see below |

## Authentication guards

`config/auth.php` defines three session guards. There is **no employee guard** —
employees authenticate on the `web` guard as ordinary `users` rows, linked to
their personnel record by email.

| Guard | Provider model | Used by |
|---|---|---|
| `web` | `App\Models\User` | Admin portal, employee portal |
| `provider` | `ProviderUser` | Provider portal |
| `cafeteria_provider` | `CafeteriaProviderUser` | Cafeteria provider portal |

## Unauthenticated surface (32 routes)

### PUBLIC — content
`/`, `/announcements`, `/announcements/{slug}`, `/announcements/transfers`,
`/announcements/transfer/{announcement}`, `/services`, `/services/{slug}`,
`/support`

Carry `public.site` (maintenance gate). Content is rendered through
`SafeContentRenderer` (CommonMark with `html_input => strip`, then HTMLPurifier
with an element allowlist and `URI.AllowedSchemes` limited to
https/http/mailto/tel).

### PUBLIC — operational, throttled
| Route | Throttle |
|---|---|
| `GET /id-checker` | yes |
| `GET /id-checker/{cardUuid}` | yes |
| `POST /id-checker/{cardUuid}/send-otp` | yes |
| `POST /id-checker/{cardUuid}/verify-otp` | yes |
| `GET /verify/card/{publicCardUuid}` | yes |
| `GET|POST /service-feedback/{token}` | yes |

Deliberately outside `public.site` so ID verification stays available during a
content outage.

### PUBLIC — redirects and infrastructure
`/cafe`, `/employee`, `/employee/login`, `/cafeteria/portal/*` (redirect-only
closures into the auth-protected provider portal), `/sanctum/csrf-cookie`,
`/up`, `/verify`

### SYSTEM-ONLY — signature gated
`GET /storage/{path}` and `PUT /storage/{path}`, registered by
`FilesystemServiceProvider` for the `local` disk (`storage/app/private`,
`serve => true`). Both require `hasValidRelativeSignature()`; the disk's
visibility defaults to `private`, so an unsigned GET is refused. Verified, not
assumed — see report §Verified-safe.

## EXTERNAL API (`routes/api.php`)

Layered chain, applied per route group and per endpoint:

```
auth:sanctum
  → throttle:api
    → ExternalApplicationGate  (application active)
      → api.scope:<scope>      (scope granted)
        → api.idempotency      (where mutating)
```

Aliases live in `bootstrap/app.php`: `api.scope => EnsureApiScope`,
`api.external => ExternalApplicationGate`.

| Group | Scope examples |
|---|---|
| `/api/v1/nfc/*` | `nfc.verify`, `nfc.service_eligibility`, `nfc.service_transactions.create` |
| `/api/v1/employees*` | `employees.basic_read` |
| `/api/v1/organizations*`, `/organization-units*`, `/positions*` | directory read scopes |
| `/api/v1/id-cards/verify/{token}` | `id_cards.verify` |
| `/api/v1/services/{serviceType}/transactions` | `service_transactions.create` |

**Design note:** the employee/organization directory endpoints apply *no*
per-application organization restriction — `index` returns the whole directory
(filterable by `organization_code`) and `show` resolves by route-model binding.
This is consistent across both endpoints, so it is a deliberate global-read
design rather than a broken object-level check. See report SEC-010.

## AUTHENTICATED — admin portal

Stack: `auth` → `verified` → `mfa` → `force.password` → `admin.access`.

`EnsureAdminAccess` additionally logs out inactive accounts and redirects
`user_type === 'provider'` to the provider portal.

Modules: organizations, organization units, positions, employees, assignments,
ID cards, NFC management, users, roles, permissions, organization scopes, API
management, external applications, audit logs, public site management,
cafeteria, transport, service feedback, recycle bin, reports/exports.

## AUTHENTICATED — employee portal

Stack: `auth` → `force.password` → `admin.access` (no MFA / email-verification
gate).

`/my-portal`, `/my-portal/entitlements`, `/my-portal/transfer-applications`,
`/my-portal/announcements/transfers`,
`/my-portal/announcements/transfer/{announcement}[/apply]`

The employee identity is resolved **server-side** via `$user->employee`
(`hasOne(Employee::class, 'email', 'email')`). No endpoint accepts a
client-supplied `employee_id`.

## AUTHENTICATED — provider portals

`auth:provider` + `provider.portal` + `provider.portal.context` for
`/provider/portal/*`. Legacy `/cafeteria/portal/*` paths are redirect-only.

## Upload / import / export surface

| Surface | Entry |
|---|---|
| Employee photo | `EmployeeController@store/update` → `public` disk |
| User profile photo | `UploadUserProfilePhotoAction` |
| Organization logo, ID card backgrounds, public-site media | admin modules |
| Employee CSV import | `/employees/import` (`mimes:csv,txt`, `max:5120`, 2000-row cap) |
| Organization structure import | `/organizations/import-structure` |
| CSV exports | provider transactions, transport transactions, service feedback |

## Dangerous-pattern census

| Pattern | Count | Assessment |
|---|---|---|
| `exec`/`shell_exec`/`system`/`passthru`/`proc_open` | 0 | no command-injection surface |
| `unserialize()` | 0 | no deserialization surface |
| `$request->all()` | 1 | `ErrorLoggingService` only, redacted |
| Raw SQL (`whereRaw`/`selectRaw`/…) | 94 | none interpolate user input |
| Dynamic `orderBy($var)` | 1 | whitelisted (`EmployeeController`) |
| `dangerouslySetInnerHTML` | 6 | 1 CMS (sanitized server-side), 5 paginator labels |
