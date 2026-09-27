# Backup health troubleshooting

The Backup & Recovery page, `php artisan backup:health` and `php artisan production:readiness`
report a specific **reason code**. None of these states is ever shown as healthy. This page
explains each code. Architecture: [backup-recovery-architecture.md](../backup-recovery-architecture.md#status-integration).

## First steps

1. Run the check **as the account that runs it in production**. That is the PHP-FPM user for
   page renders (cached up to `BACKUP_STATUS_CACHE_SECONDS`) and the `schedule:run` user for the
   five-minute monitor:

   ```sh
   sudo -u www-data php artisan backup:health -v
   ```

2. `-v` prints sanitized diagnostics only: platform, `run_as`, `config_file`
   (PRESENT/MISSING/UNREADABLE/NOT_SET/NOT_CHECKED), the command (`version`/`info`), the exit
   code, whether stdout and stderr had output, the pgBackRest error number
   (`PGBACKREST_ERROR_055`) and the parser result. Raw stderr is never shown or logged, because
   it can contain paths and options. For full detail, reproduce the command as the backup
   account (below) in a restricted shell.
3. With `PGBACKREST_RUN_AS`, the command runs as that account via `sudo -n`. Otherwise it runs as
   the PHP account itself.
4. Failures are also logged as `backup.health.failed` (reason code, exit code, environment,
   time) when a fresh probe returns CRITICAL, UNKNOWN or unavailable.

The equivalent manual commands, as the backup account:

```sh
pgbackrest version
pgbackrest --stanza=euisis --output=json info
pgbackrest --stanza=euisis check      # switches and archives a WAL segment; operator action
```

Never fix a status by making repositories, configuration or keys world-readable (no `chmod 777`),
by disabling archiving, or by editing attestations without evidence.

## Environment rules

Outside production, missing infrastructure is reported as NOT_CONFIGURED, INFRASTRUCTURE_UNAVAILABLE
or UNKNOWN with "Production blocker: NO". In production, or with
`BACKUP_ENFORCE_PRODUCTION_RULES=true` or `production:readiness --strict`, the same condition is
**CRITICAL** and a production blocker. Development machines (including Windows) are not expected
to run pgBackRest.

## Infrastructure reasons

### NOT_CONFIGURED
- **Meaning:** `BACKUP_STATUS_ENABLED` is false or unset, so no probe runs. (This was the cause
  of the original "INFRASTRUCTURE NOT AVAILABLE OR INVALID REPORT" message.)
- **Verify:** `php artisan backup:health` prints `Backup Integration: DISABLED`.
- **Safe remediation:** In development, nothing to do. In production, deploy a status driver
  ([database-backup.md](database-backup.md#connect-the-application-status-integration)), then
  set `BACKUP_STATUS_ENABLED=true` and refresh the config cache.
- **Production impact:** CRITICAL and a blocker. There is no evidence that backups exist.

### COMMAND_NOT_FOUND
- **Meaning:** The `PGBACKREST_BINARY` path (or `PGBACKREST_SUDO_BINARY` with run-as) does not
  exist. The process could not start, or the shell reported exit 127. This is expected on a
  development machine without pgBackRest.
- **Verify:** `-v` shows `preflight: BINARY_NOT_FOUND` or `SUDO_NOT_FOUND`. Check `ls -l <path>`
  and `command -v pgbackrest` on the host.
- **Safe remediation:** Install the pinned pgBackRest package on the host that should run the
  check, or correct the absolute path. If this host has no pgBackRest access by design, use
  `BACKUP_DRIVER=report`.
- **Production impact:** CRITICAL and a blocker.

### CONFIG_NOT_FOUND
- **Meaning:** `PGBACKREST_CONFIG` points to a missing file, or pgBackRest reported a missing
  `.conf` file.
- **Verify:** `-v` shows `config_file: MISSING` or `PGBACKREST_ERROR_055`. Check the path as the
  backup account.
- **Safe remediation:** Correct the path, or leave `PGBACKREST_CONFIG` empty and use the default
  `/etc/pgbackrest/pgbackrest.conf` through `PGBACKREST_RUN_AS`. Do not copy the configuration
  (which contains cipher keys) somewhere PHP can read it.
- **Production impact:** CRITICAL and a blocker.

### INVALID_CONFIGURATION
- **Meaning:** Application-side settings failed validation before any process started, or
  pgBackRest rejected an option. Application-side failures include a stanza outside
  `[A-Za-z0-9_-]`, a relative binary or config path, an invalid run-as name, an unknown
  `BACKUP_DRIVER`, or out-of-range thresholds and timeouts. pgBackRest option errors are
  [031]–[037]. A cipher or database mismatch reported per repository also lands here.
- **Verify:** `-v` shows `preflight: INVALID_STANZA`, `RELATIVE_OR_INVALID_PATH`,
  `INVALID_RUN_AS`, `policy: INVALID` or the pgBackRest error number.
- **Safe remediation:** Correct the `.env` values (see `.env.example`) and run
  `php artisan config:cache`. For a database mismatch, confirm the stanza matches this cluster
  before any `stanza-upgrade`. Never point one stanza at two clusters.
- **Production impact:** CRITICAL and a blocker.

### PERMISSION_DENIED
- **Meaning:** The executing account may not run pgBackRest or read its configuration, lock or
  repository. This covers a sudo refusal (`a password is required`), exit 126, and an SSH or
  repository `Permission denied`.
- **Verify:** Note which account ran the check (see First steps). Run `sudo -l -U www-data` to
  list the granted commands; they must match the argument vector exactly. Then run the manual
  command as the backup account.
- **Safe remediation:** Fix the exact sudoers rule, ownership of the backup account, or SSH keys
  for the repository host. Keep repositories mode 0700/0750 and owned by the backup account.
  Never grant the web account broad sudo or group access to the repository.
- **Production impact:** CRITICAL and a blocker.

### COMMAND_TIMEOUT
- **Meaning:** `version` or `info` exceeded `PGBACKREST_TIMEOUT_SECONDS` (default 20 seconds),
  so the state could not be established.
- **Verify:** Time `pgbackrest --stanza=euisis --output=json info` as the backup account. Check
  repository host latency, locks and the number of retained backups.
- **Safe remediation:** Fix repository reachability or performance. Raise the timeout (maximum
  120 seconds) only after measuring. The page stays responsive because the probe is bounded and
  cached.
- **Production impact:** UNKNOWN in development; CRITICAL and a blocker in production.

### COMMAND_FAILED / DATABASE_UNAVAILABLE
- **Meaning:** pgBackRest exited non-zero for a reason no other code covers (COMMAND_FAILED), or
  could not connect to PostgreSQL (DATABASE_UNAVAILABLE, mainly from `check`).
- **Verify:** `-v` gives the exit code and pgBackRest error number. Reproduce the command as the
  backup account and read its own log.
- **Safe remediation:** Resolve the cause shown by the tool. Add a classification if a recurring
  failure deserves its own code.
- **Production impact:** CRITICAL and a blocker.

### INVALID_COMMAND_OUTPUT
- **Meaning:** The command succeeded, but its output failed validation. Parser results: output
  that is empty or oversized, output that is not JSON, an unexpected shape (for example
  pgBackRest older than 2.33, which has no per-repository status), an unknown status code, or an
  invalid backup entry. An unrecognized `version` banner also produces this code.
- **Verify:** `-v` shows `parser: INVALID_JSON`, `UNEXPECTED_SHAPE` and so on, plus
  `stdout_present`. Compare `pgbackrest version` with the pinned, tested version.
  `--log-level-console=off` must be in effect, because console logging would corrupt the JSON.
- **Safe remediation:** Pin a supported pgBackRest release (2.33 or later). If the upstream format
  changed, update `PgBackRestInfoParser` and its tests together. Never loosen validation to
  accept unknown data.
- **Production impact:** CRITICAL and a blocker.

## Repository and backup reasons

### STANZA_NOT_FOUND
- **Meaning:** pgBackRest answered, but the configured stanza does not exist in the repositories.
  This covers: `info` returned `[]`, `missing stanza path` or `missing stanza data`, and
  `has a stanza-create been performed?`.
- **Verify:** `pgbackrest --stanza=euisis --output=json info` as the backup account. Check that
  `PGBACKREST_STANZA` matches the stanza in `pgbackrest.conf`.
- **Safe remediation:** During the approved provisioning window, and never from the web
  application, run as the backup account:

  ```sh
  pgbackrest --stanza=euisis stanza-create
  ```

  Then run `pgbackrest --stanza=euisis check` and the first full backup in **both** repositories.
- **Production impact:** CRITICAL and a blocker.

### REPOSITORY_UNAVAILABLE / REPOSITORY_NOT_CONFIGURED
- **Meaning:** A repository reported status 99 (storage missing or unreachable, a failed repository
  host connection, or invalid object-store settings), or pgBackRest has no `repo1`/`repo2`
  section. PERMISSION_DENIED is reported separately when the tool's message says so. Error text
  is classified, never displayed.
- **Verify:** Per-repository status in the Repository card or `backup:health`. On the backup host,
  check mounts, `repo<N>-host` SSH (with pinned host keys), and object-store TLS and endpoint
  reachability.
- **Safe remediation:** Restore storage or connectivity. Configure both repositories; two
  directories on the primary do not count as independent. Never disable TLS or host-key checks.
- **Production impact:** CRITICAL and a blocker. A single repository means there is no
  independent copy.

### BACKUP_NOT_FOUND
- **Meaning:** The infrastructure is configured and the repository answers, but it holds no valid
  backup (status code 2, an empty backup list, or only errored backups).
- **Verify:** `php artisan backup:list` and `pgbackrest info`.
- **Safe remediation:** Run the first full backup per repository through the runner
  (`backup.py full --repo=N`), then verify it. Do not create backups from the web application.
- **Production impact:** CRITICAL until a verified backup exists in each repository.

### BACKUP_STALE / FULL_BACKUP_STALE / BACKUP_ERRORS
- **Meaning:** The latest backup is older than `BACKUP_STALE_HOURS` (WARNING) or
  `BACKUP_CRITICAL_HOURS` (CRITICAL), or the latest full backup is older than
  `BACKUP_FULL_STALE_DAYS`. BACKUP_ERRORS means pgBackRest flagged a backup with errors (for
  example page checksum failures); it is excluded as a recovery point and is CRITICAL.
- **Verify:** Check cron and runner journals and `pgbackrest info`. For errored backups, read the
  backup log and run `pgbackrest verify`.
- **Safe remediation:** Fix the schedule or the failure and take a new backup. Treat checksum
  errors as possible data corruption and investigate before relying on any newer backup.
- **Production impact:** WARNING, or CRITICAL beyond the critical threshold.

## WAL / PITR reasons

### WAL_ARCHIVE_UNHEALTHY
Sub-reasons, read from the application's own PostgreSQL connection:

| Sub-reason | Meaning | Verify |
|---|---|---|
| ARCHIVE_MODE_OFF | `archive_mode` is not `on`/`always`, so PITR is impossible | `SHOW archive_mode;` |
| ARCHIVE_FAILING | the most recent archive attempt failed | `SELECT * FROM pg_stat_archiver;` (`last_failed_time` newer than `last_archived_time`) |
| NO_WAL_ARCHIVED | nothing has been archived since startup or a stats reset | `pg_stat_archiver.last_archived_time` is null |
| ARCHIVE_BACKLOG | ready segments exceed `BACKUP_WAL_BACKLOG_SEGMENTS` | `SELECT count(*) FROM pg_ls_archive_statusdir() WHERE name LIKE '%.ready';` |

- **Safe remediation:** Apply `deploy/backup/postgresql.conf.example` during a maintenance window
  (a restart is needed for `archive_mode`). Fix `archive_command` or repository reachability,
  then run `pgbackrest check`. Never delete WAL from `pg_wal` to free space.
- **Production impact:** CRITICAL when `BACKUP_PITR_REQUIRED=true` (the default), WARNING
  otherwise. Backups may still be HEALTHY while point-in-time recovery is degraded.

### WAL_ARCHIVE_LAG / WAL_STATUS_UNKNOWN
- **Meaning:** Nothing has been archived for longer than `BACKUP_WAL_LAG_SECONDS`, which an idle
  cluster can also cause (WARNING). WAL_STATUS_UNKNOWN means the status could not be read: the
  database is not PostgreSQL, or the query failed.
- **Safe remediation:** Check workload and `archive_timeout`. A backlog count requires the
  application role to be in `pg_monitor`, or to hold an explicit EXECUTE grant on
  `pg_ls_archive_statusdir()`; without it, backlog shows as unknown.
- **Production impact:** WARNING.

## Evidence and readiness reasons

- **VERIFY_* / RESTORE_TEST_*:** FAILED is CRITICAL. NOT_RECORDED and OVERDUE are WARNING, as is
  UNKNOWN when history is unavailable. The evidence comes from the runner journal, imported by
  `backup:health --monitor`, and the published report. Run `backup.py verify --repo=N` and the
  isolated `restore-test` ([restore-validation.md](restore-validation.md)).
- **REPORT_NOT_FOUND / REPORT_STALE / INVALID_REPORT / REPORT_NOT_CONFIGURED (report
  driver):** The report is missing, older than `BACKUP_REPORT_STALE_MINUTES`, or failed schema
  validation. Check the `collect` cron job, the transport and file ownership; PHP needs read
  access to `status.json` only.
- **HISTORY_UNAVAILABLE:** The backup tables are missing. Apply the migration with
  `php artisan migrate`. The page still renders.
- **Readiness-only issues** (`*_UNVERIFIED`, `RECOVERY_OBJECTIVES_NEED_DECISION`,
  `ALERT_RECIPIENTS_NOT_CONFIGURED`, `*_CAPACITY_UNKNOWN`) do not change operational health, but
  keep `readiness=NOT_READY`, which is a production blocker. Resolve each with recorded evidence
  and approvals; never by setting values just to pass.
