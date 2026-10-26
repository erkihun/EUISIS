# Core Golden Path: go-live evidence (2026-10-26)

What was executed, with its results. Anything **not executable here** is
labelled so, with the runbook that the responsible team runs. Rehearsals ran
on a **developer workstation**, not production hardware:

- Windows 11 Pro, Intel i9-14900HX (32 threads), 15.7 GB RAM;
- PostgreSQL 18.6, PHP 8.4.25, Node 26.7, k6 2.2.

Synthetic or development data only. No production data or personal data
left the machine.

## 1. Environment

Built under the staging root (scratch directory), with the same classes as
production where the workstation allowed:

| Class | Rehearsal | Production-like? |
|---|---|---|
| Web front | `loadtest/rig/tls-lb.mjs`: TLS 1.2+/1.3 with a self-signed certificate, health-checked round-robin, `X-Forwarded-*` | stands in for Nginx or the load balancer |
| PHP | 4 `php -S` nodes with OPcache, PHP 8.4.25 | stands in for PHP-FPM; **no Linux, Nginx or PHP-FPM available** (no WSL or Docker on this machine) |
| Database | PostgreSQL 18.6, its own `emscoredb_staging` | yes |
| Sessions, cache, queue | `database` drivers, 2 `queue:work` processes and `schedule:work` | yes (the Core needs no Redis) |
| Storage | shared private disk (junction) outside the releases | local directory, not object storage |
| Config | `APP_ENV=production`, `APP_DEBUG=false`, `https://` APP_URL, secure cookies, `TRUSTED_PROXIES=127.0.0.1` | yes |

**NOT EXECUTABLE HERE:** a Linux/Nginx/PHP-FPM staging server, object
storage, a real certificate. Runbook: [core-staging-rehearsal.md](../../runbook/core-staging-rehearsal.md).

## 2. Production configuration audit

`php artisan core:config-audit` on staging (APP_ENV=production), release
39928823: **26/26 checks pass, no warning.** The checks:

- APP_ENV, APP_DEBUG, APP_KEY, APP_URL https;
- session: secure, SameSite, HttpOnly, database driver, encrypted;
- queue database; cache database; MAIL; LOG_LEVEL info;
- https origins; TRUSTED_PROXIES; MFA enforced;
- credential keys configured; seal key configured; national ID key
  configured; keys independent;
- seal heads off-server; backup status file; backup healthy;
- scheduler heartbeat; private storage; monitor token.

The audit was **made stricter, never weaker** (ADR 0012 §1). The earlier
release 982aa159 was **refused** by its own review (SCHEDULER), and the
previous release kept serving. That exposed a flaw in the check, which
refused any release adding a scheduled task. It was fixed in 172be1be.

## 3. Security keys

Staging got independent random keys: `APP_KEY`, `CORE_CREDENTIAL_KEYS`,
`CORE_AUDIT_SEAL_KEY`, `NATIONAL_ID_HASH_KEY` and `CORE_MONITOR_TOKEN`
(32 bytes each, from `random_bytes`). They were written only to the staging
`.env` and never committed or printed. `KEY_INDEPENDENCE` checks that no two
of them, APP_KEY or the database password, share a value (tested).

| Check on staging | Result |
|---|---|
| Audit sealing | 35 rows sealed in one run |
| Audit verification | "All 119 sealed audit rows match. All 4 off-server seal heads match." |
| Credential generation | 4 Golden Path cards activated with credentials |
| Credential verification | 4/4 valid (`CredentialVerifier`); a partner client verifies over HTTPS without holder data |
| Rotation: add key 2 (key 1 in a 30-day grace period) | current = 2; 4/4 still valid on key 1 |
| `core:credentials-rekey` | "4 credential(s) moved to key version 2"; 4/4 valid |
| Retire key 1 | only key 2 configured; 4/4 valid |
| Seal-key rotation | **not supported without losing verification of older seals** (risk R-07); rotate only on compromise |

**NOT EXECUTABLE HERE:** provisioning in the organization's secret store.
That is the secret-store owner's work, following the list in the release
manifest.

## 4. Seal heads off the server

`core:audit-ship-heads` runs every five minutes and wrote
`heads/000000000042.json`, `…049`, `…084`, `…119` and `latest.json`. On
staging the destination is another directory on the same disk, because no
other host was available here.

| Item | Value |
|---|---|
| Destination | `CORE_AUDIT_HEAD_DISK`: a mount of a log host, or an S3 bucket with object lock (production) |
| Frequency | 5 minutes; catches up after a failure |
| Failure handling | the command fails, the log records it at critical, System Health › Audit integrity shows error, and `/health/monitor` sets `audit_heads_ship_failed=true` (tested) |
| Retention | that of the destination (object lock: at least the audit retention; owner TBD) |
| Access | the Core writes; the database host has no access; read access for auditors only |

## 5. Backups

`deploy/backup/core-backup.sh` with `BACKUP_DB_TOOL=pg_dump` (pgBackRest is
not available on Windows):

| Item | Result |
|---|---|
| Backup id | `core-20261025T214619Z` |
| Timestamp | 2026-10-25 21:46:19 to 21:46:21 UTC (2 s) |
| Contents | `db.dump.gpg` (111,927 bytes, custom format), `files.tar.gpg` (3 private files: photo, signature and document, 14,377 bytes), `snapshot.json`, `manifest.json`, `SHA256SUMS` |
| Encryption | GPG to a public key (RSA 3072, throwaway staging key); the backup host holds no private key |
| Off-host copy | copied to `offhost/backups/`, then `sha256sum -c` passed. **Another directory on the same disk, not another host** |
| Retention | `RETAIN_COUNT=3` (proposal, see RPO) |
| Status file | the Core reads `last_success_at`, `encrypted=true`, `offhost_copy=true`; System Health › Backups = healthy only after a passed restore test |
| Failure reporting | the status file records `last_failure_at` and the step; then `ALERT_CMD` runs |

RPO: **BUSINESS DECISION REQUIRED**. RTO: **BUSINESS DECISION REQUIRED**.
Once they are given, compare them with these measurements:

- the logical restore below;
- about 11 minutes for 3 million employees (`docs/production-readiness/backup-pitr-restore.md`);
- an RPO in minutes needs pgBackRest and WAL (PITR).

**NOT EXECUTABLE HERE:**
- pgBackRest physical backups, WAL archiving and PITR;
- an off-host or off-site repository;
- an escrowed production key.

Runbook: [core-backup-and-restore.md](../../runbook/core-backup-and-restore.md);
templates in `core-platform/deploy/backup/`.

## 6. Restore drill (a real restored instance)

`core-restore-drill.sh` restored backup `core-20261025T214619Z` **from the
off-host copy** into a **new PostgreSQL 18.6 cluster** (initdb, port 55434).
The application was the backup's release artifact (a405cf98), with the
recovered environment.

| Step | Result |
|---|---|
| Restore start / end | 21:50:38 to 21:52:38 UTC: **120 s** in all (database restore under 1 s; the release unpacked in 31 s; the rest is the checks) |
| Checksums | all artifacts match SHA256SUMS |
| Database | restored; `core:snapshot --compare`: **identical to the backup** |
| Record counts | 23 organizations, 15 units, 5 positions, 30 employees, 30 placements, 5 cards, 1 credential, 3 documents, 7 users, 3 roles, 49 audit rows, 1 seal, 28 migrations |
| Files | 3 files restored = the manifest's 3 |
| Audit chain | "All 42 sealed audit rows match." |
| The application starts | `GET /up` 200 (this release predates `/health/ready`) |
| Administrator sign-in | password, then the second factor asked for, then TOTP accepted |
| Employee data, placements | employee EMP-000001 opens; the API lists all 30 employees; the record carries its current placement |
| Organization hierarchy | `/api/v1/hierarchy` loads (4,475 bytes) |
| Cards and credentials | an active card verifies by UUID; its credential decrypts with the restored APP_KEY and verifies with the restored credential keys; a forged one is refused |
| Documents | download for the authorized administrator with a matching SHA-256; refused (302 to sign-in) without a session |
| Status file | `restore_test_result=passed`, `restore_test_seconds=120` |

The two earlier attempts on the same backup failed on the drill script's own
checks (a `/health/ready` the old release lacks; where MFA is asked for).
They are recorded honestly in the status history; the scripts were fixed.
**NOT EXECUTABLE HERE:** a PITR restore from pgBackRest repository 2 on
another host.

## 7. Staging deployment and rollback rehearsal

Release procedure: `deploy.sh` (checksum, unpack, links, migrate, optimize,
`core:config-audit`, switch, reload, worker restart, smoke). It was run
unchanged on every release:

| Step | Release | Result |
|---|---|---|
| Build | efcf13c1, a405cf98, 982aa159, 39928823 | artifacts of 7.6–7.9 MB, 6–12 min each (composer --no-dev, npm ci, vite build) |
| Deploy efcf13c1 (N, first try) | | **refused before the switch**: the release predates `core:config-audit`. Its 27 migrations had run (empty database) |
| Deploy a405cf98 (N) | | 1 migration in 2 s; review passed; switch; smoke passed (`/up`, `/login` serves `app-BA52qTfy.js`). Earlier runs failed on rig problems (fixed), and re-running the script was safe |
| Data | | `core:import-from-euisis` (28 employees from the development EUISIS copy); 7 synthetic staging accounts with MFA |
| Golden Path on N, HTTPS | | 36 checks passed in 25.4 s. **Found:** sign-in redirected to `http://`, the TRUSTED_PROXIES defect fixed in 2e8e8042 |
| Deploy 982aa159 | | migration `2026_10_29_100100` ran (3 s); then **refused** by the review (SCHEDULER); N kept serving |
| Golden Path on N with N+1's schema | | passed in 23.7 s (backward compatibility) |
| Deploy 39928823 (N+1) | | 71 s; review passed (26/26); switch; nodes reloaded; workers restarted; smoke passed incl. `/health/monitor` reporting 39928823 |
| Golden Path on N+1, HTTPS | | passed in 36.7 s; redirects stay https |
| Simulated fault | N+1 | the build manifest removed: `GET /login` = 500 three times |
| **Application rollback** | N+1 to N | `rollback.sh` in **17 s**: reported "database ahead of a405cf98 by 2026_10_29_100100 (additive; left in place)"; switched code and assets; reloaded; restarted workers; smoke passed (`/login` serves N's `app-BA52qTfy.js`) |
| Golden Path on N after rollback | | passed in 29.4 s |
| Fix forward | N+1 | the broken release removed and redeployed from its artifact in 49 s; smoke passed; shared storage intact |
| Final candidate | 15c095aa | see §7a |

**Found and fixed by the rehearsal:**
- 2e8e8042: TRUSTED_PROXIES was never read.
- 172be1be: the scheduler check refused new tasks; imports failed at
  volume; an unreadable upload gave a 500.
- 2c8518fe: the CSP inline script.
- 15c095aa: workers missed the `notifications` queue.

**NOT EXECUTABLE HERE:** an Nginx/PHP-FPM reload, systemd units, real DNS and
TLS. Runbooks: [core-deployment.md](../../runbook/core-deployment.md) and
[core-rollback.md](../../runbook/core-rollback.md).

### 7a. Final candidate on staging

| Item | Result |
|---|---|
| Release | 15c095aa (artifact SHA-256 `18157116…3912`, 7.7 MB): see the [release manifest](core-release-manifest.md) |
| Deploy | 68 s: migrate (nothing pending), configuration review passed, switch, reload, workers restarted, smoke passed incl. `/health/monitor` reporting 15c095aa |
| Golden Path over HTTPS | passed in 38.6 s |
| CSP, first pass (release 39928823, signed in as an administrator, 15 page loads) | **1 distinct violation on every page**: the inline theme bootstrap (`script-src 'self'`, hash `sha256-txPkqvLO…`). Classified as a required application source, so it needs a nonce |
| CSP, second pass (release 15c095aa, the same 15 pages: sign-in, dashboard, employees, organizations, positions, cards, print batches, reports, data quality, Security Center, System Health, notifications, users, settings, card templates) | **0 console messages, 0 violations**. The policy carries a per-request nonce and the theme script carries it (checked in the page). Still report-only; `CORE_CSP_ENFORCE=true` enforces it (decision D-11) |
| Suites on the candidate | SQLite 230/230 · PostgreSQL 18.6 230/230 · MariaDB 10.4 228 passed + 2 skipped · frontend typecheck pass · i18n audit 0 blocking (23,786 keys) · `composer audit` none · `npm audit --omit=dev` 0 |
| `/health/monitor` over HTTPS | 200 with the token, 401 without; release reported; 19 metrics. The status was "warning" only because the daily tasks had not run yet. The scheduler alert therefore uses the every-minute tasks |

## 8. Migration rehearsal on realistic data

A sanitized copy of production data was **not available here** (R-09). Two
rehearsals were run into fresh Core databases with the go-live sequence
(migrate an empty schema, then `core:import-from-euisis`), then
`core:snapshot --compare` and `deploy/migration/reconcile.php`.

| | Development EUISIS copy (`aaemployeedb`) | Synthetic EUISIS at volume (`aaemployeedb_scale`) |
|---|---|---|
| Migrate (29) | 20 s | 4 s |
| Import | 1 s | 386 s (`--chunk=2000`) |
| Fingerprint comparison | every table identical | every table identical (45 s) |
| Organizations / units | 21 / 13 → 21 / 13 | 240 / 2,400 → 240 / 2,400 |
| Positions / employees / placements | 3 / 28 / 28 → equal | 220,000 each → equal |
| Cards / card requests | 4 / 4 → equal | 220,000 / 0 → equal |
| Rejected (source rows missing) | 0 in every table | 0 in every table |
| Duplicates (numbers, national ID hashes, card numbers, card UUIDs, two current placements) | 0 | 0 |
| Orphans (placements, positions, units, edges, cards, requests) | 0 | 0 |
| Live cards without a credential | 0 (4 legacy credentials made) | 0 (220,000 made) |
| For review (not loss) | positions without grade/occupation 3; placements of unspecified type 28 | 220,000 / 220,000 / 220,000 (the synthetic data carries none) |

**Found and fixed:**
- The first volume run **failed and the reconciliation caught it**: 220,000
  cards were rejected because 35 columns × 2,000 rows exceeded PostgreSQL's
  65,535 bound values. Fixed in 172be1be, then re-run clean.
- Imported cards had no credential, so their printed QR codes would have
  failed. Fixed in 982aa159, and shown on staging: the reconciliation failed
  with 4 cards before the backfill and passed after it.

## 9. Load and concurrency

**Not production hardware, and no agreed targets (D-03), so no pass
threshold is set.** These are the measured capacity of this rig and where it
saturates.

### Setup

- **Rig:** release 15c095aa; 16 single-threaded `php -S` nodes with OPcache,
  standing in for a 16-worker PHP-FPM pool; the Node TLS front on 8444
  (forwarding `X-Forwarded-For` as nginx does).
- **Database:** the 220,000-employee imported database on PostgreSQL 18.6.
- **Load:** k6 2.2, `core-platform/loadtest/k6/core-golden-path.js`.
- **Accounts:** 100 synthetic viewer accounts (one per virtual user, each with
  its own address) and 20 partner clients.
- **Mix:** search 25%, profile 20%, card check by UUID 15%, partner
  credential check 15%, organization tree 10%, dashboard 10%, sign-in 5%.
- **Runs:** 60 s per step after a 30 s warm-up.

### Results

| Virtual users | Requests | req/s | p50 | p95 | p99 | Errors | 429 | PHP CPU (of 32 threads) | PHP memory (16 nodes) | DB connections avg/max | Queue |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 5 | 1,395 | 23.2 | 126 ms | 497 ms | 653 ms | 0% | 0 | 5.3% | 644 MB | 4.8 / 9 | 0 |
| 20 | 2,560 | 42.3 | 389 ms | 1,130 ms | 1,539 ms | 0% | 0 | 8.9% | 627 MB | 11.3 / 20 | 0 |
| 40 | 2,663 | 43.1 | 747 ms | 2,088 ms | 3,532 ms | 0% | 0 | 10.1% | 624 MB | 12.1 / 17 | 0 |
| 80 | 2,873 | 45.5 | 1,512 ms | 4,006 ms | 4,734 ms | 0% | 0 | 10.9% | 610 MB | 13.4 / 21 | 0 |

### p95 by endpoint

| Endpoint | 5 VUs | 20 VUs | 40 VUs | 80 VUs |
|---|---|---|---|---|
| Card check by UUID | 194 ms | 905 ms | 1,848 ms | 4,036 ms |
| Partner credential check | 236 ms | 908 ms | 1,948 ms | 3,745 ms |
| Employee search | 413 ms | 1,235 ms | 2,173 ms | 4,089 ms |
| Profile | 213 ms | 943 ms | 1,919 ms | 3,787 ms |
| Organization tree | 208 ms | 830 ms | 1,813 ms | 4,000 ms |
| Dashboard | 716 ms | 1,479 ms | 2,673 ms | 4,385 ms |
| Sign-in | 497 ms | 1,316 ms | 2,312 ms | 4,077 ms |

### Reading

- **Saturation begins at about 42–45 req/s,** between 20 and 40 virtual
  users. Throughput stays flat from there and latency grows with the queue.
- **No cliff:** 0 errors, no 5xx, no throttling with one address per user,
  no database connection exhaustion (at most 21), stable memory and no queue
  build-up.
- **The ceiling is the rig, not the database.**
  - The 16 single-threaded nodes are the concurrency limit. On average 9–11
    of them were running a query at once.
  - Time to first byte is the whole latency: TLS handshake p95 5.5 ms,
    connect 1.3 ms.
  - PHP used only 11% of the machine. PostgreSQL's CPU could not be read:
    the service runs as another user.
- **Slowest request:** the dashboard (operational metrics on 220,000
  employees). Serially it takes about 2.6 s per request on this rig (slow
  requests are logged at 1.5–2.3 s).
- **Card checks are latency-sensitive.** Unloaded, p50 is 94 ms and p95 194 ms
  through TLS and a `php -S` node. Under saturation they queue behind
  everything else. If check traffic must keep low latency at peak, give it
  dedicated FPM capacity or a priority pool (decision for the hosting owner).
- **Found during the load work, then fixed in the rig, not the app:**
  - k6 clears cookies between iterations;
  - all virtual users looked like one address;
  - the rehearsal import's credentials had been made under other keys.
    That last one became an operational rule: run the import on the
    production server (deployment runbook).

### Concurrency integrity (staging database, 16 processes released at the same instant)

`core-platform/loadtest/concurrency/race.php`:

| Race | Result | Invariant |
|---|---|---|
| 16 approvals of 16 verified drafts into one 1-seat position | 1 approved, 15 "no free seat" | **1** seat held (allowed 1) |
| 16 card requests for the same eligible employee | 1 accepted, 15 "already has a card request waiting" | **1** open request (allowed 1) |
| 16 registrations, each into its own position | 16 created | employee numbers unique (0 duplicates in the table) |
| 16 drafts into the same 1-seat position | 16 drafts created (first run) | 0 seats held: drafts hold no seat by design, and approval is the binding check (above). A second run was refused 16 times as possible duplicates of the first: the duplicate check works |

**NOT EXECUTABLE HERE:** a load test on hardware close to production with
PHP-FPM, against business-approved concurrency targets.

## 10. Monitoring and alerting

- **Built:** `/health/live`, `/health/ready`, and `/health/monitor` with a
  token, 19 metrics and the release. All are tested
  (`CoreGoLiveOperationsTest`) and were used by the staging smoke test.
- **Alert rules, owners and channels:** written in
  [core-monitoring-and-alerts.md](../../runbook/core-monitoring-and-alerts.md).
- **NOT EXECUTABLE HERE:** connecting the organization's monitoring tool and
  alert channel, and proving each alert fires. No alert is active, and none
  is claimed to be.

## 11. Queue and scheduler

| Item | Result |
|---|---|
| Scheduler | `schedule:work` running. The every-minute and 5-minute tasks ran (deliver-webhooks, audit-seal, audit-ship-heads, break-glass-expire, notify-expiring at 22:25:01–03); data-quality-scan at 22:20:04. The daily tasks (expire-cards 00:15, audit-verify 02:30, prune-* 03:15/03:30 UTC) had not come due during the rehearsal |
| Overlap protection | every scheduled task uses `withoutOverlapping()->onOneServer()` (tested) |
| Workers | **Found:** the workers served `default` only. **45 notification jobs waited** while System Health said ok. Fixed: `--queue=notifications,default` (manifest, runbook, rig), and System Health warns when a job waits over 15 minutes (15c095aa). After the fix: 0 waiting, 0 failed, 118 in-app notifications delivered |
| Expiry jobs | card expiry is scheduled daily; credential expiry is checked at verification; break-glass expiry runs every minute (ran) |
| Webhook retry, data-quality, notifications, exports, imports | queued or scheduled; the suite covers each (`CoreOperationsTest`, `CoreOversightTest`, `CoreBusinessImportTest`, `CoreSecurityHardeningTest`) |

## 12–14. UAT, penetration test, Amharic

- Business UAT: **not started**. Package: [core-uat-package.md](core-uat-package.md);
  the sign-off record [core-uat-signoff.csv](core-uat-signoff.csv) is blank.
- Penetration test: **not done**. Pack: [core-pentest-pack.md](core-pentest-pack.md).
  The internal checks `CorePrePenTest` (8 checks) pass; they are not a
  penetration test.
- Amharic: **not reviewed**. Review list:
  [core-amharic-review.csv](core-amharic-review.csv), 2,921 strings, 0
  missing.

## 15b. D-12 decided: the settings work is in the candidate (2026-10-26)

The project owner decided to include the settings work. The release branch was fast-forwarded to 69e0fa03 (the same patch as 064c5e4b, rebased on 1aabf8e5). A review of it found two problems, both fixed (6bb346ee):

- an SVG favicon could carry script from `/storage`;
- releases never linked the public disk.

One problem in the configuration review was also fixed (91c3d851): it ignored the mailer set on the settings page.

**Proposed release_code_commit: 91c3d851.** Locally: SQLite 238/238, PostgreSQL 238/238, MariaDB 236 + 2 skipped; Pint, audits, typecheck, build and drift checks clean. **None of the staging evidence above applies to it.** It is rehearsed again per [core-rc1-rehearsal-plan.md](core-rc1-rehearsal-plan.md).

## 15c. rc1 stage B: workstation pre-flight of 91c3d851 (2026-10-26)

Workstation evidence only (the same rig as §1–§11); it is not certification and does not replace stage C.

| Step | Result |
|---|---|
| B1 build | artifact 7.7 MB |
| B2 deploy over 15c095aa | 36 s; config audit passed; `public/storage` linked; `/health/monitor` reports 91c3d851 |
| B3 Golden Path over HTTPS | passed, 22.5 s |
| B4 settings smoke (`deploy/staging/settings-smoke.php`) | 12/12: viewer refused (GET and PATCH); General saved with a raster logo, served from `/storage`; scripted SVG favicon refused; mail password encrypted, never in the page or the audit trail; test e-mail sent through `log` and audited |
| B5 CSP | 15 screens plus `/core/settings` and `/system-settings`: 0 real violations. The only reports were the logo URL built from `APP_URL` (https://localhost:8443) while the browser used http://localhost:8601, a rig artifact |
| B6 backup and restore | backup 19 files including the public disk; drill passed in 77 s: 317 sealed audit rows and 9 off-server seal heads match, the mail password decrypts, the logo is restored and served |
| B7 rollback to 15c095aa | 24 s; schema matches (rc1 adds no migrations); Golden Path passed (25.3 s); the old release reads the settings rows; `/system-settings` and `/core/settings` return 200 |
| B8 fix forward | 19 s; Golden Path (29.9 s) and settings smoke pass |
| B9 load, 20 VUs, 60 s, 220k employees, alternating releases | 15c095aa 31.1 / 39.3 req/s (p95 1,561 / 1,173 ms); 91c3d851 40.8 / 40.8 req/s (p95 1,139 / 1,101 ms); 0 errors. No regression; the first rc1 run (29.5 req/s) was workstation noise, as the alternating runs show |

**Found and fixed in stage B (tooling, not application code):** the backup covered only the private disk, so the Settings › General images were not restorable. `FILES_ROOT` is now `storage/app` (both disks), the restore drill restores and counts that tree and runs `storage:link`, and `restore-verify.php` checks the mail password and the logo.

**Not covered:** the sequential benchmark shows the sign-in page slower on rc1 (median 163 vs 84 ms on one node), likely the settings props on web pages; it did not show in the load mix. Recorded for stage C.

## 15d. rc2: the card template designer in the candidate; stages A and B (2026-10-26)

The project owner took the card template designer (861bc5e3, rebased as 62abc843) into the candidate. Stage B on it found five defects; the fixes are db705422 and 2016bba4, and **the proposed release_code_commit is 2016bba4**. Workstation evidence only; not certification.

**Stage A on 2016bba4:** SQLite 245/245, PostgreSQL 245/245, MariaDB 243 + 2 skipped; Pint, Composer and npm audits, typecheck and build (in the artifact), sync drift zero. (62abc843 before the fixes: 243/243 on all three.) A2 and A3 remain WAITING as in §15b.

| Step | Result |
|---|---|
| B1 build | 62abc843, db705422 and 2016bba4 built (7.7 MB each) |
| B2 deploy | 62abc843 over 91c3d851: the first attempt was refused by the configuration review ("scheduler: last task 24 min ago") because the rig had been stopped after rc1 — the gate working; the migrations (two additive, `id_card_templates` and its grants) had already run, and the previous release kept serving. Deployed once the scheduler was back (22 s). db705422 in 57 s, 2016bba4 in 55 s |
| B3 Golden Path | passed on each build (24.0, 23.5, 23.5 s) |
| B4 settings and designer smokes | settings 12/12. `deploy/staging/card-designer-smoke.php` 14/14: a viewer sees templates and cannot save; a JPG disguised as PNG is refused; the artwork is on the local disk, served to a template manager and refused without a session; the saved template is the active print version (mirrored) and audited; cards, requests and print batches still answer |
| B5 CSP | designer, card, card request and print batch pages: 0 real violations (the known logo-URL rig artifact only). **The card page answered 500** (finding 1) |
| B6 backup and restore | backup 32 files (the template artwork included); drill passed in 62 s: snapshot identical, 398 sealed rows and 10 heads match with the 11 newer seals reported, MFA sign-in, documents, credentials, the mail password, the logo and the template artwork (2 files) restored. Two drill runs before that failed on findings 3 and 5 |
| B7 rollback to 91c3d851 | 12–32 s; the two designer migrations are listed as additive and left in place; the Golden Path passes on the older release (20.8, 22 s); settings pages 200; the designer route is absent there as expected |
| B8 fix forward | 11–13 s, with the designer smoke passing right after the switch (the viewer keeps `id_card_templates.view`). One attempt was refused ("scheduler never ran", finding 4) |
| B9 load, 20 VUs, 60 s, 220k employees, alternating | 91c3d851: 40.0 and 41.4 req/s (p95 1,210 / 1,104 ms); 2016bba4: 40.5 and 41.2 req/s (p95 1,199 / 1,129 ms); 0 errors. No regression. A first pair of candidate runs showed 9–10 % errors, all on the dashboard: the load database lacked the designer's two tables because the rig's migrate command had run through the release's cached configuration against the staging database. Rig mistake, not the application; the dashboard does read `id_card_templates`, which the deployment's migrations create |

**Found and fixed:**

1. **The card page failed once a card had been quality-checked** (`/id-cards/{card}` 500): `qc_at` was not cast to a date (P3 code, older than the designer; the rc1 CSP pass opened a card without a check). Fixed in db705422 with a test.
2. **Every processed job raised a TypeError in the worker**: the job-duration listener expected a string job id and the database queue numbers its jobs. Present 116 times in the rc1 rehearsal log and missed then. Fixed in db705422 with a database-queue test; on the rig, ten notification jobs then ran clean.
3. **The restore drill's audit step failed on a live trail**: the trail was sealed and shipped on after the backup, so the restored copy ended before the latest off-server head. `core:audit-verify --restored` reports newer seals instead of failing; the drill uses it (db705422).
4. **`rollback.sh` emptied the application cache** (`optimize:clear` runs `cache:clear`): the scheduler heartbeat vanished and the next deployment's review refused the release as "never ran"; the roles were re-cached by the older release under its catalog, so after the fix-forward the viewers lacked `id_card_templates.view` for ten minutes. Fixed twice over: the rollback clears only the compiled caches, and the roles cache is keyed by the catalog (2016bba4, with a test).
5. **The drill never stopped its own server** (started in a subshell), so later drills were verified against a copy left by an earlier one. The server is exec'd and a busy port refuses the drill (2016bba4).

**Not a defect, noted:** the designer is read-only for every role but `core_admin` (the EUISIS split, mirrored by the grant migration); an officer sees greyed controls with no notice. The scheduler heartbeat must be fresh for a deployment to pass its review, so a rollback followed by a deployment within the same minute may need one retry.

## 15e. rc3: the printed card from the designed template; stages A and B (2026-10-26)

The project owner took the printed-card change (83daed6b) into the candidate; it was cherry-picked onto the release branch as 8d742364 (the peer branch then fast-forwarded to the same commit, so the trees are identical). Stage B found two gaps, fixed in 6bfacc4b. **The proposed release_code_commit is 6bfacc4b.** Workstation evidence only; not certification.

**Stage A on 6bfacc4b:** SQLite 248/248, PostgreSQL 248/248, MariaDB 246 + 2 skipped; Pint, Composer and npm audits, typecheck and build (in the artifact), sync drift zero with the widened CI path list. The change adds `chillerlan/php-qrcode` ^6.0 (lock updated) and no migrations. A2 and A3 remain WAITING as in §15b.

| Step | Result |
|---|---|
| B1 build | 8d742364 and 6bfacc4b built (8.0 MB). One build of 6bfacc4b ran while the test suites were running and `tar` saw a file change under it; the script stopped before writing the checksum and the deployment refused the artifact. Rebuilt alone: checksum verified |
| B2 deploy | 8d742364 over 2016bba4 in 51 s; 6bfacc4b in 52 s; configuration review passed both times |
| B3 Golden Path | 37.1 s on 8d742364 once, then 20.5 s; 26.6 s on 6bfacc4b: the first figure was workstation noise, not the renderer |
| B4 smokes | settings 12/12; designer 14/14 on every build. The printed-card routes as an administrator: card page and preview page 200; front face SVG 200 with the designed artwork embedded (788 ms), back face 200 (385 ms); PNG export 503 (no Imagick on the workstation); print sheet 403 on a finished batch (by design: draft batches only); SVG refused without a session |
| B5 CSP | preview page and card page in the browser: 0 real violations (the known logo-URL rig artifact only). **The SVG faces were served without the sealed policy the version preview carries** (finding 1) |
| B6 backup and restore | backup 78 files in 4 s (every smoke run leaves template artwork); drill passed in 153 s while the load runs shared the workstation: snapshot identical, sealed rows and off-server heads match, MFA sign-in, documents, credentials, the mail password, the logo and the template artwork (2 files) restored |
| B7 rollback to 2016bba4 | 20–39 s; the Golden Path passes on the older release (24, 31 s); the designer smoke passes there |
| B8 fix forward | 13 s; the designer smoke passes right after the switch |
| B9 load, 20 VUs, 60 s, 220k employees, alternating | 2016bba4: 45.8 and 41.2 req/s (p95 1,004 / 1,129 ms); 6bfacc4b: 43.8 and 33.2 req/s (p95 1,046 / 1,450 ms); 0 errors. Three of the four runs lie within 41–46 req/s; the one low run is the kind of spread the workstation showed in rc1 (29.5 then 40.8 on one build), and the renderer routes are not in the load mix, so no regression is concluded — the low run is recorded for stage C, where the real hardware decides. A first pair of runs failed 70 % of requests on both releases: the rig's own user tokens had been minted for six hours and had expired; re-minted (`load-tokens.php`), not an application matter |

**Found and fixed (6bfacc4b):**

1. **The per-card SVG faces had no document policy**: an SVG opened on its own could have run script or loaded remote content. They now send `Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'` and `X-Content-Type-Options: nosniff`, as the version preview already did; asserted in `CoreIdCardPrintRenderTest`.
2. **Nothing told an operator that the PNG card export needs Imagick** (it answers 503 without the extension). The configuration review now carries a `PHP_IMAGICK` row: a warning, never a blocker, because the SVG preview and the print sheet do not need it. Production application servers need `php-imagick` before the PNG export is offered.

**Noted, not a defect:** the print sheet is available for draft batches only; the preview page itself does no server rendering.

## 15a. Release source of truth (2026-10-26, after `git fetch`; superseded for the code commit by §15b)

| Ref | Commit | What it is |
|---|---|---|
| `origin/main` | e0bc149a | unchanged since the P0/P1 gate. An ancestor of every commit below: no divergence |
| **release_code_commit (proposed)** | **15c095aa** | the last commit that changes `core-platform/backend`, `frontend` or `deploy`. Rehearsed: staging deploy, rollback, restore drill, CSP pass, load |
| **release_docs_commit (proposed)** | the latest docs/CI commit on top of 15c095aa (37449238, then the decision-register and CI commit) | 37449238 changes only `docs/` and `core-platform/loadtest/` (test tooling, not in the artifact). 0 files under `core-platform/backend`, `frontend` or `deploy` |
| a202bb31, rebased as **064c5e4b** (local branch `feat/golden-path-stages-apply`) | not in 15c095aa | the settings work ("the Core settings are the EUISIS settings"): 16 files, about 2,100 lines in settings, services, controllers, the provider, routes, the screen sync and CI; no migrations. **Excluded** from the rehearsed candidate. Including it means a new candidate and a full rehearsal again (D-12) |

The Core CI (`.github/workflows/core-platform.yml`) runs only on pushes and
PRs to `main`. At 15c095aa it had no PostgreSQL job: the PostgreSQL 18 job
`core-backend-postgres` was added on top. **No tag exists, and none is created
until D-12 is decided by a person.**

## 15. Release source

- `feat/core-p0-p1-gate` is 17+ commits ahead of `origin/main` and 0
  behind. `origin/main` (e0bc149a) is its ancestor, so there is no
  divergence and a fast-forward is possible.
- Nothing is pushed and there is no `core-*` tag. The peer's settings work
  (a202bb31, on `feat/golden-path-stages-apply`) is not in this candidate.
- **Release commit, tag (`core-v1.0.0-rc1`), push and CI need the owner's
  agreement (D-12).**
