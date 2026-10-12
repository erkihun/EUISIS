# Public Feedback — QA, hardening, load test and production gate — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Audit, prove and gate the unified public feedback implementation (commits `b060d0c`…`84d5fe5` + the documentation commit) against the 106-section QA brief, without rebuilding it.

**Architecture:** No new architecture. Every gap found is fixed inside the existing module (`app/Services/ServiceFeedback`, `EmployeeServiceFeedbackPolicy`, the public controller, the migrations) and proven by a Pest test first. Operational tooling plugs into what exists: `production:readiness`, `scale:generate` + `loadtest/k6`, `data:prune-retention`, `RequestCorrelationId`.

**Tech Stack:** Laravel 12, Pest, PostgreSQL dev DB `aaemployeedb` (test DB blocked by `pg_trgm.dll`), SQLite in-memory suite, k6 against local `php -S` nodes, Playwright MCP.

**Spec:** the QA brief pasted on 2026-10-12 ("principal Laravel 12/PostgreSQL production QA architect … UNIFIED PUBLIC FEEDBACK MANAGEMENT"); digest of the original build brief: `docs/public-feedback-brief-digest.md`.

## Global Constraints

- Do not add a second employee QR; the printed card feedback QR (`EmployeeFeedbackToken`, `/service-feedback/{token}`) is the employee identity.
- Targets stay `employee` and `organization`; no unit/service/position targets.
- Original target never changes; handlers, subjects and referrals are relations.
- Public complaint ≠ grievance ≠ discipline ≠ appeal; no EPMS/status side effects.
- Never guess legacy data; never invent SLA, retention, identity policy, CAPTCHA provider.
- Transport stays out of scope; no destructive production operations; `migrate:fresh`/truncate never on the dev DB.
- Commit per task; routes/web.php via `stage-phase2-routes.py`; permissions.php and DefaultRoleMatrix patch-staged against HEAD; AppSidebar.tsx and navigation.ts patch-staged against HEAD; leave the user's WIP untouched; trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Report only what was measured; the laptop numbers are relative, never "supports 3,000,000 employees".

## Review Focus

1. A public POST carrying `status`, `resolved_at`, `assigned_to`, `employee_id`, `organization_target_id`, `target_type`, `source_type` must be ignored on both flows — matrix test.
2. Revoking or disabling a QR must stop the next request without any cache delay — stability/invalid-state test.
3. An attachment whose storage write fails must roll the submission back and tell the visitor, never show a receipt — failure-behaviour test.
4. A user with every grant but a foreign scope, and the target employee with every grant, must get 403 on every route — hardening suite (exists) extended to the new routes.
5. The main dashboard's generic "pending feedback" count must not quietly include unrouted complaints or suggestions — dashboard audit.

---

### Task QA-1: Matrix, stability, tampering, constraints, historical context (commit 1)

**Files:** `tests/Feature/PublicFeedback/PublicFeedbackMatrixTest.php` (six combinations ×: target, type, source, routing, history, permissions), `PublicFeedbackQrStabilityTest.php` (employee token unchanged by position/org/unit change, reprint, category, service, workflow; org token unchanged by rename, logo, structure version, admin change, service, category, routing), `PublicFeedbackTamperingTest.php` (forged ids/types/hidden inputs/status fields on both flows; replay against another target; concurrent duplicate POSTs), `PublicFeedbackContextTest.php` (transfer after submission keeps old context; org rename keeps snapshot; archived employee/org and revoked QR keep rows — FK review), `PublicFeedbackQueryBudgetTest.php` (landing request query count ≤ 12 for both flows; inbox paginated).
- [ ] Write the tests; run → identify real failures; fix only real defects (e.g. missing FK restrict/null behaviour, missing unique index on `reference_number`, pagination of the institution QR list); commit `test(feedback): six-combination matrix, QR stability, tampering, constraint and context proofs`.

### Task QA-2: Domain boundaries, dashboard audit, data quality, production check (commit 2)

**Files:** `tests/Feature/PublicFeedback/PublicFeedbackBoundaryTest.php` (no grievance/discipline/appeal/EPMS rows; KPI source excludes suggestions and complaints; main dashboard pending count is feedback-only), `app/Console/Commands/PublicFeedbackDataQuality.php` (`feedback:data-quality {--repair}`: active org without QR, QR without org, duplicate active QR, submission without valid target, invalid source/target, complaint without routing, broken category reference, invalid handler, legacy ambiguous rows → NEEDS_DATA_REVIEW; repair only deterministic technical problems), `ProductionReadiness.php` additions (tables, settings group, code rule for `public_service_complaint`, private disk writable, queue async, shared cache, failed exports, routing coverage warning), `tests/Feature/PublicFeedback/PublicFeedbackOperationsTest.php`.
- [ ] TDD; commit `feat(feedback): data-quality command and production readiness checks`.

### Task QA-3: Failure behaviour and observability (commit 3)

**Files:** `tests/Feature/PublicFeedback/PublicFeedbackResilienceTest.php` (storage write failure → rolled back, no receipt, files cleaned; notification provider exception → submission committed, job fails and is retried; queue worker absent → export stays queued and the UI says so; log hygiene via `Log::spy()`), any fixes.
- [ ] TDD; commit `fix(feedback): resilience proofs` (or `test(...)` if nothing needed fixing).

### Task QA-4: Synthetic data and load test (commit 4)

**Files:** `app/Console/Commands/Scale/SeedPublicFeedbackScale.php` (`feedback:scale-seed --employees= --organizations= --categories= --rules= --submissions=` on an existing scale DB; PII-free), `ExportLoadTestData` extension (employee feedback tokens, org tokens), `loadtest/k6/public-feedback.js` (scenarios A–J + burst profile), `docs/public-feedback-load-testing.md` (dataset, VUs, RPS/TPS, p50/p95/p99, error rate, DB CPU/connections, Redis latency, queue backlog, app CPU/memory — exactly what was measured).
- [ ] Build, run against local nodes (warm), record; commit `feat(feedback): synthetic scale data and k6 public feedback scenarios`.

### Task QA-5: UAT, accessibility, responsive, route crawl, sidebar visibility (commit 5)

- [ ] Playwright: crawl every feedback route (public + internal) for 404/403/500/blank/console errors; public flow at 390×844, 820×1180, 1280×900; keyboard/focus/labels check; EN/AM; sidebar for a user without feedback grants; fix findings; commit `fix(feedback): UAT findings` (or docs-only).

### Task QA-6: Documents and the gate (commit 6)

**Files:** `docs/public-feedback-security.md` (threat model, tampering, IDOR, rate limiting, PII, attachments, identity visibility, conflict of interest, referral, audit; security matrix §89), `docs/public-feedback-routing.md` (original target / internal handler / related subject / referral destination), `docs/organization-feedback-qr.md` (create, enable, print/export, disable, revoke, rotate, archive, incident response), `docs/public-feedback-uat.md` (scenarios 92–98 with ID/Role/Precondition/Steps/Expected/Actual/Result), `docs/public-feedback-readiness.md` (final run: migrate --pretend, route:list, test, pint --test, composer audit --locked, npm audit, typecheck, build, production:readiness, load tests; P0/P1; NEEDS_DATA_REVIEW; NEEDS_DECISION; verdict).
- [ ] Commit `docs(feedback): QA gate — security, routing, QR operations, UAT and readiness`.
