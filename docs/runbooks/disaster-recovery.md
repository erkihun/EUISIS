# Full disaster recovery

Incident commander coordinates Infrastructure/DB operator, Security/System Admin and
independent Recovery Approver. Assign people before launch. Objective values remain
NEEDS_DECISION until approved; record actual recovery duration and lost-data interval.

1. Assess the failure, preserve disks/WAL/logs and take a safeguard snapshot where possible.
   Freeze damaging writes; capture external incident evidence and authorization because
   application audit may be unavailable or rolled back.
2. Provision replacement PostgreSQL on an isolated approved host, matching major/extensions,
   collation, tablespaces and required OS libraries. Restore infrastructure configs separately
   from PGDATA; pgBackRest does not replace host provisioning/config recovery.
3. Recover repository access and independent keys from the [key runbook](../key-recovery-runbook.md).
   Select a valid full chain and replay archived WAL from the independent repository using
   the [PITR procedure](postgresql-pitr.md), or the approved latest consistent recovery point.
4. Recover `storage/app/private` and `storage/app/public` or the actual private object store
   with [version/checksum consistency](object-storage-recovery.md). Restore APP_KEY and any
   historical keys through the approved secrets store. Use the release matching migration state.
5. Complete [database/application validation](restore-validation.md). Run migrations only
   if a separately approved release step requires them and a new verified safeguard exists.
6. Obtain cutover approval and maintenance window. Replace routing/config, restart PHP and
   held queue workers, validate `/up`, login, card rendering and service reads in isolation.
   Prevent real SMS, mail, integration writes or public HR exposure from the test system.
7. Reopen traffic, reconcile work newer than the target, check failed/pending jobs carefully
   before replay (avoid duplicate service transactions). Cache/session files are not canonical
   business data; invalidate sessions after recovery. Database queue jobs recover with the DB;
   if Redis is later used, define persistence and idempotent replay in a separate queue plan.
8. Confirm new timeline WAL archiving and new backups in both repositories. Record completion,
   target, measured duration, reconciliation and follow-up actions. Securely decommission the
   isolated scratch storage and retain forensic evidence according to the incident hold.

An existing standby may help availability only after validation. It does not replace retained
recovery history, and promoting a corrupt/deleted copy is not disaster recovery proof.
