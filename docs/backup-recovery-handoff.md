# Backup and recovery implementation handoff

Code/templates: **APPLICATION_READY_INFRASTRUCTURE_PENDING** (real infrastructure integration testing still required).
Production recovery: **NOT_READY**. No production data, WAL configuration or keys were changed.

| Requested item | Delivered / current evidence |
|---|---|
| 1. Existing architecture | Dump-based documentation; Linux/cron runbook; local PostgreSQL 18.6; no pgBackRest installation/repository evidence |
| 2. Risks | Local archive_mode off; no proven independent physical backup/PITR, file recovery, key recovery or approved objectives |
| 3. Final architecture | Two encrypted independent pgBackRest repositories, continuous WAL, infrastructure scheduling, isolated PITR tests, private status feed into Laravel |
| 4. Tool selection | pgBackRest fits documented Linux/PostgreSQL deployment and physical recovery requirements; no cloud provider assumed |
| 5. Full backups | Configurable infrastructure weekly baseline, separate run per repository |
| 6. Differential/incremental | Daily differential baseline; fixed runner supports incremental alternative |
| 7. WAL/PITR | Templates and isolated time-target runner implemented; actual WAL restart/archive verification remains undeployed |
| 8. Retention | Proposed 12 full chains plus dependencies/WAL; explicit pgBackRest expiry and audit; safe app metadata, no file deletion UI |
| 9. Encryption | Repository cipher templates; secret-manager-rendered keys outside Git/PHP; SSH/TLS, separate key escrow required |
| 10. Independent/off-site | Distinct private/off-site repository-host template; physical failure-domain proof remains required |
| 11. File/object storage | Actual private/public disk inventory, versioned encrypted snapshot and consistency/recovery runbook; storage platform provisioning pending |
| 12. Application keys | APP_KEY/historical/repository key escrow, rotation and strict decryption verification runbook |
| 13. UI | System → Backup & Recovery (English/Amharic): state, per-repository full/daily/age/capacity/verification/restore test, WAL, logical archive, policy, control evidence, history and staged restore requests. Authorized dashboard card: last successful backup, repositories available, WAL, last restore test, readiness; CRITICAL shown distinctly from WARNING |
| 14. Permissions | Nine backups.* permissions; System/Super Admin defaults, City/Bureau/Organization Admin excluded; separate restore approver |
| 15. Audit | Typed backup/logical/verification/test/restore request/review/approval/authorization/cancel/execution/expiry/access events; idempotent imported operations, fixed safe error text |
| 16. Alerts | Five-minute monitor, database notifications/optional mail to authorized configured active accounts, event hook, deduplicated repeats; external dead-man monitoring is deployment work |
| 17. Restore workflow | REQUESTED → UNDER_REVIEW → APPROVED → TEST_RESTORE_RUNNING → TEST_RESTORE_VERIFIED → PRODUCTION_RESTORE_AUTHORIZED → RESTORING → COMPLETED/FAILED, plus REJECTED/CANCELLED. Point-in-time, backup-set or latest requests with reason; approver ≠ requester for both the isolated-test approval and the production authorization; execution steps are CLI-recorded with evidence tickets; no browser restore or dump download |
| 18. Restore tests | Private disposable cluster, real time target from each repo, schema/constraint/orphan/decryption/file checks, guarded cleanup; broader login/file smoke checklist required |
| 19. Runbooks | Architecture, backup, PITR, full DR, object/file recovery, key recovery and validation; old dump plan superseded and deployment/go-live gates updated |
| 20. Scripts/templates | `scripts/backup/{backup.py,restore_test.py,restore_check.php}` (runner now includes optional encrypted `logical` pg_dump archive); `deploy/backup` pgBackRest/PostgreSQL/runner/controls/cron/pg_service examples; `backup:health` summary/`--json`, `backup:list`, `backup:restore-record`, extended `production:readiness` |
| 21. Tests | Authorization, sanitization, injection, request validation per restore type, full staged workflow and separation of duties, cancellation, policy allowlist, CLI output, dashboard card, audit/idempotency, policy validation, stale/failure/unknown/logical states, recipient filtering, corruption parsing and logical-archive pruning |
| 22. Validation | See validation record below; infrastructure live backup/restore unavailable |
| 23. Deployment tasks | Actual hosts/storage/keys/roles, PostgreSQL archive restart, tool/version pinning, migration, schedules, protected reports/journal transport, capacity agents, external alarms and isolated drills |
| 24. RPO/RTO | NEEDS_DECISION; record business approval and measured restore/archive/file/key times |
| 25. Readiness | NOT_READY until successful creation, verification and isolated restore from independent encrypted storage, plus recoverable files/keys and all go-live evidence |

## Status integration diagnosis (2026-09-27)

Overall: **APPLICATION_READY_INFRASTRUCTURE_PENDING**. Production recovery: **NOT_READY**.

The original message, "INFRASTRUCTURE NOT AVAILABLE OR INVALID REPORT", came from this chain:

1. `BACKUP_STATUS_ENABLED` was absent from `.env`, so `config('backup.enabled')` was false.
2. `BackupReportReader::read()` threw `RuntimeException('INFRASTRUCTURE_NOT_AVAILABLE')`.
3. `BackupStatusService::status()` caught every `Throwable` and replaced it with the single issue
   code `INFRASTRUCTURE_NOT_AVAILABLE_OR_INVALID_REPORT`.
4. The page rendered that code with underscores removed.

The report parser was never reached. Underlying facts on this machine:

- Windows 11 development host.
- pgBackRest is not on PATH; WSL has no distribution installed; there is no Docker CLI, and the
  project uses neither.
- There is no report file at `/var/lib/euisis-backup/status.json`.
- PostgreSQL 18.6 is reachable with `archive_mode=off`, `archived_count=0`.

Fix: the generic catch-all was replaced by the adapter, WAL inspector, evidence reader and
evaluator layers described in the [architecture](backup-recovery-architecture.md#status-integration).
Every failure now has its own reason code, which the
[troubleshooting runbook](runbooks/backup-health-troubleshooting.md) explains. On this machine
the page now reports **NOT_CONFIGURED** with production blocker NO. With
`BACKUP_STATUS_ENABLED=true` it reports **INFRASTRUCTURE_UNAVAILABLE / COMMAND_NOT_FOUND**
(`preflight: BINARY_NOT_FOUND`, platform Windows). WAL shows **WAL_ARCHIVE_UNHEALTHY /
ARCHIVE_MODE_OFF**. `production:readiness --strict` reports CRITICAL, blocker YES.

The pgBackRest diagnostics (`version`, `info --output=json`, `check`) could not run here:
INFRASTRUCTURE_NOT_AVAILABLE. Nothing was installed, restored or deleted.

## Validation record (2026-09-27)

- Full regression: `pest --parallel` on the SQLite test configuration, **2600 passed / 15906
  assertions**, exit 0.
- Backup suites: 99 passed. Coverage: adapter and parser (missing binary, disabled, stanza
  missing, repository unavailable, permission denied, valid/invalid/empty JSON, no backups,
  recent/stale backups, WAL unhealthy, restore test overdue, timeout, non-zero exit, no secret
  leakage, fixed argument vectors, scrubbed environment, caching, report driver, missing tables),
  plus the earlier workflow, CLI and dashboard tests. All use `Process::fake()`; no pgBackRest
  is required.
- Python runner tests: 12 passed (runner unchanged in this pass).
- `npm run build` (`tsc && vite build`) passed; the existing chunk-size warning remains.
- Pint is clean on all backup files, and `route:list` shows the four `backups.*` routes.
- Live check against the local database: the page and dashboard render (HTTP 200) with
  overall NOT_CONFIGURED and diagnostics withheld from the browser.

## Deployment order

1. Read [architecture](backup-recovery-architecture.md) and assign actual operators/approvers.
2. Provision and rehearse infrastructure templates on staging; install secrets outside Laravel.
3. Deploy the additive backup tables/permission migration. Existing account IDs are numeric;
   recovery/operation IDs are UUIDs. This task does not migrate the current application DB.
4. Apply policy/recipient environment settings, config cache and scheduler setup; PHP gets read
   access only to the atomically published report, never repository/runner/config credentials.
5. Create and verify both repositories, recover files/keys and run isolated tests from both.
   Review externally retained evidence, then attest controls with a current checked_at time.
6. Test failure alerts, review the go-live checklist and run strict production readiness.

Do not interpret a passing mock/test fixture or manually set attestation as production proof.
The infrastructure operator must validate installed-version behavior, including corruption
reporting, archived target reachability, permissions and cleanup on interrupted restores.
