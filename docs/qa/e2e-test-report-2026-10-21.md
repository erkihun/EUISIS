# Addis One (EUISIS) — End-to-end test report, 2026-10-21

Commit under test: `be95c28` (main, "chore(deps): Inertia 3, TanStack Table 9, TypeScript 7").
Tested by: an automated QA pass (one lead plus six module testers) using the repository's own gates, Pest, Playwright/Chromium and HTTP probes. Everything below was observed, not inferred; evidence paths are listed per item.

## 1. Verdict

**Not releasable from this commit.** Three blockers and a red automated suite stand in the way; the rest is a long but ordinary defect list for a product of this size.

| Area | Verdict |
|---|---|
| Build, typecheck, i18n audit, code style | PASS |
| Automated suite (Pest) | **FAIL** — 263 of 264 local failures come from one regression in the Inertia 3 upgrade (Pest page-file check); CI on `main` has been red for runs #100–#106 and #107 (this commit) was still running |
| Seed and run on PostgreSQL | **FAIL** — `db:seed` and the employee-registration screen abort the transaction with the shipped `CACHE_STORE=database` |
| Authentication, MFA, session, throttling | PASS (14/14 journeys) |
| Every signed-in page (349 GET routes) | PASS — 0 server errors, 0 JS errors |
| HTTP security hygiene | PASS with 4 findings (CSP `unsafe-inline`, Host header, X-Powered-By, mail config) |
| ID card lifecycle | FAIL for the intended roles (officer gets 403; no segregation of duties) |
| HR core (organizations, positions, employees, transfers) | Organizations and positions PASS (21/22); **employee registration FAIL on every path** (B1); transfers BLOCKED by it |
| Public site, complaints, grievances, employee portal | PASS with 1 High (draft grievance cannot be submitted) |
| Platform administration | PASS with 1 High (user edit form hides server errors) |
| Provider / cafeteria / transport portals | PASS for UAT steps 5–9 on the cafeteria side with 1 High (seeded demo cards cannot be scanned); transport contract/statement chain untested |
| Amharic, Ethiopian calendar, responsive, dark mode, accessibility | PASS with Medium localisation and contrast findings |

## 2. Blockers (fix before any release)

| ID | Sev | Title | Where |
|---|---|---|---|
| B1 | Critical | Register employee returns HTTP 500 on PostgreSQL; `php artisan db:seed` fails the same way. A `ShouldBeUnique` job is dispatched twice inside one DB transaction; the second `cache_locks` INSERT violates the primary key and PostgreSQL aborts the whole transaction (`SQLSTATE 25P02`). | `app/Observers/EmployeeAssignmentCommunicationObserver.php:36-46`, `app/Actions/Employees/RegisterEmployeeAction.php:35`, `Illuminate\Cache\DatabaseLock::acquire()` |
| B2 | Critical | Inertia 3 upgrade broke the test suite on Linux: inertia-laravel 3.5.1 defaults `pages.paths` to `resources/js/pages` (lowercase); the app keeps `resources/js/Pages`. Every `assertInertia()->component()` fails with "page component file does not exist" (263 tests). Works on case-insensitive developer machines only. | no `config/inertia.php` published; `vendor/inertiajs/inertia-laravel/src/ServiceProvider.php:49-55` |
| B3 | Critical | ID Card Officer cannot submit a card request (403) because the controller authorises `view` on the employee and the role has no `employees.view`. The go-live UAT step F3 is impossible for its role. | `app/Http/Controllers/Web/CardRequestController.php:108,115`, `app/Policies/EmployeePolicy.php` |

Proposed fixes (not applied; this pass was test-only):

- B1: do not acquire unique-job locks inside the transaction. Either move the dispatch to `afterCommit` at the *dispatcher* level (`dispatch(...)->afterCommit()` still acquires the lock immediately, so use `DB::afterCommit(fn () => Job::dispatch(...))`), or drop `ShouldBeUnique` on `SyncEmployeeCommunicationMemberships` and de-duplicate inside the job, or use a lock store that is not the same PostgreSQL connection (`CACHE_STORE=redis` is what production uses; the default `.env.example` and the seeder path still break). Eight other transactional services dispatch jobs and nine jobs are `ShouldBeUnique`; audit them with the same lens.
- B2: `php artisan vendor:publish --tag=inertia-config` and set `'pages' => ['paths' => [resource_path('js/Pages')]]`, or rename the directory. Verified locally: with `config(['inertia.pages.paths' => [resource_path('js/Pages')]])` the view finder resolves `CodeRules/Index`.
- B3: authorise card-request creation on the card policy (`id-cards.submitRequest` + organization scope), not on `EmployeePolicy::view`.

## 3. Environment

- Fresh clone, Composer and npm installed in a Linux sandbox; PHP 8.3.6 (bcmath added, as CI does), Node 22, PostgreSQL 16.15 started locally.
- `.env` = `.env.example` with `DB_CONNECTION=pgsql`; defaults kept deliberately (`CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `MAIL_MAILER=log`, `APP_DEBUG=false`). A `queue:work` worker ran throughout. Imagick is absent, as in CI.
- App served by `php artisan serve` (4 workers). Six browser testers, the page crawl and the Pest suite shared four CPU cores, so **page timings in this report are upper bounds**, not measurements of the product.
- Demo data from `DatabaseSeeder` (seeded with `CACHE_STORE=file` to get past B1). Dedicated QA accounts were created per tester (`qa.*@demo.local`, `System Admin` role, which has the same 879 permissions as Super Admin and, by default, no MFA requirement — see S4).

## 4. Static gates and automated suite

| Gate | Result | Notes |
|---|---|---|
| `npm run build` (tsc + Vite 8) | PASS | Chunk-size warning: `rich-text-editor` 1.2 MB, Amharic dictionary 789 KB, English 541 KB; 702 JS files, 9.96 MB total uncompressed. |
| `npm run i18n:audit` | PASS | 0 blocking, 164 `latin_words_in_am` warnings, 1 deprecated term. The audit needs `vendor/` present (it resolves framework validation keys from there). |
| `vendor/bin/pint --test` | PASS | 0 files. |
| `php artisan migrate` (PostgreSQL, fresh) | PASS | 15 s. |
| `php artisan db:seed` (PostgreSQL) | **FAIL** | B1. Passes with `CACHE_STORE=file`. |
| Pest, SQLite in memory, 3 workers | **FAIL** | 264 failed, 2 skipped, 4245 passed (4511 tests, 51 min). 263 = B2. The remaining one, `OrganizationDesign\DesignImportTest` (Excel chunk count 527 vs 528), also fails in CI run #106, so it predates this commit. |
| Pest, PostgreSQL, 3 workers | **FAIL** | 284 failed, 1 skipped, 4226 passed (50 min). 16 failures are PostgreSQL-only (§4.1). A first attempt with 2 workers ran out of memory (512 MB limit). |
| CI on `main` (GitHub Actions "Laravel") | **RED** | Runs #100–#106 all failed; #106 (`d5e2c3c`): 3 failed, 4506 passed, 2 h 53 min single-process. Its failures: `Intelligence\TelemetryTest` (flush), `DesignImportTest`, `PublicFeedback\OrganizationFeedbackFormTest` (offices). Run #107 for this commit had been running for more than 2.5 h when checked and will add B2. |
| `scripts/smoke-test.sh` | PASS | 12/13; the one failure is the HTTPS check, not applicable on local HTTP. |
| `php artisan production:readiness` | no blockers | WARN: `sslmode=prefer`, Imagick missing, brand logo/favicon missing, no public-feedback routing rules, backups not configured, config/routes not cached, 0 of 14 institutions have a public QR. |
| `php artisan security:production-check` | no unsafe settings | WARN: session cookie not `Secure` (local HTTP), debug log level (local). |

Observed once, not reproduced: in the first Pest run (no per-test time limit) one worker stayed at 91 % CPU on a single test for more than 20 minutes while the box was at load 10. With a 180 s limit no test exceeded 66 s (`PerformanceWorkflowTest`), so this is most likely starvation, but the suite has no time limit configured and CI takes ~3 hours per run; `enforceTimeLimit` and `--parallel` in CI would surface hangs and cut the cycle.

Evidence: `scratchpad/pest-sqlite.log`, `scratchpad/pest-sqlite-junit.xml`, `scratchpad/pest-sqlite-failures.json`, `scratchpad/build.log`, `scratchpad/i18n.log`, `scratchpad/pint.log`, `scratchpad/seed.log`.

### 4.1 PostgreSQL suite (the production engine)

`phpunit.pgsql.xml.dist` with a 180 s per-test limit, 3 workers, memory limit raised to 1.5 GB after a first attempt died with "Allowed memory size of 536870912 bytes exhausted" in a worker (2 workers, ~2,250 tests each; the shipped 512 MB limit is too low for long-lived parallel workers on PostgreSQL).

Result: **284 failed, 1 skipped, 4226 passed** in 50 min. Most failures are again B2. Diffing against the SQLite run (the JUnit file covers 3,322 of the 4,511 tests, so the diff is partial) isolates **16 PostgreSQL-only failures**, which are the ones to look at before the next release because they only show on the production engine:

| Test | What PostgreSQL rejects |
|---|---|
| `Api\MasterBackboneRemediationApiTest` (3 idempotency tests, lines 353/374/384) | API answers 500 instead of 200/409 on a repeated or conflicting idempotency key — most likely the same "unique violation inside a transaction aborts it" pattern as B1. |
| `OrganizationDesign\WorkforceAnalysisTest` (5 tests) | `SQLSTATE[23514]` check constraint `position_requirements_one_subject_chk` violated by the test's own rows — the factory data breaks a PostgreSQL-only constraint. |
| `Intelligence\GovernanceAndForecastTest` | `SQLSTATE[22P02]` invalid input syntax for type uuid: `"snap-1"` — a non-uuid string compared with a uuid column. |
| `OrganizationDesign\DesignIntegrationTest`, `PublicFeedback\PublicFeedbackContextTest`, `Transport\TransportIntegrityTest` | `SQLSTATE[25P02]` current transaction is aborted — a failed statement inside a transaction is caught and the code continues (SQLite tolerates this, PostgreSQL does not). |
| `PublicFeedback\PublicFeedbackOperationsTest` | `SQLSTATE[23503]` foreign key `employee_service_feedback_category_id_*` violated during the repair routine. |
| `Transport\TransportSecurityTest` | manual-exception lookup answers 500 instead of 404 for an unknown id. |
| `Integration\OutboxEventsTest` (tombstone payload), `OrganizationDesign\DesignImportTest` (remembered mapping) | array/string comparisons differ between engines (ordering or type casting). |

These match the warning in `docs/testing.md` ("SQLite accepts things PostgreSQL rejects") and go-live condition C1, which requires the suite to pass on PostgreSQL before a release. Evidence: `scratchpad/pest-pgsql.log`, `scratchpad/pest-pgsql-junit.xml`, `scratchpad/pest-pgsql-only-failures.json`.

## 5. Lead tester journeys

### 5.1 Authentication, MFA, session (01-auth.js) — 14/14 PASS

Wrong password error; Super Admin forced TOTP enrolment then dashboard; logout; second login hits the MFA challenge; wrong code rejected with a message; a protected page is refused until the challenge passes; correct TOTP accepted; a TOTP code already used is refused in a second session (replay protection); HR Officer logs in without MFA; lockout after repeated failures (identity+IP 5 per 15 min, account 20, IP 100); forgot-password does not reveal whether the account exists; `/register` redirects (self-registration off). 0 console errors, 0 5xx.

### 5.2 Every parameterless signed-in page as Super Admin (02-crawl.js) — PASS

349 GET routes: 330 × 200, 13 × 403 (12 are `/my-portal/*` for a non-employee account, 1 `/communication/api/channel-scopes/units`), 2 × 404 (`/court-cases` is feature-flagged off, `/situation-room/actions/owners`), 2 file downloads. **0 server errors, 0 debug output, 0 JavaScript page errors, 0 failed XHR.** 68 pages took more than 3 s under load (worst: `/organizations/create` 10.0 s, `/organization-unit-types/create` 8.8 s, `/reporting-lines` 8.6 s, `/organizations/import-structure` 8.5 s, `/performance/plans` 7.8 s). Re-measured in §9 when the box was quiet.

### 5.3 HTTP security probes (50-security-probes.sh)

| Check | Result |
|---|---|
| CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, COOP, CORP | present |
| CSRF on POST /login without token | PASS — 419 rendered as redirect, login does not happen |
| 404 / invalid uuid / unknown API route / TRACE | PASS — no internals, TRACE 405 |
| `.env`, `composer.*`, `.git/*`, logs, `employeeId_db`, `sec-routes.json` | not served (404/403) |
| Unauthenticated API GETs (11 parameterless routes) | none answers 200 |
| forgot-password throttle (5/min) | PASS — 429 on the 6th |
| Session id rotated on login; old cookie rejected after logout | PASS |
| Staff session on provider portal route | 401 — guards are separated |

Findings: S1–S5 in §7.

## 6. Module tester reports

### 6.1 HR core — organizations, positions, employees, transfers (21/22 PASS; employees BLOCKED)

Works: organization search, show, create under a draft hierarchy version (generated code equals the preview), edit, audit rows, hierarchy version list and tree; position create with a generated job code, empty-submit validation, duplicate code refused with 422; employee number preview and empty-submit validation; CSV import validation with per-row messages; HR Officer refused on `/users`.

Fails: registering an employee from the form, confirming a validated CSV import and the duplicate-detection attempt all end in HTTP 500 (B1; 239 log lines). Even direct model creation in tinker fails the same way. Transfers could not be exercised; `CompleteTransferAction.php:54-117` follows the same dispatch pattern and will very likely fail too. Also: an employee without a current assignment is 403 even for a System Admin (W21), and the CSV-import flash shows the raw SQL error (W22).

Evidence: `results/10-hr-*.json`, `shots/hr-*.png` (49).

### 6.2 ID card lifecycle and public verification (60 PASS / 3 FAIL, all real)

Works: ordering rules (issue or activate before print → 403; "mark printed" before the frozen print → refused), frozen print snapshot and SVG artifacts, batch completion, issue, activate, revoke, replacement linkage, full audit chain, legacy `/verify/card/{uuid}` → 301, PNG export without Imagick → 503 with a message (not a 500). The anonymous checker shows only the status and a masked number; the Inertia props carry no PII before consent.

Defects: B3, S1–S3, W5, W12–W14 in §7, plus W8 (holder OTP cannot be delivered, so the post-consent reveal is untested).

### 6.3 Public site, public feedback, grievances, employee self-service

Works: all public pages at desktop and 375 px; complaint and tip-off with categories, uploads (EXE renamed to PDF refused by content sniffing), one-time form token, duplicate guard, throttle (3 per QR code per 10 min, friendly flash in the browser, 429 for API callers); back-office assignment, transitions (invalid transitions → 422) and audit; employee portal daily activity (TinyMCE, sanitised HTML); XSS probes in 12 fields neither executed nor leaked (rich text sanitised server-side by HTMLPurifier and again by DOMPurify; plain-text fields stored raw and escaped by React).

Defects: W2, W9, W10, W17, W18, S11 in §7.

### 6.4 Platform administration

Works: user lifecycle with password policy reasons, encrypted national ID and phone at rest, forced first-login change, self-deactivation refused, last Super Admin protected, role/permission propagation, Super Admin and System Admin roles not editable, settings save/revert with audit, Sanctum tokens shown once and scope-checked, recycle-bin restore, other sessions signed out after a password change.

Defects: W3, W6, W7, W15, W16, W20, S4, S7, S8, S10 in §7.

### 6.5 Provider, cafeteria and transport portals (UAT steps 5–9 PASS on cafeteria)

Works: service types and providers; creating a cafeteria provider with network and cafeteria, a transport provider and three portal users (one-time 23-character password shown once on screen, never mailed or logged, forced change at first portal login, weak passwords refused); organization access and service assignment approvals; service policy with the "second person approves" rule enforced server-side (submitter's direct POST → 422); portal scan of an active card accepted with subsidy 100 / employee 20 and billed to the employee's organization; transactions in the portal and the back office; CSV/XLSX/PDF statements and payment-claim exports are real files; guard isolation between cafeteria, transport and staff (403/redirect everywhere, foreign ids 404); suspension ends portal access; transport vehicles, drivers, routes, schedules, trips; transport portal boarding start, scan refusals with the right reason codes, depart/complete, manifest PDF; cafeteria settlement preview → draft → per-organization liability → finalised by a second person.

Not as the UAT script expects: a second scan on the same day is accepted as an "extra scan" (employee pays full price) because the policy's extra-scan rule defaults to `employee_paid`, not `block`. Untested for lack of time: transport contract approval → statement → PDF, an accepted transport boarding, scan-only operator boundaries.

Evidence: `results/60-portal-*.json`, `shots/portal-*.png`. One seeded row was changed as a workaround: `AA-2026-DEMO01.public_card_uuid` was back-filled (P1).

### 6.6 Localisation, calendar, responsive, dark mode, accessibility, performance

Works: Amharic on 15/15 reachable pages with Abyssinica SIL and Ethiopian-calendar dates, 0 mojibake; language and theme persist across reload and tabs; no horizontal overflow at 375/768; drawer and menus keyboard- and touch-operable with focus trapping; skip links work; axe: 0 critical, 1 serious.

Defects: L1–L12 in §7.

## 7. Defect register

Severity: Critical = blocks a core journey or the release; High = wrong or lost data, security boundary; Medium = a journey degrades or a rule is not enforced; Low = cosmetic, hygiene.

### Security and access control

| ID | Sev | Title | Evidence / location |
|---|---|---|---|
| S1 | High | No segregation of duties on card approval: the requester approves their own request (`approved_by = requested_by`). Contradicts go-live F3. | `CardRequestPolicy.php:49-53`, `ApproveCardRequestAction.php`; `results/20-idcards-state.json` |
| S2 | High | ID Card Officer can approve, reject and verify requests and sees the Approve button: `cards.manage` is accepted wherever an approver permission is required. | `CardRequestPolicy::approve/reject/verify`, `IdCardPolicy` |
| S3 | Medium | Public ID checker distinguishes existing cards from unknown ones before holder consent (unknown → "Not valid"; existing → "Active/Revoked/Lost" plus last 4 digits) — an enumeration oracle, against the service's own rule. | `PublicIdCheckerController::show :69-78`, `PublicIdCheckerService.php:24-28`; `shots/idc-public-{unknown,revoked,lost}.png` |
| S4 | Medium | MFA baseline: `MFA_REQUIRED_ROLES` names "HR Manager" and "Security Officer", which do not exist; "System Admin" (879 permissions, identical to Super Admin) and "Public Service Bureau Admin" (777) need no MFA. The Security settings tab shows an empty required-roles list. | `config/security.php:45-47`, `roles` table (56 roles); `shots/40-adhoc-security-0.png` |
| S5 | Medium | CSP `script-src` keeps `'unsafe-inline'` in production (the middleware notes the nonce path as TODO). | `app/Http/Middleware/SecurityHeaders.php:86-88` |
| S6 | Medium | Host header is trusted for absolute URLs: `Host: evil.example` → redirect to `http://evil.example/login`. No `TrustHosts`, no `URL::forceRootUrl(APP_URL)` (only `forceScheme`). The password-reset notification is the framework default, so a reset link can carry an attacker's host if the web server forwards arbitrary `Host`. | probe log; `app/Providers/AppServiceProvider.php:578` |
| S7 | Medium | A deactivated account that still has a pending forced password change can sign in and set a password; it is only stopped afterwards by `EnsureAdminAccess`. | `LoginRequest::authenticate` (no status check); `results/40-admin-users.json` |
| S8 | Low | National ID shown unmasked on the user edit form to any `users.update` holder; `users.viewSensitive` is not consulted. | `UserController::edit`; `shots/40-users-edit-nid.png` |
| S9 | Low | `X-Powered-By: PHP/8.3.6` is sent (built-in server; not stripped by the middleware). `/.htaccess` is served by the built-in server (irrelevant behind nginx). | probe log |
| S10 | Low | Audit rows for user and role events have no `request_ip` / `user_agent` (settings, profile and password rows do). | `CreateUserAction`, `UpdateUserAction`, `DeactivateUserAction`, `RestoreUserAction`, `AssignRolesAction`, `RoleController`; `results/40-admin-audit.json` |
| S11 | Low | Grievance Officer has no `service_feedback.*` grants: only System Admin can work public complaints (403 on `/service-complaints`, `/feedback-admin*`). | role_has_permissions; `shots/officer-*.png` |

### Data and workflow

| ID | Sev | Title | Evidence / location |
|---|---|---|---|
| W1 | Critical | = B1 (employee registration 500 / seeder failure on PostgreSQL). | `laravel.log` ERR-FBLWJZTJE9LX, ERR-RIQMFSRHI18Y; PG log "duplicate key value violates unique constraint cache_locks_pkey" |
| W2 | High | A grievance saved as draft can never be submitted: `updateDraft` keeps only `COMPLAINANT_FIELDS`, which omit `applicant_declared_at`, so the completeness check fails with a message that names no field. | `GrievancePortalController.php:306-310`, `GrievanceCaseService.php:62-67,149,326-338`; `shots/emp-grievance-submit-from-detail.png` |
| W3 | High | User edit form never shows server validation errors (own status → inactive, removing the last Super Admin, bad photo all fail silently): it posts with `router.post` but renders `form.errors` from an unrelated `useForm`. | `resources/js/Pages/Users/Edit.tsx:140-172`; `results/40-admin-users.json` |
| W4 | Medium | "Save Employee" triggers the unsaved-changes dialog on its own submit; "Cancel" silently drops the save. | `resources/js/Pages/Employees/Create.tsx:191-208` |
| W5 | Medium | A lost/damaged request approved through the create-form path leaves two `is_current` cards (prior card retired only for `Correction`). | `ApproveCardRequestAction.php:75-82` |
| W6 | Medium | Dashboard "Active employees" = 3 while the DB has 5: the scoped query joins `current_assignment_id` and drops employees without a current assignment. | `DashboardMetricService::employeeQuery` (~810-824); `shots/40-dashboard-refreshed.png` |
| W7 | Medium | Non-numeric ids in admin URLs return HTTP 500 (`/users/abc/edit`, `/roles/undefined/edit`: "invalid input syntax for type bigint"). Routes lack `whereNumber`. | `routes/web.php:1015-1019`; `laravel.log` ERR-7SAOVOGRVYDS |
| W8 | Medium | Mail: System Settings seeds `mail_mailer=smtp` with an empty host, which overrides `.env` `MAIL_MAILER=log`; password-reset, new-user and holder-OTP mails fail with "Connection refused 127.0.0.1:587" while the UI reports success. `email_notifications_enabled=false` does not stop the OTP attempt. | `system_settings` rows; `RuntimeMailSettings.php:82`; `laravel.log` "Password reset email could not be sent", "Public ID check OTP email failed" |
| W9 | Medium | PHP upload limit (2 MB) is below the public evidence policy (PDF 5 MB, 75 MB total advertised): a 3 MB PDF produces Laravel's raw "The attachments.0 failed to upload."; the 4 MB profile-photo rule is also unreachable. | `ValidatesPublicSubmitter.php:93-99`; `shots/public-org-complaint-3mb.png` |
| W10 | Medium | No initial grievance handler/committee in the demo data: "Accept and route" fails for every case, so the officer workflow cannot start. | `grievance_routes`, `grievance_committees` empty |
| W11 | Medium | Employee update re-derives `full_name` from the name parts and overwrites the stored value. | `EmployeeController.php:764` |
| W12 | Low | `card_replacements.new_card_id` is never set; the replaced-card page links the first replacement, not the newest; replace rules differ between request, policy and action. | `ReplaceCardAction.php:85`, `IdCard.php:103-106` |
| W13 | Low | No "suspend" route although `CardStatus::Suspended` exists; `POST /id-cards/{card}/incident status=suspended` ends in a 405. | `routes/web.php:955-959` |
| W14 | Low | Audit noise: `card_printed` written twice per card; PNG export audited even when it returns 503; no `card_requested` row for replacement requests. | `IdCardExportController.php:83-88` |
| W15 | Low | Code rule in use can be archived without confirmation; `deletion_reason` stored as the literal string "undefined". | `resources/js/Pages/CodeRules/Show.tsx:90` |
| W16 | Low | `max_login_attempts` bounds differ: UI max 20, server max 50. Unchecking every role sends no `roles[]` and reports success with no change. | `SystemSettingsRegistry.php:573`, `UpdateSecuritySettingsRequest.php:39` |
| W17 | Low | Intermittent loss of the chosen issue category when a sessionStorage draft exists on the public complaint form (3 of 37 submissions stored `category_id` NULL). | `OrganizationFeedbackForm.tsx:542-547` |
| W18 | Low | Public form page views capped at 30/min per IP with a bare 429 page; throttle responses carry no `Retry-After`. | `routes/web.php:535-571` |
| W19 | Low | `card_requests` has no human-readable number (uuid only); `/service-complaints` inbox has no search. | UI |
| W20 | Low | Permission `audit-logs.viewAny` grants nothing (`AuditLogPolicy` checks `audit.view`). | policy |
| W21 | Medium | An employee without a current assignment is 403 even for a System Admin: `canAccessEmployee` calls `canAccessOrganization(null)` before the unrestricted-role check. | `OrganizationScopeService.php:75-78,101-104`; `shots/10-hr-employees-14-fail.png` |
| W22 | Low | CSV import confirm flashes the raw exception text ("Import failed and nothing was saved. SQLSTATE[25P02]…") to the user. | `EmployeeImportController.php:134-140`; `shots/hr-import-confirmed.png` |
| W23 | Low | Organization show page and list show no parent until the hierarchy version is published; child organizations can only be created inside a draft version. Confusing for operators, consistent with the model. | `OrganizationController.php:248-262` |

### Providers, cafeteria, transport

| ID | Sev | Title | Evidence / location |
|---|---|---|---|
| P1 | High | Seeded demo ID cards cannot be scanned by any provider portal: the seeder leaves `public_card_uuid` and `qr_payload` NULL and stores an argon2id `token_hash`, while the scan service resolves only `public_card_uuid` or `<id>\|<raw>` with SHA-256. The seeded transport provider is a `service_providers` row, not a `providers` row, so no portal user can be created for it. UAT step 6 is blocked out of the box (`card:backfill-public-uuids` fixes the cards). | `CafeteriaQrScanService.php:240-275`; `DatabaseSeeder`; `shots/portal-provider-users-create-before.png` |
| P2 | Medium | Second scan on the same day is accepted as an extra scan at full price because the policy's extra-scan rule defaults to `employee_paid`; the UAT script expects a refusal. Decide the default or the script. | `CafeteriaServicePolicyController.php:295` |
| P3 | Medium | Untranslated keys: provider-user permission checkboxes `providerUsers.permissions.provider_transport_*`; transport contract terms `transport.terms.*` (no `terms` block in the dictionary). | `Components/ProviderUsers/AccountUi.tsx:59`, `i18n/en/transport.ts` |
| P4 | Low | Transport portal boardings CSV is served as `text/html` (`streamDownload` without a content type). | `ProviderPortal/Transport/TransportTransactionController.php:54` |
| P5 | Low | English labels in the Amharic provider portal ("Provider Portal", "Menu", "Employee", "Subsidy", "Status", "Accepted", "Date"); ISO dates beside Ethiopian ones. | `shots/portal-am-*.png` |
| P6 | Low | "Add Cafeteria" form labels the cafeteria's own code "Provider Code"; scan page "Today's scans" panel is not refreshed after a scan; the 429 page on the provider login offers "Go to Dashboard" (the staff dashboard). | `CafeteriaForm.tsx:188`; `shots/portal-cafe-scan-duplicate.png` |
| P7 | Low | Access and assignment approvals may be done by their creator; only policies, settlements and contracts enforce a distinct approver. Provider login throttle is 5 POST/min per IP (shared NAT during UAT will hit it). | `routes/web.php:197` |

### Localisation and UI quality

| ID | Sev | Title | Evidence / location |
|---|---|---|---|
| L1 | Medium | Role names appear in English in the Amharic UI (header, users list, filters). | `HandleInertiaRequests.php:61`, `AppHeader.tsx:97` |
| L2 | Medium | Seeded organizations and organization types have NULL `name_am`, so Amharic screens show English names everywhere. | `OrganizationTypeSeeder` defines `name_am` but `firstOrCreate` never backfills |
| L3 | Medium | Audit log shows raw model names and event keys in both languages (`OrganizationUnit`, `User #14`, "Service Feedback Organization Qr Generated"). | `Pages/AuditLogs/Index.tsx:185-187` |
| L4 | Medium | Dashboard System-state card prints the raw code `NOT_CONFIGURED`. | `DashboardOverview.tsx:101`, `i18n/am/backup.ts:11` |
| L5 | Medium | Amharic validation messages name fields in Latin (`የemail መስክ አስፈላጊ ነው።`) and double the prefix (`የየይለፍ ቃል`). | `lang/am/validation.php:31,162ff` |
| L6 | Medium | No language or theme switcher on `/login`, forgot-password and MFA pages. | `Pages/Auth/Login.tsx` |
| L7 | Medium | Dark mode: active nav, active tab, filter button and links sit at 3.7–4.2:1 contrast (`#767eac`). | `AuthenticatedLayout.tsx` `darkThemeVariant()`; `shots/q-dark-*.png` |
| L8 | Medium | `/employees/create` shows "Preview unavailable" in red although the code preview succeeded: the aborted first request is treated as an error (axios cancellation is `CanceledError`, not `AbortError`); the text also fails contrast (axe serious). | `Components/code-rules/CodeRuleField.tsx:97-119` |
| L9 | Low | Raw i18n keys in the card lifecycle timeline (`idCards.status_submitted`) and on `/code-rules` (`codeRules.entityTypes.*`). | `CardLifecycleTimeline.tsx:72,79` |
| L10 | Low | English `aria-label`s ("Notifications alt+T", "Breadcrumb") in Amharic; "Landscape/Portrait" toggles untranslated; card-request list uses `name_en` directly. | `CardRequests/Index.tsx:171` |
| L11 | Low | Ethiopian dates omit the `ዓ.ም` suffix the terminology guide requires; `/reports` is referenced but is not a route. | `lib/calendar/dateFormat.ts:30` |
| L12 | Low | Tap targets 30–36 px (guideline 44); login page has no `<main>` landmark; 35 Tabs to reach content on `/employees` without the skip link. | axe `region`, `landmark-one-main` |

### Repository and operations hygiene

| ID | Sev | Title |
|---|---|---|
| R1 | Low | Committed at the repo root: `employeeId_db` (2.8 MB SQLite dev database), `sec-routes.json` (297 KB), empty `sec-audit.json`, `cognee_stderr.txt`, `omniroute_stderr.txt`. |
| R2 | Low | Pest has no per-test time limit and CI runs single-process (~3 h). `enforceTimeLimit` and `--parallel` would have caught the hang and B2 earlier. |
| R3 | Low | JS payload ~2.0–2.5 MB uncompressed per cold page across ~30 requests; when cookie and localStorage disagree on the locale both dictionaries load plus a full Inertia reload (+790 KB). |

## 8. What worked well

Password policy with precise reasons; TOTP enrolment, challenge and replay protection; multi-key login throttling; encrypted national ID and phone at rest with hashed lookups; redaction in audit payloads; CSRF and session handling; content-sniffed public uploads and one-time form tokens; server-side HTML sanitising for rich text; card print ordering rules and frozen print snapshots; API tokens shown once with endpoint scopes and request logging; consistent Ethiopic typography and calendar; keyboard-operable shell with skip links and focus trapping; no 500 on any of 349 crawled admin pages.

## 9. Performance re-measurement (quiet box)

With the testers and the Pest suite stopped (load average 0.5), the fifteen pages that were slowest during the crawl were loaded three times each as a System Admin (median shown; `php artisan serve`, no OPcache, no compression, English UI):

| Page | TTFB | DOMContentLoaded | load | network idle |
|---|---|---|---|---|
| /dashboard | 649 ms | 727 ms | 740 ms | 1.66 s |
| /employees | 602 ms | 701 ms | 706 ms | 1.61 s |
| /organizations | 951 ms | 1028 ms | 1034 ms | 1.94 s |
| /organizations/create | 662 ms | 740 ms | 745 ms | 3.35 s |
| /organization-unit-types/create | 589 ms | 681 ms | 690 ms | 2.70 s |
| /reporting-lines, /organizations/import-structure, /performance/plans, /users/create, /audit-logs, /hierarchy-versions, /transport/trips | 603–654 ms | 697–733 ms | 697–737 ms | 1.61–1.66 s |
| /card-requests, /id-cards, /vacancy-applications | 694–775 ms | 790–860 ms | 800–864 ms | 1.75–1.79 s |

So the multi-second timings seen during the crawl were CPU contention, not the product. Two observations remain: every page pays ~600 ms of server time before the first byte (a floor worth profiling: settings, shared props, permission loading), and the two create pages spend 2–3 s after `load` on secondary requests (code-rule preview, option lists). JS payload per cold page is 2.0–2.5 MB uncompressed (R3). Evidence: `results/80-perf-quiet.json`.

## 10. Test data left in the sandbox database

All QA records carry a `QA-` prefix or a `qa.*@demo.local` address: 14 QA users, one draft hierarchy version with two organizations, two units and five positions, two orphan employees without an assignment pointer (B1 fallout), four extra cards, four print batches and four requests on EMP-0003, 37 public submissions, three grievances and six drafts, a cafeteria provider with network, access, assignment, policies, two settled transactions and one settlement, a transport provider with vehicle, driver, route, schedule, two trips, a pass and a draft contract, three provider-portal users. Seeded data changed: `AA-2026-DEMO01.public_card_uuid` back-filled (P1 workaround); EMP-0003's full name was restored after W11 overwrote it.

## 11. Untested scope

- Holder OTP reveal on the public checker (mail and SMS cannot be delivered here, W8).
- Grievance hearings, decisions and letters (blocked by W10); discipline referrals; assessment questionnaires (none published).
- NFC provisioning; card PDF export (no route); print-batch rendering at scale.
- Offline boarding sync, GPS, transport NFC (feature flags off).
- Backups/PITR, scheduler, multi-node storage (environment).
- MFA-required roles beyond Super Admin (City Admin, Cafeteria Admin) in module journeys; testers used System Admin to avoid TOTP collisions.
- Load and capacity (see `docs/load-testing.md`; this pass ran on a shared 4-core sandbox).

## 12. Evidence index

All under `/tmp/claude-0/-home-user-EUISIS/04a59b1e-adf3-537d-b073-538f279f04b1/scratchpad/` (session-local; copy before the sandbox is reclaimed):

- `e2e/lib.js`, `e2e/01-auth.js`, `e2e/02-crawl.js`, `e2e/10-hr-*.js`, `e2e/20-idcards-*.js`, `e2e/30-public-*.js`, `e2e/40-admin-*.js`, `e2e/50-security-probes.sh`, `e2e/60-portal-*.js`, `e2e/70-quality-*.js`
- `e2e/results/*.json|log` (step logs, state, axe reports, timings), `e2e/shots/*.png` (screenshots)
- `pest-sqlite-junit.xml`, `pest-sqlite-failures.json`, `pest-pgsql-junit.xml`, `build.log`, `i18n.log`, `pint.log`, `seed.log`, `migrate.log`, `routes.json`
- `reports/*.md` (per-tester reports)
