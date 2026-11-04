# EUISIS — Phase-2 Security Assessment

**Date:** 2026-09-21
**Type:** Authorized second-pass adversarial review
**Baseline:** `docs/security-assessment-report.md`, `docs/security-attack-surface.md`
**Environment:** local only. No production system touched, no data destroyed,
no external host contacted, no real SMS/email sent.

---

# Executive Summary

Phase 1 cleared the classic web-application surface. Phase 2 attacked the six
areas it explicitly left uncleared, and the result is **worse than Phase 1
suggested** — not because the codebase is weak, but because the weaknesses
that remain are in authorization defaults and identity binding rather than in
input handling.

The most serious finding is not a coding error at all: **public
self-registration is switched on in the current `.env`, and the registration
flow treats an employee number as proof of identity.** Knowing a printed
business identifier is enough to open an account bound to that employee, set
your own password, and be signed straight into their portal. Reproduced end to
end.

Two authorization controls were also found failing open, both silently:
external applications with no endpoint assignment were granted every endpoint,
and permanent record destruction was gated on the *restore* permission while a
dedicated `forceDelete` permission sat unused in the catalog.

Against that, three of the six target areas came back genuinely strong. NFC
replay protection is correctly implemented and correctly **fails closed** when
no cryptographic adapter is bound. Cafeteria duplicate claims are blocked by
database unique constraints. Role escalation resisted every attack attempted,
including role ride-along and cross-organization assignment.

| ID | Severity | Finding | Status |
|----|----------|---------|--------|
| SEC2-005 | **HIGH** | Self-registration: an employee number is treated as proof of identity | **NEEDS-DECISION** |
| SEC2-001 | HIGH | External application with no endpoint assignment was granted every endpoint | **FIXED** |
| SEC2-002 | HIGH | One-active-card invariant checked outside the transaction, unlocked | **FIXED** |
| SEC2-003 | MEDIUM | Duplicate pending card requests from concurrent submits | **FIXED** |
| SEC2-004 | MEDIUM | Permanent deletion gated on `recycle-bin.restore` | **FIXED** |
| SEC-010 | MEDIUM | External API has no per-application organization scoping | **NEEDS-DECISION** |
| SEC-009 | LOW (revised) | `guzzlehttp/guzzle` advisories — no stable fix | **ACCEPTED-RISK** |
| SEC-011 | LOW | `.env.example` ships `SESSION_SECURE_COOKIE=false` | **OPEN** |

---

# Scope

Six priority targets, plus production configuration, dependency residual risk
and regression of every Phase-1 fix. Cafeteria terminal abuse was assessed at
the data-integrity layer (duplicate-claim constraints) rather than by driving
a terminal; that limitation is recorded under *Remaining Risks*.

---

# Findings

## SEC2-005 — Employee number treated as proof of identity (HIGH) — NEEDS-DECISION

**Affected component:** `app/Http/Controllers/Auth/RegisteredUserController.php`,
`POST /register`, gated by `security.registration_enabled`.

**Attack prerequisite:** the feature switched on — **which it currently is** —
and knowledge of one employee number belonging to an employee who has no
account yet.

**Evidence:** `.env:69` reads `REGISTRATION_ENABLED=True`. `config()` resolves
it to `true`. The controller accepts `employee_number` and `password` only; it
looks the Employee up, copies that employee's name and email onto a new `User`,
stamps `employee_reference`, and calls `Auth::login($user)`.

**Reproduction:** `tests/Feature/Security/SelfRegistrationTakeoverTest.php`
posts a valid employee number with an attacker-chosen password and asserts the
resulting account is bound to the employee's email, carries their
`employee_reference`, and that the caller is authenticated as it.

**Impact:** takeover of an employee's portal identity — entitlements, cafeteria
balance and ledger, transfer applications, personal detail. Employee numbers
are printed on ID cards, appear in CSV imports and exports, and the observed
format (`EMP-0001`) is sequential, so they are enumerable. Phase-1 data showed
**0 of 3 employees hold accounts**, meaning every employee is currently
takeover-eligible.

**Root cause:** possession of a business identifier is used as an
authentication factor. The only control is "an account for this email does not
exist yet", which fails for exactly the population that matters.

**Recommended fix (product decision, not applied):** either
1. set `REGISTRATION_ENABLED=false` and provision accounts administratively, or
2. require proof of possession before the account is created — send an OTP to
   the email/phone already on the Employee record and verify it, reusing the
   `PublicIdCheckerService` OTP machinery, which Phase 1 verified as sound.

I did not change the environment file: it is your configuration and may be
deliberately on for testing. `php artisan security:production-check` now
reports it.

## SEC2-001 — Endpoint assignment failed open (HIGH) — FIXED

**Affected component:** `ExternalApplication::allowsEndpoint()`.

**Evidence:** the method returned `true` whenever the assignment list was
empty. The documented rationale was backwards compatibility for integrations
predating endpoint assignment — but the code could not distinguish those from
an application registered today, so **every new application was unrestricted
until someone remembered to narrow it**. Phase 1's stated chain ("endpoint
assigned") did not hold.

**Reproduction:** an application created with scopes and zero endpoints
returned `200` from `/api/v1/employees`.

**Impact:** an administrator who registered an integration and did not reach
the endpoint screen published the whole API surface to it, within its token
scopes, silently.

**Fix:** migration `2026_09_21_120000_close_external_application_endpoint_fail_open.php`
adds `unrestricted_endpoints`, defaulting to `false`, and backfills `true` for
applications that have no assignments **today**. Existing integrations are
untouched; new ones are deny-by-default. The column is deliberately absent from
`$fillable` so it can never be set from a request.

**Regression test:** `ExternalApplicationOrganizationScopeTest` ("an
application is refused an endpoint it was never assigned"), plus a new
explicit case in `ExternalApplicationEndpointAssignmentTest`.

## SEC2-002 — One-active-card race (HIGH) — FIXED

**Affected component:** `app/Actions/IdCards/ApproveCardRequestAction.php`.

**Evidence:** the "employee already has a live card" check ran **before**
`DB::transaction()` opened, took no row lock, and `id_cards` carries no unique
index that would catch a duplicate (verified against the live schema: unique
only on `id`, `card_number`, `token_hash`, `public_card_uuid`).

**Impact:** two approvals for one employee, racing, both read "no active card"
and both issue one — an employee holding two live credentials in an identity
system, where revoking one leaves the other valid.

**Root cause:** check-then-insert. Notably `RegisterEmployeeAction` already
does this correctly for position occupancy, so the safe pattern existed in the
codebase and was not applied here.

**Fix:** the invariant now runs inside the transaction behind
`lockForUpdate()` on the employee row.

**Regression test:** `IdCardIssuanceRaceTest`, including a structural
assertion that transaction → lock → check remain in that order. Honest
limitation: true parallel execution needs MySQL and two connections and is not
reproducible on the suite's in-memory SQLite, so the race was proven by code
analysis, not executed.

## SEC2-003 — Duplicate pending card requests (MEDIUM) — FIXED

`SubmitCardRequestAction` carried the identical check-then-insert pattern for
"employee already has a pending request". Fixed the same way. Lower severity
because duplicate *requests* are a workflow defect, and SEC2-002's fix now
stops them becoming duplicate *cards*.

## SEC2-004 — Permanent deletion gated on the restore permission (MEDIUM) — FIXED

**Affected component:** `app/Http/Controllers/Web/RecycleBinController@forceDelete`.

**Evidence:** the endpoint checked `recycle-bin.restore`. A distinct
`recycle-bin.forceDelete` permission exists in the seeded catalog and was
**never checked anywhere**.

**Impact:** a role intended only to *recover* records could irreversibly
destroy them — employees, organizations, ID cards. Latent rather than live:
all three roles holding `restore` today also hold `forceDelete`, so nobody's
effective capability changed. The gap would have opened the moment a
recovery-only role was created.

**Fix:** the endpoint and the UI capability payload both check
`recycle-bin.forceDelete`.

**Regression test:** `SoftDeleteAuthorizationTest`.

## SEC-010 — No per-application organization scoping (MEDIUM) — NEEDS-DECISION

Re-confirmed with evidence. `external_applications` has no organization
column; `index` returns the whole directory and `show` resolves by route-model
binding. **This is not a BOLA** — both behave identically, so there is no
inconsistency to exploit. It is the blast radius of granting
`employees.basic_read` at all: no token can be limited to one bureau.

Implementing it changes the API contract for existing consumers, so it remains
your decision. `ExternalApplicationOrganizationScopeTest` records the current
behaviour as evidence and is written to be **inverted** to assert
`403 ORGANIZATION_SCOPE_DENIED` if you adopt scoping.

## SEC-009 — Guzzle advisories (LOW, revised from MEDIUM) — ACCEPTED-RISK

Still 9 advisories, still no stable release — the only candidate remains
`7.10.x-dev`, correctly not installed.

**Reachability analysis (new in Phase 2):** `app/` contains **zero** outbound
HTTP call sites — no `Http::` facade use, no `GuzzleHttp\Client`
instantiation. Guzzle is transitive only and the application never drives it.
The advisories concern client redirect, header and URI handling, so real
exposure is minimal. Severity revised down accordingly.

---

# Verified-safe in Phase 2

Attacked and found sound. Recording these so a third pass does not re-litigate
them.

## NFC — replay protection is real; hardware crypto is **not** present

`nfc_challenges` stores `nonce_hash` (hashed, never plaintext) bound to
`nfc_credential_id`, `terminal_id` and `context_hash`, with `expires_at` and
`consumed_at`. `NfcVerificationService` looks the challenge up under
`lockForUpdate()`, rejects a consumed nonce as `REPLAY_DETECTED`, validates
credential/terminal/context/expiry binding, and **consumes the nonce even on
failure** so a proof can never become a reusable oracle. Terminal, card,
credential and employee rows are all locked. `nfc_credentials` stores
`chip_uid_hash`, not a raw UID, and carries `key_version` / `key_reference`
for rotation.

**Assurance level, stated honestly: B — static NDEF reference.**
`config('nfc.adapter')` is bound to `UnavailableSecureCardAdapter`, which is
**not** a `SecureCardAdapter`. Any credential whose type is not
`ndef_reference` is therefore refused with `INVALID_CRYPTOGRAPHIC_PROOF`. The
system has the architecture for challenge-response and correctly **fails
closed** without it — but no cryptographic card verification is active in this
deployment, and this report does not claim any.

Consequence to carry forward: reference-mode credentials skip the challenge
block entirely, so a captured static payload can be replayed as a
*verification*. Duplicate *service claims* are what the unique constraints
below stop.

## Cafeteria duplicate claims — blocked at the database

`cafeteria_transactions` carries UNIQUE on both `scan_nonce` and
`scan_request_hash`. A duplicate claim fails at the constraint, not at an
application check that could be raced. This is the right layer.

## Position assignment — correctly locked

`RegisterEmployeeAction` takes `lockForUpdate()` on the Position row *before*
the occupancy check, inside one transaction. Two concurrent assignments
serialize on that lock. Not check-then-insert; my initial hypothesis was
wrong.

## Employee number generation — constraint-backed

`employees.employee_number` is UNIQUE. A racing duplicate fails at the
database rather than producing two employees with one number. `RAND_6`/`RAND_8`
are business identifiers; QR, NFC and API tokens use independent values
(`token_hash`, `public_card_uuid`, both UNIQUE).

## Role escalation — held against every attempt

Six attacks, all refused: scoped admin granting a protected global role;
granting it to self; smuggling `Super Admin` alongside a permitted role;
assigning across organizations; assigning without `users.assignRoles`. Three
controls stack — `UserPolicy::assignRoles` (no self-assignment, shared scope
required), `Role::canBeAssignedBy` (global/protected roles restricted to Super
and System Admin), and `AssignRolesRequest` (rejects if *any* named role
fails). Control case confirmed: Super Admin can still assign.

## Soft-deleted integration tokens — rejected

A soft-deleted `ExternalApplication` can no longer authenticate; its live token
returns 401.

## Phase-1 fixes — all still enforced

CSV formula injection and log redaction: 26 tests passing. CommonMark
sanitizer re-tested with four payloads post-upgrade, all neutralised.

---

# Production Configuration

New command: **`php artisan security:production-check`**
(`app/Console/Commands/SecurityProductionCheck.php`).

Nine checks — debug off, app key set, session Secure/HttpOnly/SameSite/encrypt,
registration off, no wildcard CORS, log level not debug. Outside production
they report WARN; in production, or with `--strict`, they FAIL and the command
exits non-zero so a deploy gate can block on it. It prints no secret values.

Current local run: three WARNs — `SESSION_SECURE_COOKIE` (correct for HTTP
development), `REGISTRATION_ENABLED` (**SEC2-005**), and log level.

---

# Fixes Applied

| File | Change |
|---|---|
| `database/migrations/2026_09_21_120000_close_external_application_endpoint_fail_open.php` | new — closes the endpoint fail-open, backfills legacy grace |
| `app/Models/ExternalApplication.php` | `allowsEndpoint()` deny-by-default; boolean cast, not fillable |
| `app/Actions/IdCards/ApproveCardRequestAction.php` | invariant moved inside transaction behind a row lock |
| `app/Actions/IdCards/SubmitCardRequestAction.php` | same, for the pending-request guard |
| `app/Http/Controllers/Web/RecycleBinController.php` | force delete gated on `recycle-bin.forceDelete` |
| `app/Console/Commands/SecurityProductionCheck.php` | new — deployment configuration gate |
| `tests/Feature/Settings/ExternalApplicationEndpointAssignmentTest.php` | legacy grace made explicit; new fail-closed case |

# Tests Added

| File | Tests |
|---|---|
| `tests/Feature/Security/ExternalApplicationOrganizationScopeTest.php` | 8 |
| `tests/Feature/Security/IdCardIssuanceRaceTest.php` | 3 |
| `tests/Feature/Security/SoftDeleteAuthorizationTest.php` | 4 |
| `tests/Feature/Security/RolePrivilegeEscalationTest.php` | 6 |
| `tests/Feature/Security/SelfRegistrationTakeoverTest.php` | 3 |

---

# Remaining Risks

1. **SEC2-005 is live right now.** Until registration is disabled or bound to
   possession, every employee without an account is takeover-eligible.
2. **SEC-010** — global directory read for any integration.
3. **NFC is reference-grade.** No cryptographic card verification is active.
   Static payloads are replayable as verifications; only the transaction-level
   unique constraints stop duplicate claims. Do not describe the NFC channel
   as cryptographically authenticated in any external document.
4. **Concurrency proven by analysis, not execution.** The SQLite test database
   cannot express the parallel case. Before production, run the card-issuance
   and position-assignment races against MySQL with two connections.
5. **Cafeteria terminal abuse** was assessed at the constraint layer only. The
   eligibility rules themselves — holiday, leave, weekend, upfront-week —
   were not adversarially driven.
6. **`vendor/` drift** (Phase-1 SEC-012) persists; audit the lock, deploy with
   `composer install`.

---

# Production Readiness Decision Points

1. **Decide SEC2-005 before any production exposure.** Disable registration, or
   add OTP possession-proof. This is the gate.
2. Decide SEC-010 before onboarding an integration that must not see the whole
   directory.
3. Set `SESSION_SECURE_COOKIE=true` and wire
   `php artisan security:production-check --strict` into the deploy pipeline.
4. Run the concurrency cases against MySQL.
5. Keep watching for a stable Guzzle release; do not ship the dev branch.
