# Assessment production readiness

## Verdict: NOT READY

The Assessment form, cycle, eligibility, assignment, execution, reviewer, oversight, institution-submission, private-evidence, and employee-result foundations are materially implemented. The complete requested domain is not release-ready because required governance and development workflows are absent, historical result-version preservation is incomplete, and the automated Assessment suite cannot execute in this environment.

## Architecture found

Assessment forms have immutable published versions, sections, criteria, rating options, target rules, and evaluator schemes. A cycle snapshots eligibility; queued chunked jobs generate employee records and evaluator responses. Evaluators submit option IDs, server-side services calculate decimal scores, reviewers finalize, oversight aggregates scoped data, and institution submissions are snapshot-based. Employee results are served through the authenticated My Portal.

## Release gates

| Gate | Status | Evidence / required action |
|---|---|---|
| Database | WARNING | `migrate --pretend` succeeds against PostgreSQL. Result-governance tables are additive and a resumable v1 backfill migration is present; classify the pair as `REQUIRES_BACKFILL` and reconcile it before deployment. |
| Security | FAIL | See `assessment-security-review.md`: missing governance workflows and unexecuted security tests. |
| Authorization | WARNING | Source controls are substantial; runtime IDOR/scope suite is blocked by missing `pdo_sqlite`. |
| Workflow | FAIL | Appeal, moderation, correction, result reconciliation, competency gap, and Assessment IDP workflows are not implemented end-to-end. |
| Calculations | WARNING | Backend decimal calculation is present; calculation/edge-case tests could not execute. |
| Reports / exports | WARNING | Scoped reports and exports exist; large export queuing/private-expiry proof is not present. |
| Performance | NOT_EXECUTED | Plan in `assessment-performance-test-plan.md`; no production-shaped load evidence. |
| Backup / recovery | NEEDS_DECISION | Existing backup architecture exists, but Assessment DB/evidence retention coverage and a tested restore have not been demonstrated. |
| UAT | FAIL | Matrix exists in `assessment-uat-readiness.md`; no successful UAT execution and several scenarios are blocked. |
| Operations | WARNING | Scheduler entries exist; actual production scheduler, queue worker, failed-job monitoring, storage, and alerting deployment evidence is absent. |

## Validation results

| Check | Result |
|---|---|
| `php artisan route:list --path=assessments` | PASS — 71 routes discovered. |
| `php artisan migrate --pretend` | PASS — generated PostgreSQL SQL without executing a migration. |
| Assessment PHP syntax lint | PASS — 79 Assessment PHP files. |
| `npm run build` | PASS — TypeScript and Vite production build completed. |
| `vendor/bin/pint --test` | PASS — no reported formatting violation. |
| `npm audit --omit=dev --audit-level=low` | PASS — 0 vulnerabilities reported. |
| `composer audit --locked` | UNAVAILABLE — Packagist advisory request timed out; rerun in a network-enabled CI environment. |
| `php artisan test tests/Feature/Assessment --compact` | FAIL / UNAVAILABLE — 43 tests failed at boot with `could not find driver` for SQLite; 0 assertions. |
| `php artisan config:cache` / `route:cache` | PASS — both cache builds completed. |

## Deployment requirements

1. Enable a production queue worker for `database` (or an approved queue backend), define timeout/retry/backoff/failed-job retention, and restart workers on deploy.
2. Run Laravel scheduler every minute; the Assessment monitor is scheduled daily at 06:30 and evaluator reminders at 07:00.
3. Set `APP_ENV=production`, `APP_DEBUG=false`, secure session cookies, HTTPS, private evidence storage, and protected backup credentials. Do not commit or echo secrets.
4. Confirm PostgreSQL backup plus WAL/PITR covers Assessment tables, object storage backup covers retained evidence/exports, and execute a restore test. Replication alone is not proof of backup.
5. Install `pdo_sqlite` in CI or reconfigure tests to use isolated PostgreSQL; run the complete Assessment suite and targeted concurrency/IDOR tests.
6. Execute the performance plan and UAT matrix, resolving all blockers and critical findings before release.

## Migration and rollback plan

| Migration area | Classification | Deployment / rollback |
|---|---|---|
| Form, record, oversight, execution migrations | SAFE_ONLINE only after production-size lock analysis | Additive schema; deploy application compatible with both old/new columns. Do not run destructive rollback on populated tables. |
| `2026_10_08_100700` + `2026_10_08_100800` result governance/backfill | REQUIRES_BACKFILL | Run the resumable v1 backfill and reconcile count/current-version invariants before enabling revisions. Roll back application feature exposure; preserve immutable rows. |
| Indexes on large tables | REQUIRES_MAINTENANCE decision | Use PostgreSQL concurrent-index strategy where table size requires it; Laravel default index DDL may lock writes. |

Application rollback means redeploying the prior compatible application, disabling the Assessment rollout for pilot institutions, and draining/restarting incompatible workers. Database rollback is not promised for immutable history or populated schema; restore/PITR is the recovery path.

## Needs decision

- Required MFA/email-verification assurance for evaluator and employee-result routes.
- Appeal filing window, duplicate-active-appeal rule, committee/separation-of-duties policy, correction approval policy, and moderation authority.
- Malware scanning provider and failure policy for evidence.
- Assessment record/evidence/export retention schedules.
- Production SLOs, queue backend/capacity, alert ownership, and pilot-institution rollout configuration.
