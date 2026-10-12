# Unified Public Feedback, Suggestion & Complaint — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the typed Employee feedback QR flow (Feedback · አስተያየት, Suggestion · ጥቆማ, Complaint · ቅሬታ) so the same three submissions also target an Organization through its own stable public QR, with one server-side target resolver, categories, routing, type-specific workflows, granular permissions, management screens, reports and controlled discipline referral — without creating a second feedback architecture.

**Architecture:** Keep `employee_service_feedback` as the one submissions table and generalise it (target_type / source_type / organization target / snapshots / category / timestamps) under a database-level target-integrity constraint. Add `organization_feedback_qr_codes` for the Organization QR identity (opaque 64-hex token, one active per organization, rotation and revocation audited, tokens never reused). `PublicFeedbackTargetResolver` turns an opaque token into a `TargetContext`; `PublicFeedbackSubmissionService` creates submissions transactionally (snapshot, reference, status history, routing, assignment, post-commit notifications). New tables for categories, routing rules, status history, assignment history, subject employees and discipline referral requests follow the grievance module's shapes.

**Tech Stack:** Laravel 12, PostgreSQL (SQLite for the fast suite), Inertia React 18 + TypeScript, Pest, chillerlan/bacon QR renderers via `IdCardQrCodeRenderer`, DomPDF, the `@euisis/ui` kit.

**Spec:** the Codex brief pasted on 2026-10-12 ("UNIFIED PUBLIC FEEDBACK, SUGGESTION & COMPLAINT MANAGEMENT SYSTEM"), received up to section 122 item 49; sections after that were not received. Digest: [docs/public-feedback-brief-digest.md](../../public-feedback-brief-digest.md). Baseline docs: [docs/public-service-feedback.md](../../public-service-feedback.md), [docs/employee-feedback-security.md](../../employee-feedback-security.md).

## Global Constraints

- Public Complaint ≠ Employee Grievance ≠ Employee Discipline ≠ Appeal: separate tables, permissions, workflow, navigation, reports (§1, 63, 64).
- Transport Management stays out of scope (§2).
- Reuse the existing Employee feedback QR token (`employee_feedback_tokens`, 64-hex, printed on the card back); never rotate it for feature or HR changes; no PII in any QR (§4, 5); do not weaken OTP-gated ID verification (§6).
- The QR resolves the target server-side; the browser never supplies an authoritative `employee_id` / `organization_id`; no target search or selector on public pages (§14, 17, 19).
- Target types exactly `EMPLOYEE` | `ORGANIZATION`; source types exactly `EMPLOYEE_CARD_QR` | `ORGANIZATION_FEEDBACK_QR`; Organization Unit QR is out of scope (§8, 15, 16).
- Exactly one primary target per submission, enforced in PHP and by a PostgreSQL CHECK constraint; source/target combinations validated; original target immutable (§23, 24, 62).
- Historical context is snapshotted at submission; transfers and renames never rewrite old rows (§25, 26).
- Submission types are the enum `feedback` | `suggestion` | `complaint`; never a generic comment (§21).
- No automatic disciplinary, EPMS, salary or employment effect from any submission; referral is a separate record (§27, 30, 58–60).
- Anonymous is not uncontrolled: CSRF, rate limits on shared cache, idempotency, attachment limits (§37–39, 65–67). Plain text only; escape on output (§68).
- Reference numbers through `CodeRuleService`; never raw IDs publicly (§40, 42, 97).
- Every management query: permission + `OrganizationScopeService` + workflow role (§56, 96). Target employee never adjudicates their own complaint (§54).
- Additive, PostgreSQL-safe migrations; no `migrate:fresh`, no truncation; existing rows preserved (§113–116).
- Dates canonical Gregorian in the DB; Ethiopian display in Amharic (§78). Bilingual UI; no raw enums (§77).
- NEEDS_DECISION, not invention, for: SLA values, retention, withdrawal, per-organization settings authority, category governance, suggestion outcome vocabulary, tracking scheme (§32, 41, 49, 91, 94, 110, 112).
- Commit per phase; TDD (failing test first); verify on SQLite and PostgreSQL; `tsc`, i18n key parity, Pint, `vite build`; Amharic browser walkthrough per phase.
- Baseline rule for this repository: never rewrite history; stage only feedback hunks from files that also carry unrelated uncommitted work (scratchpad `stage_head_plus.py`).

## Review Focus

1. A forged `employee_id` / `organization_id` / `type` in a public POST must be ignored or rejected, never honoured (§17, 34 of tests).
2. A revoked or rotated Organization QR token and an inactive Organization must produce the generic unavailable page and accept nothing (§13, 22, 75).
3. The PostgreSQL CHECK constraint must reject rows that PHP validation did not see (direct inserts) — run the constraint tests on `phpunit.pgsql.xml.dist`.
4. The target employee holding every permission must still be unable to list, view, assign, transition, download or refer their own complaint (§54, 55, 66).
5. Routing with no matching rule must leave the submission `unrouted` and notify an administrator, never assign the target employee or drop the row (§46).

---

## Phase 0 — Baseline: adopt the typed employee submissions (commit 1)

**Files:** the feedback-domain subset of the working copy (listed in scratchpad `stage-baseline.py`): typed submissions, complaint attachments, `ServiceFeedbackType`, submission inbox pages, the two migrations of 2026-10-10, docs. Shared files patched: `CodeRuleEntityType` (PSC case), `CodeGeneratorService` (PSC arm), `permissions.php` (two complaint permissions), `navigation.ts` (two keys), `AppSidebar.tsx` (two entries under Service Management).

- [ ] Stage with `python scratchpad/stage-baseline.py`; `git diff --cached --stat`.
- [ ] `vendor/bin/pest tests/Feature/ServiceFeedbackPublicTest.php tests/Feature/ServiceFeedbackAdminTest.php tests/Feature/ServiceFeedbackLocalizationTest.php tests/Feature/Performance/ServiceFeedbackPerformanceTest.php tests/Unit/AppSidebarNavigationTest.php` → all pass.
- [ ] `git show :routes/web.php | php -l`-style syntax check of every staged PHP file.
- [ ] Commit: `chore(feedback): adopt the typed public submission baseline (feedback, suggestion, complaint)`.

## Phase 1 — Unified target model and resolver (commit 2)

**Files:**
- Create: `app/Enums/PublicFeedbackTargetType.php` (`Employee = 'employee'`, `Organization = 'organization'`), `app/Enums/PublicFeedbackSourceType.php` (`EmployeeCardQr = 'employee_card_qr'`, `OrganizationFeedbackQr = 'organization_feedback_qr'`), `app/Enums/SubmitterIdentityMode.php` (`Anonymous`, `Identified`, `Optional`).
- Create: `database/migrations/2026_10_12_000100_unify_public_feedback_targets.php` — on `employee_service_feedback`: `target_type` string(16) default `employee` + index, `source_type` string(32) default `employee_card_qr`, `organization_target_id` uuid nullable FK organizations restrictOnDelete, `source_organization_qr_id` uuid nullable, `target_name_snapshot` string nullable, `target_code_snapshot` string(60) nullable, `category_id` uuid nullable, `subject` string(200) nullable, `submitter_identity_mode` string(24) default `optional`, `submitted_at` timestamp nullable (backfill from `created_at`), `acknowledged_at`, `assigned_at`, `resolved_at`, `closed_at` timestamps nullable; `employee_id` → nullable (`->change()`); indexes `[target_type, type, submitted_at]`, `[organization_id, status, submitted_at]`, `[organization_target_id, submitted_at]`; PostgreSQL only: CHECK `esf_target_integrity_chk` and CHECK `esf_source_target_chk`. Down: drop constraints/indexes/columns; refuse when organization-target rows exist.
- Create: `app/Services/ServiceFeedback/TargetContext.php` (readonly DTO: `targetType`, `sourceType`, `employeeId`, `organizationId` (target), `employeeAssignmentId`, `contextOrganizationId`, `contextUnitId`, `contextPositionId`, `sourceId`, `targetNameSnapshot`, `targetCodeSnapshot`, `publicDisplay` array; `assertConsistent()`).
- Create: `app/Services/ServiceFeedback/PublicFeedbackTargetResolver.php` — `resolveEmployeeToken(string $token): ?TargetContext` (wraps `EmployeeFeedbackTokenService::resolveForPublic` + current assignment), `resolveOrganizationToken(string $token): ?TargetContext` (Phase 2 fills it; returns null until then).
- Modify: `app/Models/EmployeeServiceFeedback.php` (fillable, casts, `organizationTarget()` relation, `isEmployeeTarget()`), `app/Services/ServiceFeedback/PublicServiceFeedbackService.php` (write `target_type`, `source_type`, `submitted_at`, snapshots on both `submit` paths).
- Test: `tests/Feature/PublicFeedback/TargetIntegrityTest.php`.

- [ ] Write failing tests: `it('stores an employee submission with target_type employee and source employee_card_qr')`, `it('rejects a row with both targets or neither target in PHP')` (model `saving` guard throws `InvalidArgumentException`), `it('rejects a row with both targets at the database on PostgreSQL')` (skip on sqlite), `it('backfills submitted_at for legacy rows')`.
- [ ] Run → fail (columns missing). Implement migration, enums, DTO, resolver, model guard. Run SQLite + PostgreSQL → pass. Pint. Commit `feat(feedback): unified target model — target/source types, snapshots, integrity constraint`.

## Phase 2 — Organization feedback QR (commit 3)

**Files:**
- Create: `database/migrations/2026_10_12_000200_create_organization_feedback_qr_codes.php` — `id` uuid, `organization_id` FK restrictOnDelete, `public_token` string(64) unique, `status` string(16) (`active|disabled|revoked`) index, `enabled_at`, `disabled_at`, `revoked_at`, `revoked_by` FK users nullOnDelete, `revocation_reason` string(500), `rotated_from_id` uuid nullable, `last_scanned_at`, `scan_count` unsigned int default 0, `created_by`, `updated_by`, timestamps; partial unique index `org_qr_one_active` on `organization_id` where `status = 'active'` (PG + SQLite).
- Create: `app/Enums/OrganizationFeedbackQrStatus.php`; `app/Models/OrganizationFeedbackQrCode.php` (`$hidden = ['public_token']`, `publicUrl()`, `acceptsFeedback()`); `app/Services/ServiceFeedback/OrganizationFeedbackQrService.php` — `ensureActive(Organization, User, Request): OrganizationFeedbackQrCode` (idempotent, locks the organization row), `disable`, `enable`, `rotate(qr, reason)` (revokes old, creates new, keeps history), `revoke(qr, reason)`, `activeFor(Organization)`, `resolveForPublic(string $token)` (active QR + active organization only), `recordScan`; each state change audited with new `AuditEventType` cases `OrganizationFeedbackQrGenerated/Disabled/Enabled/Rotated/Revoked/Exported`.
- Create: `app/Http/Controllers/Web/OrganizationFeedbackQrController.php` (`show`, `generate`, `disable`, `enable`, `rotate`, `revoke`, `exportPng`, `exportPdf`) reusing `IdCardQrCodeRenderer` and a new `resources/views/pdf/organization-feedback-qr.blade.php` (logo, bilingual name, QR, instruction, URL text).
- Create: `resources/js/Pages/ServiceFeedback/OrganizationQr.tsx` (status card, preview, actions with confirm + reason, scan/submission summary); link from `Pages/Organizations/Show.tsx`.
- Permissions: `service_feedback.organization_qr.view|create|export|disable|rotate` in `permissions.php` (+ migration registering them, granted to no role; `DefaultRoleMatrix` city admin only through the existing "all permissions" path).
- Routes under the admin group: `/organizations/{organization}/feedback-qr[...]` named `organizations.feedback-qr.*`.
- Test: `tests/Feature/PublicFeedback/OrganizationFeedbackQrTest.php` — brief §118 items 11–15, 21–23, 30 plus "reopening the page does not regenerate", "revoked token never reassigned".

- [ ] Failing tests → implement → SQLite + PostgreSQL → i18n keys (`serviceFeedback.orgQr*`) → tsc → build → walkthrough → commit `feat(feedback): organization feedback QR — stable token, rotation, revocation, print/export`.

## Phase 3 — One public flow for both targets (commit 4)

**Files:**
- Modify: `routes/web.php` — public routes `GET /feedback/{orgToken}`, `GET /feedback/{orgToken}/{type}`, `POST /feedback/{orgToken}/feedback`, `POST /feedback/{orgToken}/{type}` (`throttle:service-feedback-submit`), names `public-feedback.organization.*`; keep employee routes unchanged.
- Modify: `PublicServiceFeedbackController` → delegates to `PublicFeedbackTargetResolver` and a new `PublicFeedbackSubmissionService::submit(TargetContext $target, ServiceFeedbackType $type, array $data, Request $request): EmployeeServiceFeedback` (transaction per §106: validate target active, type enabled, identity policy, idempotency token, snapshot, reference for complaints (`PSC`) and configurable for others, create, initial status history (Phase 5 adds), commit, then queue notifications). `PublicServiceFeedbackService::submit/submitEntry` become thin wrappers to keep the committed tests green.
- Modify: `resources/js/Pages/Public/ServiceFeedback.tsx` — prop `target: { type: 'employee'|'organization', display: {...} }`; organization landing shows logo + name + short name; receipt shows type, target confirmation, reference, submitted-at (localized); no target selector anywhere.
- Create: `resources/js/Components/public/FeedbackTargetCard.tsx`.
- Idempotency: `PublicFeedbackFormToken` (cache-backed one-time token in the form, `form_token` hidden input validated and consumed atomically via `Cache::lock`/`add`).
- Tests: `tests/Feature/PublicFeedback/OrganizationPublicFlowTest.php` (§118 16–20, 24; §120 31–35; §121 40 duplicate POST) and additions to `ServiceFeedbackPublicTest` for forged IDs (§117 5–6).

- [ ] Failing tests → implement → both drivers → tsc/i18n/build → Amharic walkthrough of the organization landing and the three forms → commit `feat(feedback): organization-targeted public flow through the shared resolver and submission service`.

## Phase 4 — Categories (commit 5)

**Files:** migration `public_feedback_categories` (`id`, `code` unique, `name_en`, `name_am`, `submission_types` json, `target_types` json, `organization_id` nullable FK (null = city-wide), `is_active`, `sort_order`, `created_by`, `updated_by`), FK `employee_service_feedback.category_id`; model `PublicFeedbackCategory`; `PublicFeedbackCategoryService::optionsFor(TargetContext, ServiceFeedbackType)`; admin CRUD `PublicFeedbackCategoryController` + page `ServiceFeedback/Categories/Index.tsx` (FilterPanel/TableCard kit, modal form, deactivate instead of delete when referenced); permission `service_feedback.manage_categories`; public forms get a category select when options exist; validation `category_id` must be applicable (type + target + organization scope). Tests `PublicFeedbackCategoryTest` (§120 35, scope §32, no delete when referenced §33).

- [ ] TDD cycle, both drivers, build, walkthrough, commit `feat(feedback): public feedback categories`.

## Phase 5 — Workflow, history, assignment and routing (commit 6)

**Files:** migrations `public_feedback_status_history` (`submission_id`, `from_status`, `to_status`, `actor_id` nullable, `reason`, `transitioned_at`), `public_feedback_assignments` (`submission_id`, `handler_type`, `handler_id`, `assigned_by`, `assigned_at`, `ended_at`, `reason`), `public_feedback_routing_rules` (`organization_id` nullable, `target_type` nullable, `submission_type` nullable, `category_id` nullable, `handler_type` (`user|organization_unit|role`), `handler_id`, `priority`, `effective_from`, `effective_to`, `is_active`, `created_by`); enum `ServiceFeedbackStatus` gains `UnderReview`, `Assigned`, `Accepted`, `NotAdopted`, `Closed`, `Unrouted` (existing `Pending/Reviewed/Resolved/Hidden` kept); `PublicFeedbackWorkflow` (allowed transitions per type), `PublicFeedbackRoutingService::resolve(submission): ?array` (most specific active rule wins: organization > target type > submission type > category, then priority), `PublicFeedbackAssignmentService`; submission service calls routing after create (unrouted + admin notification when none); admin inbox actions (assign/reassign, transition with reason) in `ServiceSubmissionController`; routing rules admin page `ServiceFeedback/RoutingRules/Index.tsx`; permissions `service_feedback.assign|reassign|resolve|close|manage_routing`; policy: target employee cannot transition/assign (§54). Tests `PublicFeedbackWorkflowTest`, `PublicFeedbackRoutingTest` (§46 unrouted, §47 scope).

- [ ] TDD cycle, both drivers, build, walkthrough, commit `feat(feedback): type-specific workflow, status/assignment history and routing rules`.

## Phase 6 — Identity, visibility and settings (commit 7)

**Files:** settings group `service_feedback` in `SystemSettingsService` (`enabled`, `identity_mode`, `allow_attachments`, `allow_rating`, `feedback_enabled`, `suggestion_enabled`, `complaint_enabled`, `captcha_enabled` (honoured only if a CAPTCHA provider exists), `tracking_enabled` = false; per-organization overrides NEEDS_DECISION → documented, not built); submitter contact encrypted at rest (`encrypted` casts on `client_name`/`client_contact`? — NEEDS_DECISION whether to re-encrypt legacy rows; new rows encrypted, legacy read-through), permissions `service_feedback.view_submitter_identity`, `service_feedback.view_sensitive_attachments`, `service_feedback.view_employee_target`, `service_feedback.view_organization_target`; DTO masking in inbox/detail; policy updates; settings page `ServiceFeedback/Settings.tsx`. Tests `PublicFeedbackVisibilityTest` (§36, 55–57, 86), `PublicFeedbackSettingsTest` (type disabled → 404 on the public form).

- [ ] TDD cycle, commit `feat(feedback): identity modes, granular visibility permissions and settings`.

## Phase 7 — Management navigation, dashboard, reports, exports (commit 8)

**Files:** sidebar group `publicFeedback` (label "Public Feedback Management · የተገልጋይ አስተያየት አስተዳደር") replacing the Service Feedback group: dashboard, all submissions, feedback, suggestions, complaints, organization targets, categories, routing rules, organization QR codes (list), reports, settings; dashboard metrics by target type and status including unrouted; inbox filters `target_type`, `organization_target_id`, `category_id`, `status`, `handler`; reports with target filters; queued CSV/XLSX export job `ExportPublicFeedbackReport` (private disk, formula-safe, audited) mirroring the organization-design export; employee-level report gated by `view_employee_target` and documented against §82. Tests `PublicFeedbackReportsTest`, sidebar test update.

- [ ] TDD cycle, build, walkthrough, commit `feat(feedback): public feedback management navigation, dashboard, reports and exports`.

## Phase 8 — Notifications, acknowledgement, abuse protection (commit 9)

**Files:** `app/Notifications/PublicFeedbackNotification.php` (database + mail, no narrative in mail), events after commit (`PublicFeedbackSubmitted`, `Assigned`, `Unrouted`, `Resolved`, `Referred`), queued acknowledgement to an identified submitter (mail only; SMS NEEDS_DECISION), rate limiter per QR source + IP on the configured cache store (Redis when `CACHE_STORE=redis`), request size limits documented, upload hardening checks (double extension, executable MIME). Tests `PublicFeedbackNotificationTest`, `PublicFeedbackAbuseTest` (§121 39, 41).

- [ ] TDD cycle, commit `feat(feedback): post-commit notifications, acknowledgement and abuse protection`.

## Phase 9 — Subject employees and controlled discipline referral (commit 10)

**Files:** migrations `public_feedback_subject_employees` (`submission_id`, `employee_id`, `employee_assignment_id` snapshot, `subject_role`, `added_by`, `added_at`), `public_feedback_referrals` (`submission_id`, `kind` = `discipline`, `status` `requested|accepted|declined|withdrawn`, `requested_by`, `decided_by`, `decided_at`, `notes`, `disciplinary_case_id` uuid nullable — set only by the discipline module, never here); `PublicFeedbackReferralService` (target employee cannot request/decide on own complaint; permission `service_feedback.refer_discipline`); detail page sections. Tests `PublicFeedbackReferralTest` (§122 45–47: no grievance, no discipline case, no appeal created).

- [ ] TDD cycle, commit `feat(feedback): investigation subjects and controlled discipline referral requests`.

## Phase 10 — Hardening, docs, UAT and readiness gate (commit 11)

- IDOR sweep over every new management and public route with a foreign-scope user and the target employee; XSS payload stored and escaped (§121 37); enumeration (sequential IDs never accepted) (§121 43–44); log hygiene test (no complaint text / contacts in logs).
- Docs: `docs/public-feedback.md` (architecture, tables, routes, permissions, settings, NEEDS_DECISION registry, migration safety, rollback), `docs/public-feedback-uat.md`, `docs/public-feedback-readiness.md` (READY / CONDITIONAL READY / NOT READY), update `docs/public-service-feedback.md` and `docs/employee-feedback-security.md`.
- Run of record: `tests/Feature` + `tests/Unit` on SQLite; the feedback suites on PostgreSQL; tsc; build; i18n parity; `composer audit`; `npm audit`.
- Commit `docs(feedback): UAT record and readiness gate`.
