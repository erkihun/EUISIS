# Password Security Policy

How EUISIS decides which passwords are acceptable, how they are stored, and
what happens when they change. The policy applies to every password-authenticated
account: administrators, employee accounts, provider-portal users (cafeteria,
transport) and legacy service-provider and cafeteria-provider accounts.

Code: `App\Security\Passwords\PasswordPolicy` (rules),
`PasswordLifecycle` (the only way a password is replaced),
`PasswordHistoryStore`, `PersonalInformation`, `Rules\*`,
`App\Security\Hashing\MigratingArgon2IdHasher`, `App\Security\LoginThrottle`.
Tests: `tests/Feature/Security/PasswordPolicyTest.php`.

## 1. Alignment with NIST SP 800-63B-4

| NIST guidance | EUISIS |
|---|---|
| Minimum 15 characters for single-factor passwords; 8 when used only with MFA | **15 for every account.** System Settings can raise it but never lower it below 15 (`security.passwords.minimum_length_floor`). |
| Accept at least 64 characters | 64–128 allowed (setting, default 128). Never truncated (see §5). |
| Accept spaces, all printing characters and Unicode | Yes. Nothing is trimmed, and paste and password managers work. |
| No composition rules | None. The old "Password Complexity" setting was removed. |
| No periodic expiry | None. The old "Password Expiry Days" setting (never enforced) was removed. |
| Block breached, common, expected and context-specific values | Breach corpus check, common-password blocklist, service words, sequences, personal information (§3). |
| Rate-limit failed attempts | Per identifier+IP, per account and per IP (§8). |

> **Password history of 5 is an EUISIS organizational control, not a requirement to force routine periodic password changes.**

A change is required only after a known or suspected compromise, an
administrator reset, a temporary or legacy-default password, account recovery,
or when the holder chooses to change it.

## 2. One policy, every path

Every place a password is **chosen** validates with `PasswordPolicy::rules()`,
and every place a password is **replaced** goes through `PasswordLifecycle`:

| Path | Where |
|---|---|
| Change own password (profile) | `PasswordController`: current password required, throttled 6/min |
| Forced change at first sign-in | `ForcedPasswordChangeController` |
| Forgot / reset password | `NewPasswordController` |
| Employee self-registration | `RegisteredUserController` |
| Administrator creates a user | `UserStoreRequest` + `UserController::store` |
| Administrator sets or resets someone's password | `UserUpdateRequest` + `UpdateUserAction` (typed) or `generate_temporary_password` |
| Service-provider users (create, reset) | `ServiceProviderUserController` |
| Cafeteria-provider users (create, reset) | `CafeteriaProviderUserController` |
| Provider portal: change own password | `ProviderProfileController`: current password required, throttled |
| Transport provider portal account | `StoreTransportProviderRequest` + `TransportProviderController` |
| Seeded demo accounts | `Database\Seeders\Concerns\DemoPasswords` |

There is no API, CSV import or other bulk path that sets passwords.

## 3. What is rejected

Checks run in this order, stopping at the first failure:

1. **Length:** 15 to 128 characters (settings may tighten).
2. **Confirmation** must match, where the form asks for one (checked on the server).
3. **Common or predictable** (`PasswordIsNotCommon`):
   - an entry of `resources/security/common-passwords.txt`, alone or with digits and symbols added ("Password123456789!" reduces to "password");
   - a password built only from service words in `security.passwords.context_terms` plus digits ("EUISIS-Admin-2026!!", "AddisAbaba12345!");
   - repeated or sequential characters ("aaaaaaaaaaaaaaa", "123456789012345", "qwertyuiopasdfgh");
   - the legacy shared default password, if one is still stored.
4. **Personal information** (`PersonalInformation`): the account's name tokens (English and Amharic), username, email local part, full employee number, or 9-digit phone number. Comparison copies are lower-cased and stripped to letters and digits; the password itself is never changed.
   - Latin tokens shorter than 4 characters are ignored (a name "Ali" must not reject "Salient ..."). Ethiopic tokens count from 2 characters, because each Ethiopic character is a whole syllable.
   - The employee number counts only when it appears whole (`AAC-48392017` and `aac48392017` both count; `4839` does not).
   - Phone numbers match in any format (`09…`, `+2519…`, `2519…`).
   - The email domain and generic words ("com", "mail", "user") never count.
5. **Reuse:** the current password and the last *N* (Password History Count, default 5). Each stored hash is checked with `Hash::check`, never by comparing new hashes.
6. **Breach corpus:** see §4.

Messages name the category ("…must not contain your employee number"), never
the matched value, the history entry or the service used. EN/AM texts are in
`lang/*/password-policy.php`.

**Checked only after identity is proved.** On the forgot-password form the
account-specific checks (personal information, reuse) run only after the
emailed token has been verified. On registration they run only after the OTP
has been verified. Otherwise the error message would reveal to anyone whether
a guess was the victim's current password, or what an employee's name is.

## 4. Breached-password check

`CompromisedPasswordChecker`, implemented by `HibpCompromisedPasswordChecker`,
uses the Have I Been Pwned range API (k-anonymity):

- Only the **first 5 hex characters of the SHA-1 digest** are sent. The password and its full hash never leave the server, and responses are padded (`Add-Padding`).
- Nothing about the password, prefix or response is logged.

If the service is unreachable, the outcome is explicit:

| Account | Result |
|---|---|
| Privileged (MFA-required or Super/City Admin) | Refused: "We could not check this password right now." |
| Everyone else | Accepted; a warning is logged (no password data). All other checks still apply. |

The feature can be turned off with the *Breached Password Check* setting, or
for air-gapped deployments with `PASSWORD_BREACH_CHECK=false`. To use an
internal mirror, set `PASSWORD_BREACH_CHECK_ENDPOINT`.

## 5. Storage and hashing

- **Argon2id** (memory 64 MiB, 4 passes, 1 lane; `config/hashing.php`), which is above the OWASP minimum. This costs about 64 MiB of RAM per concurrent hash on the server.
- bcrypt, the previous default, silently ignores everything after byte 72. A 72-byte prefix is only 24 Amharic characters. Argon2id has no such limit, and a test proves two 90-byte passwords that differ only at the end do not verify as each other.
- **Migration:** `MigratingArgon2IdHasher` still verifies existing bcrypt hashes and reports them as needing a rehash.
  - A user's hash is replaced with Argon2id at their next successful sign-in (`hashing.rehash_on_login`; the provider login does the same).
  - Nobody is locked out. Only bcrypt and Argon2id are accepted as hash formats.
  - A user's first sign-in after the switch changes their stored hash, so their other open sessions (other browsers or devices) end once, reporting "password changed".
- **No Unicode normalization of the password itself.** Passwords are verified byte-for-byte, as before, so no existing password stops working. Only the comparison copies used for the personal-information check are NFKC-normalized.
- **Password history** (`password_histories`) holds old *hashes* only, keyed by account type and id.
  - It is never plaintext or reversible.
  - It has no route, UI, API, export or serialization (the model hides the hash).
  - It is pruned to the configured count on every change.

## 6. The change itself (`PasswordLifecycle`)

All in one transaction:

1. Lock the account row. Concurrent changes run one after another, and the second of two racing requests sees the first one's password as current and is refused.
2. Re-check reuse against the locked row.
3. Move the **current** hash into history, then keep only the newest *N*.
4. Store the new Argon2id hash.
5. Set `must_change_password` and `password_changed_at`.
6. Rotate `remember_token`.
7. Write the audit entry: event and reason only, never a password, hash or token.

After the transaction commits:

- The current session is regenerated and stays signed in.
- `AuthenticateSession` signs out every other session on its next request (docs/session-management.md).
- The holder is notified in-app and by email with what happened and when, and what to do if it was not them. The notification never contains the password.

MFA is never disabled by a change or reset. The next sign-in follows the
normal MFA policy. API credentials of external applications are separate
from human passwords and are not revoked.

## 7. Temporary passwords (administrator handoff)

- If an administrator leaves the password blank when creating an account, or ticks *Generate a one-time password* when resetting one, the system generates **20 random characters from an unambiguous alphabet** (about 114 bits), in the form `xxxxx-xxxxx-xxxxx-xxxxx`.
- The password is shown **once** to that administrator (flash only). It is never stored in plaintext, logged or shown again.
- The account is set to `must_change_password`. The holder can reach only the change-password screen (web) or profile page (provider portal) until they replace it.
- The temporary password goes into history, so it cannot become the permanent one.
- Typed administrator passwords go through the full policy for the target account, and also force a change.
- An administrator cannot change **their own** password through user management; that happens in Profile, where the current password is confirmed.

### Migration from the shared default password

EUISIS used to assign one configured default password to every new account.
That is a shared credential and is no longer done:

| Before | Now |
|---|---|
| Blank password: the shared default | Blank password: a unique one-time password |
| Default password configurable in Security settings | Not configurable; the fields were removed from the UI and are not accepted on save |
| Users signing in with the default are forced to change it | **Unchanged.** A stored default hash is kept for exactly this purpose |
| — | The legacy default can never be chosen again (rejected as common) |

To retire it completely once no account uses it, delete the
`security.default_password_hash` row from `system_settings`.

## 8. Sign-in and reset rate limits

| Limit | Value |
|---|---|
| Failed sign-ins per identifier + IP | *Max Login Attempts* per *Lockout Minutes* (settings; default 5 per 15 min) |
| Failed sign-ins per identifier, any IP | 4× that; slows credential stuffing against one account |
| Failed sign-ins per IP, any account | `LOGIN_PER_IP_FAILURES` (100) per window; slows password spraying |
| Forgot-password and reset submissions | 5/min per IP (shared bucket), plus Laravel's 60-second per-account resend throttle |
| Current-password verification (profile and provider portal) | 6/min |

Every lock is temporary; there is no permanent lockout an attacker could use to
keep someone out. A successful sign-in clears the identifier limits but not
the IP limit.

Reset tokens are random, stored hashed, valid for 60 minutes
(`auth.passwords.users.expire`) and deleted on use. The forgot-password form
answers identically whether or not the email has an account.

## 9. Browser guidance

Every password form shows an advisory checklist (`PasswordPolicyChecklist`):

- length;
- no name, username, email or employee number (checked locally);
- "not common or breached" and "not one of your last *N*", both marked "checked when you save", because history and breach data never reach the browser;
- confirmation match.

New-password fields use `autocomplete="new-password"` and current-password
fields use `current-password`. No field blocks paste or sets a truncating
`maxlength`. The server is the only authority.

## 10. Logging

These keys are redacted from the error log and the audit log:

`password`, `password_confirmation`, `current_password`, `new_password`,
`temporary_password`, `user_password`, `password_hash`, `default_password_hash`,
`reset_token`, `token`, `otp`, `recovery_code(s)`, `two_factor_*`, `authorization`.
