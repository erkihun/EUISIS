# Assessment security review

Audit date: 2026-10-08 (workspace state audited). This is a source and configuration review, not a substitute for a deployed-environment penetration test.

## Evidence examined

- `routes/assessments.php`, Assessment controllers, form policy, execution/oversight services, migrations, jobs, commands, React pages, and `tests/Feature/Assessment/*`.
- `php artisan route:list --path=assessments` returned 71 routes.
- `php artisan migrate --pretend` rendered the pending Assessment migrations against PostgreSQL without executing them.
- The Assessment feature tests could not boot: the test configuration selects SQLite `:memory:` but PHP has no `pdo_sqlite` extension. No test assertion therefore ran.

## Control status

| Area | Status | Evidence / conclusion |
|---|---|---|
| Authentication | PASS | Every Assessment route is inside an `auth` group. |
| Uniform verification and MFA | FAIL | Evaluator workspace and My Portal Assessment route groups omit `verified` and `mfa`, unlike forms, records, reviews, and oversight. Decide and enforce the required assurance level consistently before release. |
| Form authorization and scope | PASS (source) | `AssessmentFormPolicy` combines permission checks with `OrganizationScopeService`. |
| Evaluator IDOR | PASS (source), UNVERIFIED (runtime) | `AssessmentResponseService::canOpen` requires the assigned evaluator, active account, permission, and valid record state; assessment tests could not run. |
| Employee self-service IDOR | PASS (source), UNVERIFIED (runtime) | My Portal resolves records from authenticated `employee_id`; acknowledgement rechecks ownership server-side. |
| Oversight scope / city visibility | PASS (source), UNVERIFIED (runtime) | `OversightAccess` and scoped query services are used by dashboard, drill-down, report, export, and submission services. |
| Form version immutability | PASS (source), UNVERIFIED (runtime) | Draft/published states and form service are present; existing tests target immutability but could not execute. |
| Authoritative calculation | PASS (source) | Client submits rating-option IDs; `AssessmentResponseService` resolves options and `AssessmentScoringService` calculates decimal scores server-side. |
| Workflow and double-submit protection | PASS (source), UNVERIFIED (runtime) | Submission/finalization use transactions and row locks; response optimistic locking is present. |
| Result version integrity | FAIL | A resumable historical-v1 backfill migration is now present, but there is no public workflow for appeal/moderation/correction, and current employee/EPMS reads still come from `assessment_records`, not the current result-version row. |
| Appeals, moderation, corrections | FAIL | Tables and limited model/service scaffolding exist, but no Assessment controllers, routes, policies, requests, UI, or tests implement these distinct workflows. |
| Competency gaps and Assessment IDP | FAIL | No Assessment-domain gap/IDP workflow is connected to finalized Assessment records. Existing performance IDPs are a separate model/workflow. |
| Evidence privacy | PASS (source), UNVERIFIED (runtime) | Evidence uses the private `local` disk and downloads authorize the assigned evaluator or scoped detailed-results reviewer. |
| File validation | PASS (source) | MIME allow-list, size cap, server-generated path, and sanitized stored display name are present. Malware-scanning integration is not configured: NEEDS_DECISION. |
| Export confidentiality / CSV injection | PASS (source), UNVERIFIED (runtime) | Scoped export controller exists; Assessment tests include CSV formula-injection coverage but could not run. |
| Peer anonymity | NEEDS_DECISION | Execution UI suppresses anonymous evaluator identity in employee results, but a full audit of PDF, Excel, API, notifications, and audit views has not been executed. |
| Audit trail | PASS (source) | Publish, eligibility, assignment, submission, finalization, evidence, exclusion, and institutional submission operations call the audit action. Governance-workflow events cannot be audited until the workflows exist. |
| Sensitive logging | PASS (source review) | Assessment notifications do not include answers; no Assessment response payload logging was found. |

## Release-blocking findings

1. **BLOCKER — incomplete appeal, moderation, correction, and result-revision workflows.** Deploying governance tables without controlled routes, policies, separation-of-duties checks, private-evidence access control, and tests does not implement the required processes.
2. **CRITICAL — historic-result backfill remains unexecuted.** `2026_10_08_100800_backfill_assessment_result_versions` now supplies a resumable, non-destructive v1 backfill, but it must be run and reconciled on a production-shaped copy before release.
3. **CRITICAL — Assessment security tests are not executable in this environment.** Install/enable `pdo_sqlite` for the configured test database or run the suite against an isolated PostgreSQL test database before release.
4. **HIGH — authentication assurance is inconsistent.** Decide whether evaluator and employee-result routes require verified email and MFA, then make route policy and tests consistent.

## Required remediation proof

- Implement and test distinct Assessment appeal, moderation, correction, and result-version APIs/workflows; no generic final-score edit endpoint.
- Run and reconcile the historical v1 backfill, proving exactly one current result version per finalized record.
- Run the IDOR, scope, anonymity, file-download, export, workflow, and concurrency tests successfully in a database-capable CI environment.
- Perform an authenticated browser/API security test against a non-production environment and retain the evidence with the release.
