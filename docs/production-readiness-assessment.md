# EUISIS production readiness assessment

| | |
|---|---|
| Date | 2026-09-26 |
| Scope | Organization → structure → units → positions → users, roles, scopes → employees → assignments → ID cards → QR/NFC eligibility → providers → cafeteria networks → organization access → policies → entitlement → scanning → transactions → settlement → reports and exports |
| Code inspected | working tree on `main` after commit `1e094b8`, plus the fixes listed in §4 (not yet committed) |
| Environment inspected | local development machine (Windows, PHP 8.2.29, MySQL, file cache, database queue); tests on SQLite in memory |
| Not inspected | any production or staging server, production data, the production database engine, network and TLS setup |
| Decision | **CONDITIONAL GO** — see §9 |

Supersedes [production-readiness-score.md](production-readiness-score.md) (June, 71/100).
Companion documents: [production-flow-map.md](production-flow-map.md),
[go-live-checklist.md](go-live-checklist.md),
[runbook/deployment-and-rollback.md](runbook/deployment-and-rollback.md).

## 1. Summary

The end-to-end flow works: a new automated test drives the whole path —
organization, unit, position, employee, staff account and scope, card request,
approval by a second person, printing, issue, activation, public QR check,
provider, network, main and branch cafeterias, access, assignment, two-person
policy approval, a counter scan in the provider portal, the transaction in both
portals, organization liability, settlement and exports — through the same
HTTP routes people use, and it passes.

Getting there found one **critical** defect: **every scan made in the provider
portal failed with a server error** (PRA-01). Cafeteria counters using the
portal could not record a meal. It had been broken since 2026-06-03 and no
test exercised the route. It is fixed and covered. Three further high-severity
defects were fixed (card backs could not render, a race issuing two live cards,
provider-portal menus and orders failing), along with medium ones.

What keeps this from a plain GO is not a known open defect but what has not
been verified: the production database engine (tests run on SQLite, the
developer machine on MySQL, the operations documents assume PostgreSQL), the
production configuration, the production data, and a staging run with real
roles. §9 lists the conditions.

## 2. Evidence collected

| Check | Result |
|---|---|
| Full test suite (`php artisan test --parallel`) | **2 464 passed, 0 failed** (15 097 assertions), before and after the dependency patch (§7) |
| Failing tests at the start of the audit | 17 — each classified in §5 |
| `composer audit --locked` | 9 advisories in `guzzlehttp/guzzle` 7.10.0 (1 high, 8 medium) → patched to 7.15.5 within 7.x → **no advisories** |
| `npm audit --omit=dev` | 0 vulnerabilities |
| `npx tsc --noEmit` | passes |
| `vite build` | passes; main chunk 968 kB (272 kB gzip) — PRA-18 |
| `vendor/bin/pint --test` | all changed files pass; 89 untouched files have pre-existing style drift — PRA-17 |
| `php artisan migrate:status` | 173 ran, 1 pending: `2026_09_26_000000_add_rbac_metadata_and_register_permission_catalog` (owner's migration, deliberately not applied) |
| `php artisan migrate --pretend` | SQL generated for the pending migration on MySQL without error |
| Route audit (`route:list`) | no duplicate method + URI; sensitive public write routes (sign-in, reset, OTP, ID checker, feedback, scan) throttled by middleware or `LoginThrottle`; legacy portal routes redirect |
| `security:production-check` (local) | 4 FAIL expected on a dev machine (secure cookie, file sessions, registration on, debug log level) — must pass on production (C2) |
| `production:readiness` (new, local) | no blocking item outside production; WARNs listed in §8 |
| Data audits (new, local data) | `data:audit-duplicates`: 1 HIGH — one position with 3 current occupants against 1 approved slot (development data; see D-2). `structure:audit`, `cafeteria:audit-configuration`: clean |
| Smoke script (new) against local server | all checks pass except HTTPS (expected on local HTTP) |

## 3. Findings

Severity: CRITICAL blocks go-live; HIGH must be fixed or explicitly accepted
before go-live; MEDIUM should be fixed soon after; LOW is hygiene.
Status: FIXED (with regression test) · OPEN · NEEDS_DECISION (a business rule
the code cannot settle) · ACCEPTED.

| ID | Severity | Component | Evidence | Risk | Business impact | Root cause | Fix applied | Regression test | Status |
|---|---|---|---|---|---|---|---|---|---|
| PRA-01 | CRITICAL | Provider portal — counter scan | `POST /provider/portal/scan` as a provider operator → HTTP 500 `TypeError` (reproduced before the fix) | Every portal scan fails | Cafeterias using the portal cannot serve subsidised meals; nothing is recorded | The portal authenticates `provider_users` (guard `provider`, since 2026-06-03); the scan action and services accept only a staff `User` (since 2026-05-27). No test posted to the route | `ProcessCafeteriaQrScanAction` accepts a staff user or a provider operator; only staff ids go to `users`-keyed columns; the operator id is kept in `cafeteria_transactions.metadata.scanned_by_provider_user_id` and in the audit entry | `tests/Feature/Cafeteria/ProviderPortalScanTest.php`, `tests/Feature/GoldenPath/GoldenPathTest.php` | FIXED |
| PRA-02 | HIGH | ID cards — back side and QR validator | `IdCardTemplateManagementTest` failed with `QR_PAYLOAD_CONTAINS_PII: … "service"`. The golden path (built-in layout, back QR not shown) passes without the fix | Rendering the back of a card throws whenever its template shows the feedback QR | Backs of such templates cannot be previewed, printed or exported; on a domain containing "org", "service", "fin", "tel", … **every** card QR would be refused | Since 2026-09-20 the back prints the holder's `/service-feedback/{token}` QR through the card QR pipeline; the PII validator matched field names anywhere in the URL, including the fixed route and the host | Validator skips the operator-configured origin (`APP_URL`, `ID_CARD_QR_BASE_URL`) and, for the known single-segment public routes, checks only the opaque segment; other hosts and extra path segments are still refused | `IdCardQrSymbolTest` (7 new cases), `IdCardTemplateManagementTest` | FIXED |
| PRA-03 | HIGH | ID cards — approval | Approving the same lost/damaged/replacement request twice from two stale copies issued two `pending_print`, `is_current` cards (test fails without the fix) | An employee holds two live credentials | Two usable cards; double cafeteria use; weakened revocation | Request status checked before the row lock and never re-read; these request types skip the one-live-card check | Request re-read and re-checked under a row lock inside the approval transaction | `CardRequestWorkflowTest` "issues one card when two approvers approve the same lost-card request" | FIXED |
| PRA-04 | HIGH | Provider portal — menus and food orders | `POST /provider/portal/menus` as an operator → HTTP 500 `QueryException` | Menu create, update, publish, close and order status changes fail | Providers cannot publish menus or handle orders | Operator UUID written to bigint `created_by` / `updated_by` columns referencing `users` | Shared `staffUserId()` writes only staff ids | `ProviderPortalScanTest` "lets a portal operator create, publish and close a menu" | FIXED |
| PRA-05 | HIGH | Deployment — reverse proxy | `infrastructure-security.md` tells operators to set `APP_TRUSTED_PROXIES` or `config/trustedproxy.php`; neither existed | Behind a load balancer every visitor appears as the proxy's IP; HTTPS not detected | Per-IP login lockouts and rate limits lock out everyone at once; audit logs record the proxy; HSTS never sent | Setting never implemented | `config/trustedproxy.php` reads `APP_TRUSTED_PROXIES` (IPs/CIDRs or `*`); trusts nobody when unset; `.env.example` documents it; `production:readiness` reports it | `tests/Feature/Security/TrustedProxiesTest.php` | FIXED (operator must set it — C2) |
| PRA-06 | HIGH | Database engine | `phpunit.xml.dist`: SQLite in memory; developed on MySQL; backup and infrastructure docs: PostgreSQL. **Update 2026-09-26:** first run on PostgreSQL 18.6 (local) — see PRA-21 to PRA-23 for what it found | Migrations or queries that only fail on the production engine; engine-specific behaviour (e.g. a `date` cast compares differently on SQLite — found while writing the golden test) | Failed deployment or wrong results on day one | No CI job on the production engine | All 174 migrations and the seeder now run on PostgreSQL; every parameterless page renders as Super Admin; **full suite on PostgreSQL 18.6 (sequential): 2 468 passed, 1 failed → fixed (PRA-28), 1 skipped (conditional)**; the whole suite also passes on SQLite (2 470). How to run it: `docs/testing.md` | `tests/Feature/PostgresCompatibilityTest.php` and the full suite on PostgreSQL | FIXED locally — repeat on the staging server's PostgreSQL version and a copy of production data (C1) |
| PRA-28 | MEDIUM | Cafeteria policy status sync | Full suite on PostgreSQL: after the nightly sync the superseded version ended "expired" | Order-dependent status history; for a moment two versions of one policy active | Wrong audit trail of which terms applied; confusing policy history | The sync activated due versions in unspecified row order from a stale list: newer first superseded the older, then the stale older copy was re-activated and expired | Oldest first (`effective_from`, `version_no`), each re-read under lock and skipped unless still approved | `PolicyVersioningTest` — 5 consecutive PostgreSQL runs | FIXED |
| PRA-21 | HIGH | Migrations on PostgreSQL | `migrate` failed at `2026_09_15_000100_create_nfc_tables`: "no unique constraint matching given keys" (self-referencing FK), then "foreign key … cannot be implemented" (`uuid` → `varchar(36)`) | Fresh PostgreSQL install impossible | Production database cannot be created | PostgreSQL adds a fluent `->primary()` after the table's foreign keys, so 8 self-referencing tables (NFC, 6 performance tables, cafeteria policies) had no key yet; `external_applications.id` is `varchar(36)` on PostgreSQL only (2026_08_14_000300) but two NFC columns were `uuid` | Explicit `$table->primary('id')` before the foreign keys (compiled DDL identical on MySQL and SQLite — verified); NFC `external_application_id` is `varchar(36)` on PostgreSQL, unchanged elsewhere | migrations run on PostgreSQL 18.6; full SQLite suite | FIXED |
| PRA-22 | MEDIUM | Queries with an empty uuid | On PostgreSQL: `/cafeteria/scan/today`, `/cafeteria/scan/calendar`, `/performance`, `/performance/lookups/units`, `…/positions` → 500 "invalid input syntax for type uuid" | Pages crash instead of answering "none" or "missing parameter" | Performance dashboard unusable until a cycle exists; scan helpers error | MySQL and SQLite treat `uuid_column = ''` as no match; PostgreSQL rejects it | Parameters validated as uuid (422 when missing); the dashboard matches nothing when there is no cycle | `PostgresCompatibilityTest` | FIXED — other code paths may share the pattern; C1 will show them |
| PRA-24 | HIGH | NFC counter recording | Full suite on PostgreSQL: NFC eligibility and record → 500 "invalid input syntax for type uuid" on `cafeteria_transactions.scan_nonce` | Every NFC cafeteria scan fails on PostgreSQL; on MySQL (strict) the 64-character value does not fit the 36-character column | NFC terminals cannot serve meals | The controller stored `sha256(terminal + reference)` in a uuid column; SQLite ignores lengths, so tests passed | Nonce is a name-based UUID (v5) of terminal + reference — still the same for a retried request, so replays are still caught | `NfcTest` (uuid and determinism asserted) | FIXED |
| PRA-25 | MEDIUM | Employee feedback token, grievance latest response | PostgreSQL: `function max(uuid) does not exist` on every card page, preview and print that shows the feedback QR | Card pages and feedback flows fail on PostgreSQL | ID card show/preview/print unusable | `latestOfMany()` always compares keys with `MAX(id)`; PostgreSQL has no MAX for uuid | Relations ordered by `created_at`, then `id` | ID card, feedback and position-service suites on PostgreSQL | FIXED |
| PRA-26 | MEDIUM | Card token parsing | PostgreSQL: a token `"<not-a-uuid>\|…"` → 500 instead of "invalid token" | A garbled or tampered QR crashes the counter request | Error page at the counter; noise in logs | The card id part was sent to the uuid key unchecked | Checked with `Str::isUuid` first (scan service and service verification) | `CardTokenSecurityTest` on PostgreSQL | FIXED |
| PRA-27 | LOW | Tests on PostgreSQL | 3 test-only failures: integer ids on provider users, `orderBy` relying on NULLs sorting first, settings written past the service's cache | False failures | — | Assumptions that hold on SQLite only | Fixtures corrected | the tests themselves | FIXED |
| PRA-29 | HIGH | MFA | Coverage work (MfaController 5 %): a user who enrolled in MFA voluntarily was never challenged; `/mfa/disable` accepted the password alone from a session that had not passed MFA | Voluntary MFA gave no protection; a stolen password could switch MFA off | Account takeover despite MFA | `RequireMfa` and the post-login rule only looked at roles that *require* MFA | Enrolled users are always challenged; disabling needs an MFA-verified session | `tests/Feature/Security/MfaFlowTest.php` (fails on the old code) | FIXED |
| PRA-30 | MEDIUM | MFA | A TOTP code could be reused within its validity window | Replay of an observed or intercepted code | Second factor weakened | Only the code was checked, not whether it had been used | Code must belong to a newer 30-second step than the last successful use | `MfaFlowTest` | FIXED |
| PRA-31 | MEDIUM | Cafeteria access scope | `accessibleProviderIds()` is empty both for oversight users (all) and for users with no assigned cafeteria (none); four callers read empty as "all" | Staff without an assignment saw every cafeteria's today totals, today feed and cafeteria lists | Over-broad access to other cafeterias' figures | Ambiguous "empty list" convention | Only `cafeteria_providers.viewAll` means all; everything else filters (fails closed) | `tests/Feature/Policies/CorePolicyTest.php` | FIXED |
| PRA-32 | MEDIUM | Provider portal export | Exporting from a branch cafeteria was refused | Operators could export only their main cafeteria | Branch payment claims could not be produced | Policy compared with the provider's single "first" cafeteria | Any cafeteria of the operator's own provider | `CorePolicyTest` | FIXED |
| PRA-33 | HIGH | Cafeteria reversal | `POST /cafeteria/transactions/{id}/reverse` → 403 for every user, Super Admin included | No meal could be reversed from the page | Mistaken scans stay charged | The request looked up route parameters `cafeteria_transaction` / `transaction`; the route declares `cafeteriaTransaction` | Parameter name corrected | `tests/Feature/Cafeteria/TransactionOperationsTest.php` | FIXED |
| PRA-34 | HIGH | Cafeteria reversal | Two reversals of the same meal from stale copies each wrote a refund | Double refund of the subsidy | Money | Status checked before the row lock | Checks moved onto the locked row inside the transaction | `TransactionOperationsTest` | FIXED |
| PRA-35 | HIGH | `sensitive-data:encrypt-existing` | Every user row failed ("Errors: 1") yet the command exited successfully; user national IDs and phones stayed in plaintext | Personal data left unencrypted while the run looked clean | Data-protection breach risk | Re-saving through the model decrypts the stored plaintext in its dirty check and throws; errors were only counted | Users written directly with the cast's encryption and the hash; exit code 1 when any row fails | `tests/Feature/Console/EncryptExistingSensitiveDataTest.php` | FIXED |
| PRA-36 | LOW | Dead code | No callers and 0 % coverage: `CafeteriaInstitutionAccessService`, `CafeteriaEmployeeExclusionService`, `CafeteriaBillingService` + `GenerateProviderBillingReportAction` | Maintenance confusion; stale rules someone might revive | — | Superseded by the network / policy model | Not removed | — | NEEDS_DECISION D-8 (remove) |
| PRA-37 | LOW | Provider ledger | The provider ledger is credited only for provider-portal scans and never debited on reversal | The portal balance can differ from settled amounts | Confusing provider statements (settlements use transactions, not this ledger) | Ledger added for the portal scan path only | None | — | NEEDS_DECISION D-9 |
| PRA-23 | MEDIUM | Cafeteria exclusions | `/cafeteria/employee-exclusions/create` selected `first_name_en` / `last_name_en`, columns no migration ever created (since 2026-05-27, all engines). The module (index, create, store) applies no organization scope | The page could not open; a scoped administrator would see and act on every organization's employees | Exclusions could not be recorded; over-broad access once fixed | Wrong column names; module predates organization scoping | Uses `full_name` | `PostgresCompatibilityTest` | FIXED (crash) · OPEN (scope — decide who may manage exclusions, D-7) |
| PRA-07 | HIGH | Dependencies | `composer audit --locked`: 9 Guzzle advisories (CVE-2026-69246 high) | Host-check bypass, cookie scope and proxy-credential leaks in outbound HTTP (SMS, integrations) | Credential or data exposure through outbound calls | Guzzle 7.10.0 | `composer update guzzlehttp/guzzle guzzlehttp/psr7 guzzlehttp/promises` → 7.15.5 / 2.5.3 (minor versions only) | `composer audit --locked` clean; full suite re-run (§7) | FIXED |
| PRA-08 | MEDIUM | Dashboard — activity feed | `DashboardTest` "scoped hr officer" failed: `can.audit` true | HR officers see the audit activity feed (who did what) | Broader access to audit information than the audit log page allows | Feed gated on `reports.view`; HR Officer gained `reports.view` on 2026-09-25 | Feed follows the audit log policy (`audit.view` / `audit-logs.viewAny`) | `DashboardTest` + new "reports.view alone does not open the audit activity feed" | FIXED |
| PRA-09 | MEDIUM | Health check | `/up` returned 200 with the database unreachable | Load balancer keeps sending traffic to a broken node | Errors for users instead of failover | Laravel's default `/up` checks nothing | `DiagnosingHealth` listener runs `select 1` and a cache read | `tests/Feature/HealthCheckTest.php` | FIXED |
| PRA-10 | MEDIUM | Provider ledger | `balance_after` = latest row by `created_at` + credit, outside any lock | Two concurrent scans at one cafeteria build on the same previous balance | Wrong running balance shown to providers | No lock; ties within one second | Lock the cafeteria row and total the ledger in integer cents | `ProviderPortalScanTest` (balance accumulates 120 → 240) | FIXED |
| PRA-11 | MEDIUM | Employees — national ID | Validation-only `unique:employees,national_id_hash`; no unique index. The hash is unkeyed SHA-256 of a 16-digit number | Two simultaneous registrations can share an ID; with a database copy, IDs can be recovered by brute force (10¹⁶ space) | Duplicate identities; disclosure of national IDs if a backup leaks | Hash chosen for lookup only | None — needs a keyed hash (HMAC with a secret), re-hash of existing rows and then a unique index | `data:audit-duplicates` detects duplicates | NEEDS_DECISION D-1 |
| PRA-12 | MEDIUM | Positions — capacity | Registration refuses a second occupant; `position_establishments.approved_slots` allows N; transfer completion and structure import place people with no check. Local data: one position with 3 occupants / 1 slot | Over-filled or wrongly refused positions | Establishment control and vacancy counts unreliable | Two modules, two rules | Audit reports positions above max(1, approved slots). `PositionCapacityService` crashed when called without a date — fixed | `PositionEstablishmentTest` (capacity), `AuditCommandsTest` | NEEDS_DECISION D-2 |
| PRA-13 | MEDIUM | Schema | No DB constraint for one current assignment, one live card, one open card request per employee; `positions.organization_id` has no foreign key | A past race or import leaves duplicates the application then treats ambiguously | Wrong scope, card or policy | MySQL cannot express partial unique indexes; application relies on row locks | Read-only audits `data:audit-duplicates`, `structure:audit` | `AuditCommandsTest` | OPEN — add constraints after C1 and a clean audit |
| PRA-14 | MEDIUM | Cafeteria scan — legacy QR | `CafeteriaQrScanService::resolveCard` accepts a URL ending in a card's primary key with no token | Anyone who learns a card id can present it | Meal fraud with old printed cards | Backward compatibility with early cards | None | — | NEEDS_DECISION D-3 |
| PRA-15 | LOW | ID cards — portrait front | Since 2026-09-20 the portrait front shows the employer, name, position and number only — not nationality or employment type; tests still expected the old layout | — | Printed portrait cards omit two fields landscape cards show | Deliberate redesign, tests not updated | Tests updated to the current design; landscape still asserted in full | `IdCardEmployeeMappingTest`, `IdCardLayoutTest` | NEEDS_DECISION D-4 |
| PRA-16 | LOW | Tests | `IdCardLayoutTest` and four other files `require_once` another *test* file for its helpers, re-registering its tests under the wrong `beforeEach` | Order-dependent failures ("parallel-only" 403s) | False alarms in CI | Helpers kept in a test file | Helpers moved to `tests/Feature/IdCards/IdCardFrontHelpers.php` | every ID card file passes alone and together | FIXED |
| PRA-17 | LOW | Code style | `pint --test`: 89 files not touched by this work | — | Noise in reviews | Style drift | None (not reformatted to keep this change reviewable) | — | ACCEPTED |
| PRA-18 | LOW | Front-end performance | `public/build/assets/app-*.js` 968 kB (272 kB gzip) | Slow first load on weak connections | Slower counters and phones | Large shared bundle | None | — | OPEN |
| PRA-19 | LOW | Local environment | `composer update` showed `vendor/` older than `composer.lock` (framework 12.58 installed, 12.69.2 locked) | Tests ran on older packages than production would install | False confidence | `composer install` not re-run after lock changes | vendor re-synced; suite re-run on the locked versions | §7 | FIXED (local) |
| PRA-20 | LOW | Stale test fixtures | `RegistrationTest` ×3 (employees without `first_name`), `PositionServiceTest` (test role held the renumber override it tested) | — | False failures | Fixtures older than the schema / permission | Fixtures corrected; override path now tested both ways | the tests themselves | FIXED |

## 4. Changes made (uncommitted)

Application: `ProcessCafeteriaQrScanAction`, `CafeteriaQrScanService`,
`CafeteriaTransactionService` (PRA-01) · `ProviderScanController`,
`ProviderMenuController`, `ProviderFoodOrderController`,
`FormatsProviderPortalData` (PRA-01, 04, 10) · `QrPayloadSecurityValidator`
(PRA-02) · `ApproveCardRequestAction` (PRA-03) · `config/trustedproxy.php`,
`.env.example` (PRA-05) · `composer.lock` (PRA-07) · `DashboardDataService`
(PRA-08) · `AppServiceProvider` (PRA-09) · `PositionCapacityService` (PRA-12).

New commands: `production:readiness`, `data:audit-duplicates`,
`structure:audit`, `cafeteria:audit-configuration` (all read-only; audits run
each check in a rolled-back transaction and print ids and codes, never names or
national IDs).

New tests: golden path and negative paths, provider portal scan / menu,
trusted proxies, health check, audit commands, card approval race, QR
validator cases, capacity, dashboard feed, strategic goals and locale.

Documents: this assessment, `production-flow-map.md`, `go-live-checklist.md`,
`runbook/deployment-and-rollback.md`, `scripts/smoke-test.sh`.

Not changed: the owner's pending migration; any data; any seeders; card UUIDs,
employee numbers, position codes, transactions or published hierarchy.

## 5. Test failures at the start, classified

| Test | Count | Classification | Evidence | Resolution |
|---|---:|---|---|---|
| `Auth\RegistrationTest` | 3 | STALE FIXTURE | `employees.first_name` is NOT NULL; fixture omitted it | fixture fixed |
| `DashboardTest` scoped HR officer | 1 | NEW REGRESSION (commit `deb2071`, 2026-09-25) | expectation unchanged since the first commit; role matrix change widened the feed | PRA-08 |
| `PositionServiceTest` service number lock | 1 | STALE FIXTURE | test role granted `Permission::all()`, including the override being tested | fixture fixed, override tested |
| `IdCardTemplateManagementTest` portrait backgrounds | 1 | NEW REGRESSION (commit `bdc0f45`, 2026-09-20) — real defect | feedback QR refused by the PII validator | PRA-02 |
| `IdCardEmployeeMappingTest` employment types | 7 | STALE TEST after deliberate redesign (`bdc0f45`) | renderer comment: "The portrait header names only the employee's actual employer" | tests updated; D-4 |
| `IdCardLayoutTest` portrait sections | 1 | STALE TEST after redesign (`bdc0f45`) | text now centred in its box; box positions still honoured | assertion updated to the box centre |
| ID card preview/export (parallel only) | 3 | TEST HARNESS | `require_once` of a test file | PRA-16 |

## 6. Areas reviewed with no defect found

Organization delete (row lock + in-use guard, soft delete, audit) · employee
registration (single transaction, position lock, generated number, feedback
token inside the transaction) · card approval for new cards (employee lock,
one live card) · print authorization inside `IdCardPrintSnapshotService` ·
scan eligibility (card status, expiry, employee status before any policy) ·
scan idempotency (unique nonce and request hash, replay returns the first
result) · settlement routes (`can:` middleware) · security headers (CSP, frame,
nosniff, referrer, COOP/CORP, HSTS on HTTPS) · public routes throttled ·
QR payload carries no employee data · employee photos on the private disk.

## 7. Verification after the fixes

| Check | Result |
|---|---|
| Full suite on the locked dependency versions (Laravel 12.69.2, Guzzle 7.15.5) | **2 464 passed, 0 failed** (15 097 assertions, 273 s) |
| `composer audit --locked` | no advisories |
| `npm audit --omit=dev` | 0 vulnerabilities |
| `tsc --noEmit`, `vite build` | pass |
| `pint --test` on every changed and new file | pass |

## 8. Readiness score

| Category | Max | Score | Basis |
|---|---:|---:|---|
| End-to-end functional flow | 15 | 12 | golden path passes over HTTP; a critical portal defect existed until today |
| Data integrity and constraints | 12 | 8 | strong locking; uniqueness and "one current" rules not backed by the database (PRA-11, 13); capacity rule unsettled (PRA-12) |
| Security and IAM | 15 | 12 | headers, throttles, MFA support, PII-free QR, private files; unkeyed national-ID hash (PRA-11); legacy card-id scan (PRA-14) |
| Authorization and organization scope | 10 | 8 | policies + scope service + maker/checker; one widening found and fixed (PRA-08) |
| Concurrency and idempotency | 8 | 7 | scan locks and nonces; two races fixed (PRA-03, 10) |
| Tests and quality | 10 | 7 | 2 464 passing tests incl. golden path; runs on SQLite only (PRA-06); style drift |
| Database and migrations | 8 | 5 | pretend clean on MySQL; production engine unverified; data migrations irreversible (restore-based rollback documented) |
| Operations and observability | 8 | 6 | health check, readiness and audit commands, runbooks, smoke script; no metrics or alerting evidence |
| Dependencies | 4 | 4 | both audits clean |
| Performance | 5 | 3 | large main bundle; ledger total per scan |
| Localization, calendar, UX | 5 | 4 | Amharic and Ethiopian calendar throughout; some Amharic validation attributes double the "የ" prefix outside Performance |
| **Total** | **100** | **76** | |

The score does not override §9: a CRITICAL or unmet condition blocks go-live
whatever the total.

## 9. Decision

**CONDITIONAL GO.** No known CRITICAL defect remains open. Go-live is allowed
only when all of the following are true, each signed off in
[go-live-checklist.md](go-live-checklist.md) §A:

- **C1** The production database engine and version are confirmed, migrations run cleanly on it (empty database and a copy of current data), and the full test suite passes on it.
- **C2** `php artisan production:readiness --strict` on the production host reports no blocking item, including `APP_TRUSTED_PROXIES` when behind a proxy.
- **C3** The three data audits on a copy of production data report no HIGH finding, or each has an approved repair plan.
- **C4** The UAT script (checklist §F) passes on staging with real, least-privilege roles on the release tag.
- **C5** The business owner has answered D-1 to D-7.

If C1 or C4 fails, the decision becomes **NO-GO** until fixed and re-verified.

## 10. Decisions needed (NEEDS_DECISION)

| ID | Question | Why the code cannot decide | Default if unanswered |
|---|---|---|---|
| D-1 | Protect national IDs with a keyed hash and enforce uniqueness in the database? | Requires a secret-management choice and a one-time re-hash of every employee | Keep as is; duplicates detected by `data:audit-duplicates` |
| D-2 | How many people may hold one position: always one, or the approved establishment slots? Should transfers and imports enforce it? | Registration and the vacancy module disagree | Registration keeps refusing a second occupant; audit flags over-capacity |
| D-3 | When do cards printed with the legacy URL (card primary key, no token) stop being accepted at the counter? | Depends on how many such cards are in circulation | Keep accepting them |
| D-4 | Must the portrait card front show nationality and employment type as the landscape front does? | Deliberate design change on 2026-09-20 | Current portrait design |
| D-5 | Which database engine is production — MySQL (as developed) or PostgreSQL (as documented)? | Documents and development disagree | **Answered 2026-09-26: PostgreSQL** (`.env.production`); C1 is to be run on PostgreSQL |
| D-6 | Who may finalize a settlement — must it be a different person from the one who drafted it? | Not stated in the brief or the settlement rules | Any holder of `cafeteria_settlements.manage` |
| D-7 | Should cafeteria exclusions (leave, suspension, …) be limited to the administrator's own organizations? | The module was built city-wide; other modules scope by organization | City-wide, as today |
| D-8 | Remove the unused cafeteria services listed in PRA-36? | Deleting code is a product decision | Keep (unused) |
| D-9 | Should the provider ledger cover every scan (counter, NFC, portal) and reversals, or be retired in favour of settlements? | It is only partially written today | Portal scans only |
