# Addis One go-live checklist

## Backup and recovery gates (required evidence)

See [architecture](backup-recovery-architecture.md). Current status: **NOT_READY**;
the following require actual deployment evidence, not passing application unit tests.

- [ ] pgBackRest installed with pinned compatible version and reviewed configuration
- [ ] Full backups successful in both repositories
- [ ] Differential/incremental backups successful in both repositories
- [ ] WAL archive enabled/healthy with measured lag and capacity monitoring
- [ ] Independent/off-site storage and failure domains verified (not two directories on primary)
- [ ] Both repositories encrypted; SSH/TLS and private access verified
- [ ] Verification successful; corruption resolved; retention/WAL dependencies reviewed
- [ ] Isolated PITR tests passed from each repository; target/timeline and duration recorded
- [ ] APP_KEY and historical/repository key recovery verified separately
- [ ] Private/public file/object storage recovered with consistency/checksum checks
- [ ] Retention/holds configured; expiry reviewed and audited
- [ ] Backup/WAL/capacity/staleness/restore failure alerts tested, including independent dead-man alerts
- [ ] Protected atomic status feed and isolated journal transport deployed; app cannot write reports
- [ ] Backup operator, security/key custodian and separate restore approver assigned
- [ ] PITR, full DR, file/key and pre-migration runbooks rehearsed
- [ ] RPO/RTO approved by business owner; measured against drills (currently NEEDS_DECISION)
- [ ] `production:readiness --strict` backup gate passes on deployed infrastructure

No recoverable independent backup, tested restore, working required WAL, recoverable key/file
storage, private repository or resolved integrity failure is a **production blocker**.

Sign each line with name and date. A line that cannot be ticked blocks
go-live unless the system owner accepts the risk in writing next to it.
Evidence and finding IDs (PRA-nn, D-n) refer to
[production-readiness-assessment.md](production-readiness-assessment.md).

## A. Conditions from the readiness decision (all required)

- [ ] **C1 — Database engine.** The production engine is confirmed (MySQL or PostgreSQL). On that engine and version: `php artisan migrate` from an empty database and on a copy of current data succeeds, and the full test suite passes against it (PRA-06).
  _Progress 2026-09-27: PostgreSQL confirmed (D-5). On local PostgreSQL 18.6 the migrations, seeder and full suite pass after fixes PRA-21 … PRA-28. Remaining: the same on the staging server's PostgreSQL version, and `migrate` on a copy of current production data._
- [ ] **C2 — Production configuration.** `php artisan production:readiness --strict` on the production host ends with "No blocking items"; every WARN has a written decision (PRA-05, PRA-27).
- [ ] **C3 — Data audits.** On a copy of production data, `data:audit-duplicates`, `structure:audit` and `cafeteria:audit-configuration` report no HIGH finding, or each finding has an owner and a repair plan approved separately (repairs are never automatic).
- [ ] **C4 — Staging UAT.** The UAT script below passes on staging with real roles (not Super Admin), on the release tag being deployed.
- [x] **C5 — Decisions recorded.** D-1 … D-7 answered (D-5 on 2026-09-26, the rest on 2026-10-11; see the assessment's decision table). The business owner may still overturn any of them.
- [ ] **C6 — Scale evidence.** A load test against staging-sized infrastructure and data meets the agreed SLOs (docs/load-testing.md, docs/capacity-plan.md). Local measurements are not production capacity.
- [ ] **C7 — Credentials rotated.** Every credential found in git history (risk register SEC-11, commit `19ab6b8`, incl. the Gmail app password) is revoked and replaced.
- [ ] **C8 — Integrity pre-checks.** `data:audit-duplicates` on a copy of production shows no duplicated live cards, current assignments, vacancy-application transfers, provider references or provider ledger postings; otherwise the integrity migrations stop with NEEDS_DATA_REVIEW.

## B. Environment (`.env` on the production host)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set and backed up offline (losing it makes encrypted national IDs unreadable)
- [ ] `APP_URL=https://…` — final domain; card QR codes print it for years (`ID_CARD_QR_BASE_URL` if a short domain is used)
- [ ] `APP_TRUSTED_PROXIES` = load balancer / reverse proxy addresses (empty only if clients connect directly)
- [ ] `SESSION_DRIVER=database` or `redis`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`
- [ ] `CACHE_STORE` shared across app servers (database or redis); `QUEUE_CONNECTION` database or redis
- [ ] `LOG_LEVEL=warning` (or info), daily log rotation
- [ ] `MAIL_*` delivers; SMS gateway configured with daily and monthly caps
- [ ] Self-registration off unless deliberately enabled
- [ ] `FEATURE_COURT_CASES=false` (placeholder module). Transport is live by default; leave `FEATURE_TRANSPORT` unset (or `true`) and review Transport › Settings before the first boarding
- [ ] `STORAGE_PRIVATE_DRIVER=s3` and `STORAGE_PUBLIC_DRIVER=s3` with `STORAGE_PRIVATE_BUCKET`, `STORAGE_PUBLIC_BUCKET`, `STORAGE_PUBLIC_URL` and `AWS_*` credentials when more than one app server runs (node-local disks are not shared)
- [ ] `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` (default 900) above the longest job timeout (600); `production:readiness` checks it
- [ ] `REDIS_*` or database cache shared by every node; scheduler locks (`onOneServer`) need a shared cache
- [ ] PHP extensions: `pdo_<engine>`, `mbstring`, `openssl`, `intl`, `fileinfo`, `redis` if Redis is used, `imagick` if PNG card export is needed

## C. Infrastructure

- [ ] TLS certificate valid; HTTP redirects to HTTPS; HSTS present (smoke test)
- [ ] Load balancer liveness on `GET /health/live`, readiness on `GET /health/ready` (database, cache, storage); `/up` still answers
- [ ] Cron runs `schedule:run` every minute on every app node (tasks run once via `onOneServer`)
- [ ] Queue workers supervised for the `default`, `notifications` and `long` queues, e.g. `queue:work --queue=default,notifications` and a separate `queue:work --queue=long --timeout=600`
- [ ] PgBouncer (transaction pooling) or a sized `max_connections` in front of PostgreSQL; see docs/load-balancing.md
- [ ] Backup and recovery gates at the top of this checklist signed (pgBackRest, WAL/PITR, isolated restore test)
- [ ] `storage/app` on persistent, backed-up storage (employee photos and card templates are private files; see runbooks/object-storage-recovery.md)
- [ ] Database not reachable from the internet; application DB user has no superuser rights

## D. Release

- [ ] Tagged release; CI green (tests, type check, build, `composer audit --locked`, `npm audit --omit=dev`)
- [ ] `migrate --pretend` output reviewed; includes the pending `2026_09_26_000000_add_rbac_metadata_and_register_permission_catalog`
- [ ] The `2026_10_10_3000xx` integrity and index migrations reviewed: they build indexes `CONCURRENTLY` outside a transaction and stop with NEEDS_DATA_REVIEW on duplicates instead of changing data (docs/database-scale.md)
- [ ] The 2026_10_11_100200 migration re-hashed national IDs with the keyed hash (`production:readiness`: "National-ID lookup hashes are keyed" PASS); it needs the environment's real APP_KEY
- [ ] `php artisan db:seed` is never run on production (`DatabaseSeeder` refuses in production; reference seeders are run individually)
- [ ] Pre-deploy recovery evidence recorded: verified pgBackRest backup labels in both repositories and a current isolated restore test (runbook/deployment-and-rollback.md §1)
- [ ] Rollback owner named; runbook read (runbook/deployment-and-rollback.md)

## E. Master data and access

- [ ] Code rules active for Organization, Position, Employee and card numbers
- [ ] Organization types, occupations and the published hierarchy loaded — by import or the admin pages, not by re-running seeders on production
- [ ] Demo accounts absent: no `@demo.local` users; no default passwords
- [ ] Every staff account has the least role it needs and an organization scope; Super Admin limited to named people
- [ ] Provider portal accounts created per operator (no shared logins); each forced to change the temporary password
- [ ] ID card templates approved (front and back, both orientations in use) — see D-4

## F. UAT script (staging, real roles)

1. HR officer (scoped to one organization) registers an employee in a vacant position; employee number and position code are generated.
2. The same officer cannot open an employee of another organization.
3. Card officer requests a card; a **different** approver approves; the card is printed (confirm print), issued and activated.
4. Scan the printed card's QR with a phone: the public checker opens, shows no personal data until the holder's one-time code.
5. Cafeteria admin grants the organization access to a network, approves the service assignment; a second person approves the policy.
6. At the counter, the provider operator scans the card in the provider portal: accepted, subsidy and employee share as per policy. A second scan the same day is refused.
7. The transaction appears in the provider portal and the back office, billed to the employee's organization.
8. Suspend the card; the next scan is refused.
9. Finance drafts the month's settlement for the provider, checks the per-organization liability, and finalizes it.
10. Export the transaction statement (PDF/XLSX) and the provider payment claim.
11. Switch the interface to Amharic; repeat step 6's screens; dates show in the Ethiopian calendar.

## G. After go-live (first week)

- [ ] Smoke test after every deploy; `queue:failed` empty daily
- [ ] Data audits weekly; results filed
- [ ] Error log reviewed daily; audit log sampled for unexpected actors
- [ ] First settlement reconciled with each provider before payment
