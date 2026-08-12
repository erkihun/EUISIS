# EUISIS — Multi-Factor Authentication (MFA / TOTP)

## Overview

EUISIS supports Time-based One-Time Password (TOTP) multi-factor authentication
backed by [`pragmarx/google2fa-qrcode`](https://github.com/antonioribeiro/google2fa-qrcode).
Any RFC 6238 authenticator app works (Google Authenticator, 1Password, Authy,
Microsoft Authenticator, Bitwarden, etc.).

Enforcement is decided by `App\Services\Security\MfaRequirementService`.
`security.mfa_enabled` (System Settings > Security, default **on**) is the
master switch: when off, no one is forced through MFA. While on, two sources
are combined (union):

1. **Env baseline** — members of roles listed in `config/security.php` →
   `mfa_required_roles` are forced through MFA.
2. **System Settings > Security** (database-backed, admin-editable):
   - `security.mfa_enabled` — master switch (gates the env baseline too).
   - `security.mfa_required_for_all` — every user must use MFA.
   - `security.mfa_required_role_ids` — role-id checklist (guard-aware, since
     role ids are unique across guards).
   - `security.require_mfa_for_admins` — **legacy** flag; while still `true`
     it maps to `config('security.mfa_privileged_roles')`. It is hidden from
     the settings UI and automatically set to `false` the first time the new
     role-based settings are saved.

Other users may still enrol voluntarily via `/mfa/setup`.

## Configuration

| Env var | Default | Purpose |
|---|---|---|
| `MFA_REQUIRED_ROLES` | `"Super Admin,City Admin"` | Comma-separated role names whose users must enrol MFA (baseline, independent of DB settings). |
| `MFA_PRIVILEGED_ROLES` | `"Super Admin,City Admin,Organization Admin"` | Role names treated as "admins" for the legacy `require_mfa_for_admins` setting. |
| `MFA_ENFORCE` | `true` (false during `php artisan test`) | Master kill-switch for the `RequireMfa` middleware. |
| `MFA_SESSION_LIFETIME_MINUTES` | falls back to `SESSION_LIFETIME` | How long an MFA session verification lasts before re-challenge. |

`config/security.php` exposes a derived value `mfa_recovery_code_count`
(default 8) — number of one-time recovery codes generated at enrolment.

## Schema

Migration: `database/migrations/2026_05_25_000000_add_two_factor_to_users_table.php`

| Column | Type | Notes |
|---|---|---|
| `two_factor_secret` | text, encrypted | Base32-encoded TOTP secret. |
| `two_factor_recovery_codes` | text, encrypted | JSON array of bcrypt-hashed recovery codes. |
| `two_factor_confirmed_at` | timestamp, nullable | Set the moment the first TOTP code is accepted. |
| `two_factor_enabled` | bool | Mirror of `confirmed_at !== null`; convenient for queries. |
| `two_factor_last_used_at` | timestamp, nullable | Audit aid (no PII). |

All sensitive columns are also added to `$hidden` on the User model so they
never leak through resources or JSON serialisation.

## Routes

| Method | URI | Name | Controller |
|---|---|---|---|
| GET | `/mfa/setup` | `mfa.setup` | `MfaController@showSetup` |
| POST | `/mfa/setup/confirm` | `mfa.setup.confirm` | `MfaController@confirmSetup` |
| GET | `/mfa/challenge` | `mfa.challenge` | `MfaController@showChallenge` |
| POST | `/mfa/challenge` | `mfa.challenge.verify` | `MfaController@verifyChallenge` |
| POST | `/mfa/disable` | `mfa.disable` | `MfaController@disable` |

Throttles applied: `throttle:5,1` on challenge verify; `throttle:10,1` on
setup-confirm and disable. All MFA routes require `auth`.

## Middleware

- `mfa` (`App\Http\Middleware\RequireMfa`): applied to the dashboard route
  group. Pushes role-required users to `/mfa/setup` or `/mfa/challenge`.
- `mfa.setup` (`App\Http\Middleware\EnsureMfaNotRequired`): inverse — bounces
  already-verified users away from the setup page.

## Enrolment flow

1. User in a required role logs in.
2. `RequireMfa` detects no confirmed MFA secret → 302 to `/mfa/setup`.
3. `showSetup` generates a TOTP secret (`saveQuietly`) and renders a QR code as
   an inline SVG data URI plus the manual key.
4. User adds the account to their authenticator and posts the first 6-digit
   code to `mfa.setup.confirm`.
5. On success: `two_factor_enabled=true`, recovery codes are generated, the
   session is marked MFA-verified, and the user is redirected to the dashboard.
6. Recovery codes are flashed to the next page render (one-time view).

## Challenge flow (returning user)

1. Login succeeds.
2. `RequireMfa` sees confirmed MFA but no current `mfa_verified_at` in the
   session → 302 to `/mfa/challenge`.
3. User submits a TOTP code OR a recovery code. Recovery codes are bcrypt
   hashed and consumed (single use).
4. Session is marked MFA-verified for `mfa_session_lifetime_minutes`.

## Disable

`POST /mfa/disable` requires `current_password`. If the user's role still
requires MFA, the request is refused with a localized error and an audit log
entry — they cannot leave themselves un-MFA'd.

## Audit events

The following `AuditEventType` cases are emitted (see `app/Enums/AuditEventType.php`):

- `mfa.setup_started`
- `mfa.enabled`
- `mfa.challenge_succeeded`
- `mfa.challenge_failed` (also on bad setup-confirm code)
- `mfa.disabled`
- `mfa.recovery_code_used`

## Emergency reset (operator runbook)

If a user loses their authenticator AND their recovery codes:

1. Verify identity out-of-band (e.g. ID card + manager call).
2. From `php artisan tinker` run:

   ```php
   $u = App\Models\User::where('email', '...')->firstOrFail();
   $u->forceFill([
       'two_factor_secret' => null,
       'two_factor_recovery_codes' => null,
       'two_factor_confirmed_at' => null,
       'two_factor_enabled' => false,
       'two_factor_last_used_at' => null,
   ])->save();
   ```

3. Tell the user to log in; they'll be sent back through the enrolment flow.
4. Manually write an audit log entry for the reset, e.g.:

   ```php
   app(App\Actions\Audit\WriteAuditLogAction::class)->execute(
       App\Enums\AuditEventType::MfaDisabled,
       actor: auth()->user(),
       auditable: $u,
       reason: 'operator_reset_lost_device',
   );
   ```

## Testing

The `RequireMfa` middleware is bypassed during `php artisan test` by setting
`config('security.mfa_enforce')` to `env('APP_ENV') !== 'testing'`. Tests that
specifically exercise MFA flows should override the config:

```php
config(['security.mfa_enforce' => true]);
```
