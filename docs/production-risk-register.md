# Production risk register

Consolidated from the 2026-10-10 full-project production-readiness audit
(six read-only audits: scale/ORM, authorization/scope, infrastructure/statelessness,
concurrency/integrity, PostgreSQL scale, release exposure). Target: 3,000,000
employees, millions of daily transactions, multi-node behind a load balancer.

Severity: **P0** production blocker · **P1** critical before city-wide rollout ·
**P2** high · **P3** optimization. Status: `OPEN`, `FIXED (commit)`,
`NEEDS_DECISION`, `NEEDS_DATA_REVIEW`, `NEEDS_INFRA`.

Transport Management is **out of current production scope**; Transport findings
are recorded only where Transport code is exposed or touches shared code.

## P0 — production blockers

| ID | Area | Finding | Evidence | Status |
| --- | --- | --- | --- | --- |
| SEC-1 | Authorization | Deleting a user's last organization-scope row makes the account city-wide (no scope = unrestricted). An Organizational Admin can escalate any scoped user. | `UserOrganizationScopeController.php:105-121`, `OrganizationScopeService::isUnrestricted` | FIXED (fa42d62) |
| SEC-2 | Authorization / PII | Audit log viewer is unscoped and returns raw old/new values containing employee phone, email, address, date of birth. | `AuditLogController.php:25-55`, `WriteAuditLogAction.php:21-58` | FIXED (7fbc4f9) |
| SEC-3 | Authorization / PII / Scale | Entitlements page: unscoped, unpaginated, raw `Employee` models, all-employee dropdown; `store()` grants across organizations. | `EntitlementController.php:26-56` | FIXED (ab66a8d) |
| SEC-4 | Authorization / PII | Unscoped list pages leaking raw employee PII: vacancy applications, transfer dashboard, card print batch create/index. | `VacancyApplicationController.php:32-45`, `TransferDashboardController.php:25-63`, `CardPrintBatchController.php:25-50` | FIXED (54148ae) |
| CON-1 | Integrity | Vacancy transfer implemented twice / position over-filled (no lock, no re-check, no unique on `vacancy_application_id`). | `CompleteVacancyTransferAction.php:32-75` | FIXED (19d2616) |
| CON-2 | Integrity / money | Service-transaction API: no transaction, SELECT-then-INSERT idempotency, quota overrun. | `RecordServiceTransactionAction.php:50-75` | FIXED (692e8c6) |
| CON-3 | Integrity | Two live/current ID cards per employee possible (unlocked replace/activate, no DB constraint). | `ReplaceCardAction.php`, `ActivateCardAction.php`, `ApproveCardRequestAction.php:83-91` | FIXED (19d2616) |
| INF-1 | Statelessness | All persistent uploads/generated files on node-local disk; hardcoded `'local'`; no S3 adapter; code relies on local paths. | `config/filesystems.php`, ~20 services | FIXED (a69b490) — discipline/service-submission streaming is in uncommitted WIP files |
| DB-1 | Indexes | `employee_assignments` has no index on `employee_id`, `organization_id`, `position_id`. | foundation migration L133-136 | FIXED (e64de35) |
| DB-2 | Schema drift | Fresh migrate omits unique `transaction_number` and composite indexes on `cafeteria_transactions`. | `2026_05_26_000004`, CT-legacy migration | FIXED for fresh installs (e64de35) — run `data:audit-duplicates` and compare indexes on the live DB |
| DB-3 | Search | Employee (and other) search uses `ILIKE '%x%'` with no trigram index → seq scan on 3M rows. | `EmployeeController.php:174-180` + 142 sites | FIXED (e64de35, pg_trgm GIN) |
| SCL-1 | Scale | Positions index/status pages load every position in scope; whereIn > 65,535 bind limit. | `ScopedPositionStructureService.php`, `PositionController.php:78-91` | FIXED (0484bd4) |
| SCL-2 | Scale | Employee index/create/edit ship every position/unit as dropdown props. | `EmployeeController.php:133-139,335-357,509-511` | FIXED (3ee116e, 3254bea) |
| SCL-3 | Scale | Cafeteria exclusion create lists all employees. | `EmployeeCafeteriaExclusionController.php:57` | FIXED (3e3a8c8) |
| SCL-4 | Scale | ID-card reprint notifies by loading every active user + per-user policy check, synchronously. | `EmployeePortalNotifier.php:16` | FIXED (fa3d24b) |
| SCL-5 | Scale / money | Cafeteria statement export `period=all`; provider payment summary loads full period per page view; settlement draft loads+locks every unsettled transaction; analytics `employee_usage` unbounded. | `CafeteriaTransactionController.php:103`, `ProviderPaymentClaimService.php:19`, `CafeteriaSettlementService.php`, `CafeteriaAnalyticsReportService.php` | FIXED (152394c, 2a6c349) |
| SCL-6 | Scale | Assessment eligibility rebuild = whole-workforce single transaction in a web request; target preview scans all employees per click. | `AssessmentEligibilityService.php:127-136`, `AssessmentTargetResolver.php:102` | FIXED (726eaf9) |
| SCL-7 | Scale | Daily-activity reminder job walks ~3M employees every 15 minutes; unindexed exclusion subquery. | `DailyActivityReminderService.php:72-99` | FIXED (96f8681) |

## P1 — before city-wide rollout

| ID | Finding | Status |
| --- | --- | --- |
| SEC-5 | Empty accessible-org set treated as "all" in ID cards, transfer announcements, cafeteria providers. | FIXED (fc8875f) |
| SEC-6 | Cafeteria employee exclusions unscoped (read + write). | FIXED (3e3a8c8) |
| SEC-7 | Scoped Cafeteria Admin holds `cafeteria_providers.viewAll` → city-wide transactions; provider policy update/archive unscoped. | PARTIAL (694d4fd: update/archive scoped) — `viewAll` for scoped admins NEEDS_DECISION |
| SEC-8 | Transport admin pages unscoped (resolved by Transport gating, REL-1). | FIXED by REL-1 (Transport disabled by default, 260e62a) |
| SEC-9 | Raw `Employee` serialization bypasses `employees.viewPii` (transfer applications, print batches). | FIXED (7593906) |
| SEC-10 | Profile photos / logos / system assets stored on public disk with client extension → stored XSS via `.html`. | FIXED (07424b6) |
| SEC-11 | Git history contains real credentials (`.env.backup-*`, commit `19ab6b8`) incl. a Gmail app password. | NEEDS_DECISION — owner must revoke; history rewrite is destructive |
| CON-4 | No DB backstop for one current assignment per employee. | FIXED (19d2616, partial unique index) |
| CON-5 | Position occupancy counted inconsistently (assignments vs occupancies); import bypasses lock. | NEEDS_DECISION — registration allows one occupant per position (assignments, locked; import uses the same action), transfers allow the approved slots (occupancies). Decide which is authoritative. |
| CON-6 | Transfer rejection unlocked; can relabel approved/transferred applications. | FIXED (c9e399b) |
| CON-7 | Discipline service takes no row locks → duplicate decisions/deliveries/cases. | OPEN — discipline module is uncommitted WIP; reported to owner, not changed |
| CON-8 | Settlement finalize vs transaction reversal race → provider paid for reversed meal. | FIXED (2a6c349) |
| CON-9 | Provider ledger written outside scan transaction; no unique per transaction; reversals never debited. | FIXED (be720f9) |
| CON-10 | Duplicate employees by national ID (hash index not unique). | NEEDS_DATA_REVIEW — `data:audit-duplicates` lists shared national IDs; add the unique index after review |
| INF-2 | Long jobs (600s) exceed queue `retry_after` (90s) → duplicate execution. | FIXED (e2a6450) |
| INF-3 | Single `default` queue; OTP/SMS/most notifications synchronous; ~34 synchronous exports. | PARTIAL (e2a6450, 01e5d51: notifications queued) — exports stay synchronous but row-capped |
| DB-4 | Audit log search: `ILIKE` on bigint column (500), full COUNT per page, `whereDate`. | FIXED (7fbc4f9) |
| DB-5 | Dashboard: ~40–50 COUNTs per user, `whereDate` on event tables, per-user cache. | FIXED (fbf2043) |
| DB-6 | Cafeteria transaction number random 6 chars → ~2,000 collisions/day at 3M scans. | FIXED (97fa479) |
| DB-7 | Missing FK indexes on hot tables (`id_cards.employee_id`, `employee_duplicate_flags.employee_id`, etc.). | FIXED (e64de35) |
| DB-8 | Large-table migrations: non-concurrent indexes, validated FKs, row-by-row backfills, type-change rewrites. | PROCEDURE (docs/database-scale.md, large-table migration rules); the new integrity and index migrations follow it. Older migrations already ran. |
| DB-9 | No retention/pruning for audit, notifications, security events, verification logs, reminders. | NEEDS_DECISION (retention periods) |
| SCL-8 | Transfer announcement job: whole workforce, no timeout, re-run by queue, per-employee settings query. | FIXED (4826aae) |
| SCL-9 | Daily-activity citywide reports walk employees × 92 days in request. | FIXED (bc85d83, request budget) |
| SCL-10 | Unbounded pickers/loads: subsidy ledger, card request create, print batch, transfer form positions, unit/vacancy pickers, org-structure API, grievance reports in PHP. | FIXED (4c7c00f, 8e23f3c, d374c7b, f1afcf4) — org-structure API `depth=employee` still returns every occupant of one organization (API contract; NEEDS_DECISION) |
| REL-1 | Transport fully built and reachable (admin, provider portal, roles, sidebar, homepage says "available"). | FIXED (260e62a) |
| REL-2 | `DatabaseSeeder` has no production guard; creates demo provider/user accounts. | FIXED (f4714b6, 1917922) |

## P2 / P3 (summary)

- INF: ~~five scheduled tasks lack `onOneServer()`~~ (FIXED e2a6450); ~~only `/up` health endpoint~~ (FIXED a5d9c9a: `/health/live`, `/health/ready`); trusted-proxy/HTTPS depend on env (now reported by `production:readiness`, d90c954); no DB SSL/timeouts; CI runs sqlite only (no pgsql, pint, tsc, audits); file uploads not backed up; maintenance driver `file`; node-local logs.
- SEC: transfer announcement search escapes scope via top-level `OR`; position establishments unscoped; provider-portal transport cross-provider ids; `EnsureProviderServiceScope` ignores expiry; org-unit relationship policy unscoped; self-registered employees unscoped (latent).
- CON: overlapping field-work approvals; IdCard↔Employee lock-order deadlock; grievance letter PDF rendered inside locked transaction; double card issuance; code-generator misplaced match arms.
- DB: notifications unindexed for listing/unread; random v4 UUID keys on notifications and scan uniques; users list sort unindexed; feedback `whereDate`.
- SCL: Excel exports all `FromArray`; offset `chunk()` walks; N+1 in two resources; backfill commands `->get()`.
- REL: two sidebar links can 403 for custom roles (transfer dashboard, assessment oversight); Court Cases placeholder in navigation; ~20 settings stored but never read.

## Checked and sound

Public ID checker and public QR feedback (opaque tokens, masked output, throttled);
field-work GPS precision gating; grievance and discipline access services; cafeteria
provider-portal isolation; employee self-service downloads; reference/code generation
locking; grievance decision lock order; field-work check-in/out; daily-activity
uniqueness; regular transfer completion; cafeteria daily-entitlement `active_key`;
sessions/cache/queue on a shared store (database); MFA state multi-node safe.
