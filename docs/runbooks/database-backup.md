# Database backup operations

Owner: assigned Database/Infrastructure Operator. Escalation: System Admin and designated
security incident lead. Assign actual people/on-call routes before go-live.

## Provision once, on approved infrastructure

1. Confirm PostgreSQL major, extension binaries, tablespaces, actual data directory and
   repository hosts. Install matching PostgreSQL clients and a pgBackRest version supporting
   `verify`, plus Python 3.10+. Pin/test versions. Do not install on the application web user.
2. Adapt `deploy/backup/pgbackrest.conf.example` for the actual deployment, configure
   the corresponding repository-host settings and peer/SSH access per pgBackRest's
   multi-host instructions. Repository 1 is private independent storage; repository 2 is
   a different off-site failure domain. Pin SSH host keys. Provision each cipher password
   through a mode-0600 secret include and escrow it separately.
3. Provision private repository, lock and log directories. Restrict logs to operations;
   some tool versions log detailed paths/options. Do not enable debug/trace in shared logs.
4. Install `scripts/backup/backup.py` and `restore_test.py` into an ops-owned directory such
   as `/opt/euisis-backup`. Adapt runner/pg_service templates. Create the output directory
   and journal directory (journal mode 0700); PHP can read only the final status report.
5. Collector DB account needs `pg_stat_archiver`, relevant settings and archive-status
   directory access. Grant the narrow supported `pg_ls_archive_statusdir()` execution
   privilege on the server version; do not grant `pg_read_server_files` or superuser to PHP.
   Use a local peer-authenticated backup account when appropriate, otherwise protected
   pg_service/.pgpass and verified TLS. Test the collector as the actual cron identity.
6. Create the stanza, then merge the PostgreSQL archive template in a maintenance window.
   Confirm paths and command binary; restart for archive_mode. Run `pgbackrest --stanza=euisis
   check` and inspect both repositories. **check can switch WAL and archive a test segment;
   it is an operational check, not a purely read-only inspection.** `info` is read-only.
7. Create a full backup in both repositories, then differential/incremental. Pin config
   paths in every command. Do not run a production restore as part of setup.

## Fixed runner commands

Examples assume the reviewed templates were installed under the paths shown:

```sh
python3 /opt/euisis-backup/backup.py full --repo=1 --config=/etc/euisis-backup/runner.json
python3 /opt/euisis-backup/backup.py full --repo=2 --config=/etc/euisis-backup/runner.json
python3 /opt/euisis-backup/backup.py diff --repo=1 --config=/etc/euisis-backup/runner.json
python3 /opt/euisis-backup/backup.py verify --repo=1 --config=/etc/euisis-backup/runner.json
python3 /opt/euisis-backup/backup.py collect --config=/etc/euisis-backup/runner.json
php artisan backup:health          # summary; --json for the sanitized document
php artisan backup:list --repo=2   # labels, types, times and sizes only
```

Repeat daily and verify operations for repository 2. `incr` is available as the approved
daily alternative. The runner serializes mutations using an OS advisory lock; a collision
fails instead of overlapping. Cron must alert and retry a missed operation. Status collection
runs independently while a long backup executes. Tool timeouts yield failure; investigate
stray remote tool processes and pgBackRest locks before retrying. Do not remove live locks.

`verify` checks repository contents; its zero exit status alone is insufficient. The runner
requires text/verbose output proving valid backup and WAL files, and rejects unknown formats
or reported corruption. Pin the tested tool version and validate both success and corruption
fixtures after upgrading. `info`/`check` alone do not prove every stored file's
checksum or application recovery. A successful backup without a successful isolated restore
test is insufficient. Failed verification blocks deployment until repaired/retested.

For remote repository capacity, run a restricted host agent that publishes only
`{"observed_at": <UTC epoch>, "usage_percent": <0..100>}`. Configure `capacity_reports`
with local protected report paths keyed `1`/`2`; transfer atomically over authenticated SSH.
Reports older than 15 minutes are unknown. `capacity_paths` is valid ONLY when the actual
repository filesystem is mounted locally; using the primary disk's free space is incorrect.
Monitor the primary WAL filesystem capacity separately at infrastructure level.

## Optional logical archive

`backup.py logical --config=...` produces a supplementary encrypted `pg_dump --format=custom`
archive. It is never the primary recovery path. Before enabling it:

1. Create a dedicated read-only role (for example one granted `pg_read_all_data`). Do not use
   the Laravel role or a superuser. Add the `euisis_logical_backup` pg_service entry.
2. Choose an approved public-key encryptor that reads stdin and writes stdout. Set
   `encrypt_command` as a fixed absolute argument vector. Only the public recipient is
   installed on the host; escrow the private key with the other recovery keys.
3. Point `output_dir` at private (mode 0700), independent storage. It must not be the database
   disk or a web root. Replicate it off-site if the policy requires.
4. Set `retain_count`. The runner deletes only its own archive names beyond that count.
5. Uncomment the logical cron line, then set `BACKUP_LOGICAL_ENABLED=true` so staleness is
   monitored. Periodically restore one archive with `pg_restore` into an isolated database.

## Connect the application status integration

Choose one driver per application host. Both are read-only, and neither can start a backup,
restore, expire or stanza-create.

**`pgbackrest` driver** (pgBackRest reachable from the application host). The web and scheduler
account runs exactly two argument vectors. Grant them through sudo as the backup account, so
PHP never reads `pgbackrest.conf` or the repository cipher keys:

```text
# visudo -f /etc/sudoers.d/euisis-backup-status  (adapt the web account, backup account and binary path)
Defaults!/usr/bin/pgbackrest env_reset
www-data ALL=(postgres) NOPASSWD: /usr/bin/pgbackrest version, \
  /usr/bin/pgbackrest --stanza=euisis --output=json --log-level-console=off --log-level-stderr=warn --log-level-file=off info
```

```dotenv
BACKUP_STATUS_ENABLED=true
BACKUP_DRIVER=pgbackrest
PGBACKREST_BINARY=/usr/bin/pgbackrest
PGBACKREST_STANZA=euisis
PGBACKREST_RUN_AS=postgres
PGBACKREST_CONFIG=            # leave empty with RUN_AS; if set, it becomes part of every command
```

If `PGBACKREST_CONFIG` is set, `--config=<path>` is the first argument and the sudoers rule must
include it verbatim. Do not grant `check`, `backup`, `expire`, `restore` or wildcards to the web
account. `php artisan backup:health --check` is for an operator account that already holds
backup privileges.

**`report` driver** (no pgBackRest on the application host). Deploy the runner `collect` job and
the protected report transport described below, then set `BACKUP_STATUS_ENABLED=true`,
`BACKUP_DRIVER=report` and `BACKUP_STATUS_PATH`.

Both drivers also read the runner report, when present, for the operation journal (verification,
restore tests), control attestations and capacity. Without it those items read as not recorded.

Verify as the account that runs the scheduler:

```sh
sudo -u www-data php artisan backup:health -v   # reason code + sanitized diagnostics
sudo -u www-data php artisan backup:list
php artisan production:readiness --strict
```

Any status other than HEALTHY names a specific reason code. Follow
[backup health troubleshooting](backup-health-troubleshooting.md). Never make repositories,
configuration or keys world-readable to get a green status.

## Scheduling, retention and alerts

Review and install the cron example with actual timezone/windows. WAL is continuous;
physical backups run separately per repository. Monthly test cron belongs ONLY on the
isolated host. Keep Laravel `schedule:run` for five-minute metadata/audit/notification polling.
Set BACKUP_STATUS_ENABLED only after the chosen status driver passes `backup:health`. Configure active
authorized alert user IDs and optionally mail; send a deliberate staging failure/stale report
to verify delivery. Monitor cron and app monitoring failures externally.

Set retention counts in pgBackRest and Laravel policy metadata together. Run tool-managed
`expire` only after checking the policy/holds and verified restore coverage. Its journal
records RETENTION_CLEANUP (including RUNNING/FAILED/SUCCEEDED). Never use filesystem age
deletion against backup chains/WAL. Retention changes are reviewed infrastructure changes
with a ticket; there is no web deletion endpoint. Off-site immutable retention must be
compatible with expiry: if deletion is denied, alert and resolve policy/capacity explicitly.

Escalate full/daily backup failure, missing collector heartbeat, WAL failure/backlog,
unavailable repository, capacity warning, stale full/verification/test and key/file recovery
expiry. Fixed report codes point to restricted logs; never paste raw stderr, environments
or credentials into application audit/notifications. Preserve journals and import them before
rotating; the report includes only the latest 1,000 events. Transfer isolated test journal
files to the collector journal directory over pinned SSH, with exclusive ops ownership.

## Failure recovery

Preserve logs and current data. Investigate permissions, connectivity, space, archive
backlog and tool version. Do not disable archiving, discard WAL, shorten retention or wipe
a repository to make checks pass. Use the independent repository to validate recovery;
restore/repair a corrupted repository through the approved incident procedure. Record
successful re-verification and a new isolated test. Update current attestations only after
evidence review. Escalate when recovery objectives cannot be met.
