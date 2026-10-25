# ADR 0012: Core platform — go-live operations

Status: accepted (technical decisions). The business and infrastructure decisions this
depends on are open and listed in [core-owner-matrix-and-risks.md](../../go-live/core/core-owner-matrix-and-risks.md).
Date: 2026-10-26. Follows ADR 0011.

## Context

The final certification (ADR 0011 follow-up) returned NO-GO on blockers that
were operational rather than code: no backups and no restore drill, no
staging deployment or rollback rehearsal, no load test, no external
monitoring, keys derived from APP_KEY, no business UAT or penetration test,
and no migration rehearsal on realistic data. This round closes what can be
closed in the repository and on a workstation, and records the rest as
work for the owners.

## Decisions

### 1. The configuration review is the deployment gate

`core:config-audit` fails in production, and with it `deploy.sh`, on:

- credential keys, the seal key or `NATIONAL_ID_HASH_KEY` derived from
  APP_KEY;
- any two secrets that share a value (APP_KEY, the database password, the
  seal key, the credential keys, the national ID key, the monitor token);
- no off-server seal heads;
- a missing or unhealthy backup status, including a missing or failed
  restore test;
- a scheduler with no heartbeat in 10 minutes;
- a private disk under `public/`.

These checks are stricter in production and only warn elsewhere.

The scheduler check is a heartbeat, not "every task ran recently". The
rehearsal showed that the stricter form refuses every release that adds a
scheduled task: the task cannot have run before its release is deployed.

### 2. Seal heads leave the database server

`core:audit-ship-heads` runs every five minutes. It writes each seal's head
to `CORE_AUDIT_HEAD_DISK` (a mounted host, or an object-locked bucket) as
`heads/<to_sequence>.json`, plus `latest.json`. It is idempotent and catches
up after a failure. `core:audit-verify` compares every seal with its copy
(allowing 30 minutes' grace for a seal not yet shipped). It reports a seal
that changed, one that is missing off-server, and seals removed from the
database. The last case also describes a restore to an earlier point, which
is expected and recorded after a planned restore. A failed shipment turns
System Health › Audit integrity to error until a run succeeds.

### 3. Probes for load balancers and the external monitor

`/health/live` and `/health/ready` (no session, check names only) and
`/health/monitor` (bearer `CORE_MONITOR_TOKEN`; 404 when no token is set).
The monitor endpoint returns:

- every System Health status;
- five-minute API and card-check counts, errors and p95;
- queue, scheduler, backup, audit, webhook and storage figures;
- the deployed release.

No personal data is included. The alert rules are in the monitoring runbook;
they are not active until they have an owner and a channel.

### 4. Trusted proxies come from the configuration

`config/trustedproxy.php` reads `TRUSTED_PROXIES`. Before this, nothing read
it. Behind the rehearsal's TLS front, release a405cf98 redirected sign-ins
to `http://`, and sign-in throttling saw a single client address.

### 5. One artifact per commit; code-only rollback

- `build-artifact.sh` builds once (in CI): production Composer dependencies,
  built screens and `RELEASE`.
- `deploy.sh` migrates **before** the switch, while the old release serves.
  That is only safe with additive migrations, so the migration rules in the
  deployment runbook are mandatory.
- `rollback.sh` switches code and assets back and never runs
  `migrate:rollback`.

### 6. Backups are host tooling

- **Database:** pgBackRest (physical, WAL archiving, two repositories) is the
  production path. `core-backup.sh` also takes a logical dump, GPG-encrypted
  to a public key; that dump is what the drill restores.
- **Files:** a tar of the private disks, encrypted the same way.
- **Checks:** SHA256SUMS, plus the off-host copy checked after copying.
- **Status:** the result goes into the status file the Core reads.
- **Secrets:** not in the backup. They come from the secret store, restored
  first.

### 7. Imported cards get their legacy credential at import

The credentials migration backfills the cards present when it runs. At
go-live the schema is migrated first and the cards are imported after, so
`core:import-from-euisis` runs the same `LegacyCredentialBackfill`. Without
it, every printed QR code would have stopped verifying. Imports cap rows per
insert by the column count (PostgreSQL allows 65,535 bound values per
statement).

### 8. Time: stored in UTC, shown in Addis Ababa time (proposal D-06)

Checked, not assumed:

- Both EUISIS and the Core run with `app.timezone = UTC`.
- Every timestamp in the database, the audit trail, the API (ISO-8601 with
  `Z`) and the scheduler is UTC.
- **Today the screens, exports and e-mail show those UTC times as they are.**
  The shared screen formatter (`formatDateTimeDisplay`) and `CoreDates` only
  reformat them. Staff therefore read times of day 3 hours behind Addis Ababa
  time. Dates (birth, expiry) are unaffected, except for timestamps between
  21:00 and 24:00 UTC.
- The cron times in `routes/console.php` are UTC: 00:15 UTC is 03:15 in
  Addis Ababa.

Proposal D-06: keep storage, the API and the scheduler in UTC, and show
Africa/Addis_Ababa (UTC+3, no daylight saving) wherever a time of day is
shown. This is one change in the shared formatter (EUISIS source, synced to
the Core) plus `CoreDates`, and both applications must change together, or
the screens and the exports disagree. It is **not changed in this round**:
it waits for the business decision. Until then, risk R-15 (P2) applies.

### 9. Portability

The migrations use `dateTime()` for NOT NULL times. On MariaDB with
`explicit_defaults_for_timestamp=0`, `timestamp()` was refused, or silently
given `ON UPDATE CURRENT_TIMESTAMP`. The scale index falls back to a plain
index on MariaDB. The suite passes on SQLite, PostgreSQL 18.6 and MariaDB
10.4. PostgreSQL remains the rehearsed production engine.

## Consequences

- A production server that is not configured safely cannot complete a
  deployment.
- The audit trail's integrity evidence exists in two places.
- The deployment, rollback, backup, restore drill, reconciliation and load
  procedures are scripts that the infrastructure team can run unchanged on
  real staging. The workstation rehearsal is evidence that the procedures
  work, not that production infrastructure is ready.
