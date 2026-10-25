# Core platform: backup and restore runbook

Backups run **outside the application**, as the backup account on the backup
host. The Core only reads their status (`CORE_BACKUP_STATUS_FILE`, ADR 0011).
Tooling: [`core-platform/deploy/backup/`](../../core-platform/deploy/backup/).
The rehearsal results are in [core-go-live-evidence.md](../go-live/core/core-go-live-evidence.md) §4–5.

## Recovery objectives

| Objective | Value | Owner |
|---|---|---|
| RPO (data loss allowed) | **BUSINESS DECISION REQUIRED** | business owner (TBD) |
| RTO (time to restore service) | **BUSINESS DECISION REQUIRED** | business owner, with infrastructure (TBD) |

Until values are given, the cadence below is a proposal. Once they are given,
check them against these measurements:

- Logical restore of the staging copy: measured in the evidence document.
- Logical restore of a 3-million-employee EUISIS database: about 11 minutes on
  a local SSD (`docs/production-readiness/backup-pitr-restore.md`).
- A pg_dump-only backup cannot meet an RPO below its interval. An RPO in
  minutes needs pgBackRest with WAL archiving, i.e. point-in-time recovery
  (PITR).

## What is backed up

| Data | How | Encrypted | Off-host |
|---|---|---|---|
| PostgreSQL database | **pgBackRest**: weekly full and daily differential backups, WAL archiving for PITR, repository 1 encrypted with AES-256, repository 2 off-site (`pgbackrest.conf.example`). Plus a nightly `pg_dump --format=custom` (the drill path) | pgBackRest cipher; the dump is GPG-encrypted to the backup public key | repository 2, and the dump through `OFFHOST_CMD`/`OFFHOST_DIR` |
| Employee documents, photos, signatures, imports, exports | `tar` of `shared/storage/app/private`, GPG-encrypted | yes | `OFFHOST_CMD`/`OFFHOST_DIR`, verified with `sha256sum -c` |
| Card assets | card templates and their images are database rows; printed card files are not kept | with the database | with the database |
| Audit seal heads | already off-server (`CORE_AUDIT_HEAD_DISK`); that store is backed up by its own host | — | yes, by design |
| Configuration | **not in the backup.** `.env` and the secrets (APP_KEY, CORE_CREDENTIAL_KEYS, CORE_AUDIT_SEAL_KEY, NATIONAL_ID_HASH_KEY, the monitor token, mail credentials) live in the secret store, plus the release artifact and its manifest | the secret store's | the secret store's own replication |
| Backup keys | the GPG private key and the pgBackRest cipher passwords, escrowed apart from the backups (two holders, TBD) | — | — |

**Without APP_KEY a restored database is only partly usable:** MFA secrets,
session data and credential tokens are encrypted with it. Without
CORE_CREDENTIAL_KEYS, card credentials do not verify. Without
CORE_AUDIT_SEAL_KEY, the audit seals cannot be checked. Restore the secrets
first.

## Schedule (proposal until RPO/RTO are decided)

`crontab.example`: a pgBackRest full backup weekly and a differential daily,
the dump and files nightly, `pgbackrest verify` weekly, and a restore drill
monthly on the isolated restore host, from the off-host copy.

## Configure a backup host

1. Install `pgbackrest` (matching PostgreSQL 18), `gpg` and the remote copy
   tool (`rclone` or `rsync`).
2. Create the backup account. Give it `~/.pgpass` or a service file for a
   read-only backup role; never the application's database role.
3. Install `pgbackrest.conf.example` as `/etc/pgbackrest/pgbackrest.conf` and
   fill in every `REPLACE_*`. Set `archive_mode`/`archive_command` in
   `postgresql.conf` (this needs a restart). Then run
   `pgbackrest --stanza=core stanza-create` and `check`.
4. Import the backup **public** key only. The private key goes to the escrow
   holders.
5. Install `backup.env.example` as `/etc/core-backup/backup.env` (mode 0600)
   and fill in every `REPLACE_*`.
6. Point `CORE_BACKUP_STATUS_FILE` in the Core's `.env` at `STATUS_FILE`.
   Make it readable by the PHP-FPM user and writable only by the backup
   account.
7. Run `core-backup.sh` once by hand. System Health › Backups must show the
   run. It shows a warning until the first restore drill passes.

## Restore (an incident)

1. Declare the incident. Agree the restore point: the latest backup, or a
   PITR time with pgBackRest.
2. Provision the target: database server and storage volume, the release
   artifact of the backup's release (`manifest.json` "release"), and `.env`
   from the secret store.
3. Database:
   - pgBackRest: `pgbackrest --stanza=core --type=time --target='<UTC time>' restore`,
     then start PostgreSQL;
   - logical: `gpg --decrypt db.dump.gpg | pg_restore --no-owner -d <db>`.
4. Files: `gpg --decrypt files.tar.gpg | tar -x -C /srv/core/shared/storage/app/private`.
5. Deploy the artifact with `deploy.sh`. Its migrate step is a no-op for the
   same release.
6. Verify: run `core:snapshot --compare=<backup>/snapshot.json` (exact for a
   logical restore; a PITR point differs by the changes since then) and
   `core:audit-verify`. Seals shipped after the restore point show as
   "missing in the database": that gap is the data lost. Record it. Then run
   the application checks below.
7. Re-enable the scheduler and workers, and announce the restore point to
   users. Work done after it must be re-entered.

## Restore drill (monthly; also before go-live)

On an isolated host, from the **off-host** copy:

```bash
RESTORE_ROOT=/srv/restore-drill/$(date +%Y%m%d) PGBIN=/usr/lib/postgresql/18/bin \
ARTIFACT=/srv/artifacts/core-<sha8>.tar.gz ENV_FILE=/secure/core.env \
STATUS_FILE=/var/lib/core-backup/status.json \
core-platform/deploy/backup/core-restore-drill.sh /mnt/offhost/core-backups/<backup id> /secure/drill-account.json
```

It starts a new PostgreSQL cluster on a local port, restores the database and
files, and boots the release against them. It then checks:

- counts and checksums equal the backup's (`core:snapshot --compare`);
- the file count equals the manifest's;
- the audit chain verifies, and so do the off-server heads;
- `restore-verify.php` passes: an administrator signs in with MFA, an employee
  and their placement open, the hierarchy loads, a card verifies by UUID and
  by credential, a forged credential is refused, and a document downloads with
  its checksum but not without a session.

The result goes into the status file (`restore_tested_at`,
`restore_test_result`). Keep `drill-report.txt` as evidence.

The drill account is a city-wide administrator with MFA, kept for drills only.
Its credentials stay with the restore operator, never in the repository.

## Backup failure

See [core-operations-runbooks.md](core-operations-runbooks.md) § Backup failure.
