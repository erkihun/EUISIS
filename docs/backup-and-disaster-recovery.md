# Backup and disaster recovery

The former logical-dump-only plan is superseded by the [backup and recovery architecture](backup-recovery-architecture.md).

Primary PostgreSQL DR uses pgBackRest physical backups and continuous WAL/PITR, with encrypted independent repositories, isolated restore tests, file recovery and separately escrowed keys. pg_dump is optional supplementary logical recovery only.

Current production readiness: **NOT_READY** until infrastructure deployment and recovery evidence pass the [go-live checklist](go-live-checklist.md). The former 1-hour RPO / 4-hour RTO and example provider/recipient details were not evidence of approved policy or deployed infrastructure. Since 24 October 2026 (ADR 0004) the project owner's targets are RPO 15 minutes, RTO 1 hour for an application or server failure and RTO 4 hours maximum for a full database restore; they stay **TARGET_NOT_YET_VALIDATED** until a restore and failover drill proves them (`BACKUP_OBJECTIVES_VALIDATED_BY`, gates G1 and G2 in [go-live/go-live-gates.md](go-live/go-live-gates.md)).

- [Database backup operations](runbooks/database-backup.md)
- [Point-in-time recovery](runbooks/postgresql-pitr.md)
- [Full disaster recovery](runbooks/disaster-recovery.md)
- [File/object recovery](runbooks/object-storage-recovery.md)
- [Key recovery](key-recovery-runbook.md)
- [Restore validation](runbooks/restore-validation.md)
