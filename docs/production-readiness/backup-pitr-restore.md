# Backup, PITR and restore

Production Gate 2, 2026-10-20. Design: [../backup-recovery-architecture.md](../backup-recovery-architecture.md),
[../backup-and-disaster-recovery.md](../backup-and-disaster-recovery.md), runbooks in
[../runbooks/](../runbooks/).

## Verdict: DR is NOT_READY

**A backup that has not been restored is not proven recoverable.** No production backup exists
in evidence, and none has been restored.

| Item | Status | Evidence |
| --- | --- | --- |
| Backup design | **Designed** | pgBackRest; two encrypted, independent repositories; full/differential schedule template (`deploy/backup/crontab.example`); 12 weekly chains proposed; the app's `backup:health` monitor runs every 5 minutes and alerts |
| Backup tooling deployed | **NOT_PRESENT** | The 2026-09-27 inspection found no pgBackRest binary or repository (INFRASTRUCTURE_NOT_AVAILABLE). Nothing has changed since. |
| PITR (WAL archiving) | **NOT_CONFIGURED** | `archive_mode=off`, `archived_count=0` on the only PostgreSQL available. `BACKUP_PITR_REQUIRED` is true by default, so `backup:health` reports it. |
| Off-host / off-site storage | **Designed, not deployed** | repository 2 is a template |
| Encryption | **Designed** | repository cipher with escrowed keys ([../key-recovery-runbook.md](../key-recovery-runbook.md)) |
| Monitoring | **Implemented in the app** | `backup:health --monitor`. External dead-man monitor not deployed. |
| Restore of a production backup | **Never performed** | no record anywhere |
| RPO / RTO | **NEEDS_POLICY_DECISION_RPO_RTO** | `config/backup.php` defaults to `NEEDS_DECISION`; the old 1 h / 4 h figures were never approved |
| Production database engine | **Unresolved in practice** | Decision D-5 says PostgreSQL. The live Plesk host runs MySQL/MariaDB, for which the repository has **no backup or PITR design at all**. Either migrate production to PostgreSQL before go-live, or design MySQL backup and binlog PITR. **P1.** |

## Restore drill performed in this gate (isolated, logical)

`loadtest/db/restore-drill.sh aaemployeedb_scale3m aaemployeedb_restore_drill <dir> 8`

The drill restored the 3M benchmark database into a **new** database on the same PostgreSQL
18.6. It refuses an existing target and never writes to the source.

| Step | Result |
| --- | --- |
| `pg_dump --format=directory --jobs=8` (consistent snapshot) | **26 s**; 1.6 GB on disk (compressed) for a 13–14 GB database |
| `createdb` + `pg_restore --jobs=8` | **590 s** (9 min 50 s): data plus every index rebuilt |
| `ANALYZE` | 32 s |
| Restored size | 12 GB (freshly packed indexes) |
| Row counts of 15 tables + content checksums of `employees` and `id_cards` | **identical** to the source (3,000,004 employees, 3,750,000 cards, 4,000,017 assignments, 3,606,248 service transactions, 6,049,245 audit rows, 132,077 integration events, 264,000 webhook deliveries, 283 migrations) |
| `integrity-checks.sql` on the restored copy | **all 0** |
| Clean-up | restored database dropped; dump directory deleted |

**What it proves:**

- A logical dump of a 3M-employee database restores completely and consistently.
- **Measured restore time: about 11 minutes end to end** on this machine (local SSD, 8 jobs).
  That is a lower bound for the logical path; a remote repository adds transfer time.

**What it does not prove:**

- PITR to a moment in time.
- The pgBackRest physical path: restore from a repository, then WAL replay.
- Restore from off-site storage.
- Key escrow working.
- Anything about the production database: it is not PostgreSQL today and has no backups in
  evidence.

## Redis

Redis holds no canonical data and is not used in the shipped configuration. If it is adopted:

- **Queues in Redis:** need AOF, or stay in PostgreSQL.
- **Sessions:** may be lost; users sign in again.
- **Cache:** rebuilt on demand.

Integration events are safe either way: they live in the PostgreSQL outbox.

## Required before production (conditions of Gate 2)

1. Production on PostgreSQL (D-5) with WAL archiving to two repositories, and `backup:health`
   reporting HEALTHY.
2. An isolated **PITR restore test** from each repository, with the duration measured and
   recorded through `backup:restore-record` (approver ≠ requester).
3. RPO/RTO approved by the business owner, then compared with:
   - the measured restore time (≈ 11 min logical for 3M at minimum);
   - the archive lag.
4. Standby with documented failover (**NEEDS_DEPLOYMENT_CONFIGURATION_POSTGRES_HA**). A restore
   alone means about 11 minutes or more of total outage.
5. Object-storage backup of private files: photos, evidence and ID print artefacts are not in
   the database ([../runbooks/object-storage-recovery.md](../runbooks/object-storage-recovery.md)).
