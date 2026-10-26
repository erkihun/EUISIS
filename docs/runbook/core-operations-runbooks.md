# Core platform: operations runbooks

These are short, practical procedures for the on-call person. Owners and
channels are in the [owner matrix](../go-live/core/core-owner-matrix-and-risks.md);
they are TBD until the organization assigns them. Alert names refer to
[core-monitoring-and-alerts.md](core-monitoring-and-alerts.md).

**First look in every case:**

- `GET /health/monitor` with the monitor token: every check and its key figures.
- System Health (city-wide `system_health.view`).
- `shared/storage/logs/laravel-*.log`: search by the `X-Request-Id` a user reports.

---

## Application deployment / rollback

Follow [core-deployment.md](core-deployment.md) and [core-rollback.md](core-rollback.md).

## Database restore

Follow [core-backup-and-restore.md](core-backup-and-restore.md) § Restore.

## Queue failure (`CoreQueueBacklog`, `CoreFailedJobsSpike`)

1. Check `/health/monitor`: `queue_waiting`, `queue_oldest_minutes`,
   `queue_failed_jobs` and `queue_stuck_jobs`.
2. Workers running? `systemctl status 'core-worker@*'`. Restart them with
   `systemctl restart 'core-worker@*'`, which also picks up new code after a
   deploy that skipped `queue:restart`.
3. Stuck jobs (reserved over 15 minutes) mean a worker died mid-job. Restarting
   the workers releases them on retry-after.
4. Failed jobs: System Health › Queue shows the class and the error (never the
   payload). Fix the cause, then **Retry**. Retry and Remove are audited. Jobs
   are written to be repeat-safe.
5. Exports or notifications that never left: the user sees them pending. They
   complete once the queue drains, so nothing needs re-entering.

## Database unavailable (`CoreDatabaseDown`)

1. `/health/ready` answers 503 and the load balancer takes the nodes out.
   `/health/live` still answers, so the nodes are not restarted in a loop.
2. Check the database host: service, disk full, connection limit
   (`select count(*) from pg_stat_activity`), and failover state if replicated.
3. Connection exhaustion: each PHP-FPM child and worker holds one connection.
   Lower `pm.max_children`, or raise `max_connections` if memory allows. A
   pooler (PgBouncer) is **not tested** with the Core. PDO uses server-side
   prepared statements, so transaction pooling needs PgBouncer 1.21 or later
   with `max_prepared_statements`; rehearse it on staging first.
4. Once it is back: `/health/ready` is ok, the scheduler resumes, and
   `core:audit-seal` catches up on unsealed rows by itself.
5. Data damage: restore ([core-backup-and-restore.md](core-backup-and-restore.md)).

## Redis unavailable

**Not applicable as built.** The Core uses the database for sessions, cache
and queue. If production adopts Redis later, add its probe to `/health/ready`
and write this section then.

## Storage unavailable (`CoreStorageLow`, `/health/ready` storage=fail)

1. `storage` fails when the evidence root is not a writable directory: an
   unmounted volume, read-only remount or permissions.
2. Remount, or repair the permissions: owner = PHP-FPM user, mode 0750 on
   directories.
3. While storage is down, uploads, document downloads, exports and card
   rendering with photos fail. Everything else works.
4. Low space: exports expire on their own (`core:prune-exports`); old import
   files can be archived by the owner. Grow the volume before 10% free.

## Backup failure (`CoreBackupFailed`, `CoreBackupTooOld`)

1. The status file records `last_failure_at` and the failed step
   (`last_failure_reason`). The log is `/var/log/core-backup/backup.log`.
2. Common causes:
   - snapshot or database: the backup role, `~/.pgpass`, or pgBackRest lock
     or stanza (`pgbackrest --stanza=core check`);
   - files: disk space on the repository;
   - offhost: the remote's credentials or quota;
   - status: permissions on the status file.
3. Re-run `core-backup.sh` by hand once it is fixed. System Health turns
   healthy only after a later success.
4. More than one interval missed: raise to the backup owner and record it in
   the risk register, because the RPO is at risk.

## Card-verification outage (`CoreCardVerificationErrors`, `CoreCardVerificationSlow`)

1. Card checks use the public check page, `/api/v1/cards/verify` and the
   integration API. All three rely on the database and `CredentialVerifier`.
2. Errors everywhere point to the database (see above).
3. Valid cards answer `KEY_RETIRED` or `CREDENTIAL_UNKNOWN` after a change:
   `CORE_CREDENTIAL_KEYS` lost a key version that is still in use. Put the
   previous key list back from the secret store, then run `optimize` and
   reload. See ADR 0010 for the rotation order: add the new key first, run
   `core:credentials-rekey`, and retire the old one after its grace date.
4. Slow checks: `/health/monitor` `card_verification_p95_ms_5m`. Check the
   database load and the `digital_credentials` indexes.
5. Tell the verifier sites (the business owner keeps the list): during an
   outage, checks fall back to the printed card and a manual call. A failed
   check is never a reason to confiscate a card.

## API credential compromise

1. Integration Center, then the client, then **Revoke** (immediate and
   audited). For a token that only leaked, **Rotate** instead: the old token
   stops at once.
2. API call logs (Integration Center › Calls): what that client called since
   the suspected leak (IP, routes, status). Export them for the incident.
3. Webhook secret leaked: Webhooks, then **Rotate secret**, and give the new
   secret to the receiver.
4. A person's token: use Users, then the account, then reset the password.
   This ends every session and token; reset MFA too if the device is
   suspected.
5. Report it to the security owner. Check whether the client's scope allowed
   personal data; if so, follow the data-protection notification policy (the
   organization's, TBD).

## Audit integrity failure (`CoreAuditIntegrityFailed`)

1. `core:audit-verify` failed: Security Center shows the first bad sequence
   and the message.
2. **Do not** re-seal or "fix" rows. The audit tables are evidence. Take a
   database snapshot or backup right away and keep it.
3. Read the message:
   - "changed after it was sealed" or "chain breaks": a sealed row was altered
     (the database trigger was bypassed, which means superuser access);
   - "sequence missing": a row was removed;
   - "differs from its off-server copy" or "has no off-server copy": the seals
     in the database no longer match the copies kept off the server;
   - "shipped but the database ends at": seals were removed, or the database
     was restored to an earlier point. After a planned restore this is
     expected: record the gap.
4. Report it to the security owner and treat it as a security incident: who
   had database superuser or seal-key access, and when (the database logs,
   the secret-store access log).
5. Heads that will not ship (`audit_heads_ship_failed`): the off-server store
   is unreachable. Fix access. The next `core:audit-ship-heads` run catches
   up, and nothing is lost meanwhile.

## Rotating the audit seal key

Seals are signed with `CORE_AUDIT_SEAL_KEY`, and verification uses the
current key for every seal. Rotation is therefore a planned event:

1. run `core:audit-verify` and record the result;
2. export the seal list (`audit_seals`), which is kept off-server as heads;
3. change the key;
4. from then on, verification of the older seals needs the old key. The
   procedure needs a key-versioned seal (not built: P2 risk R-07).

Until that exists, rotate only on compromise.
