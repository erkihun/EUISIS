# Core Golden Path: final go-live checklist

Every box stays unticked until its **external evidence** is attached: a
command output, a report, a ticket or a signature from the responsible team.
The workstation rehearsal (core-go-live-evidence.md) proves the procedures and
tooling. It does **not** tick any box below. Status as of 2026-10-26.

| # | Item | Status | What already exists | Evidence still required | Owner |
|---|---|---|---|---|---|
| 1 | [ ] Owners assigned | WAITING FOR EXTERNAL EVIDENCE | owner matrix with every row BLOCKED | names, backups and deadlines filled in by the organization | TBD |
| 2 | [ ] RPO/RTO approved | WAITING FOR EXTERNAL EVIDENCE | D-01/D-02 in the decision register; restore timings measured | decision maker, date, reason | TBD |
| 3 | [ ] Release commit approved | DECIDED (D-12, 2026-10-26); commit to be confirmed after CI | settings work included; proposed code commit 91c3d851 (evidence §15b) | CI green on 91c3d851, or the latest fix commit, then the tag | TBD |
| 4 | [ ] CI green | WAITING FOR EXTERNAL EVIDENCE | locally on 91c3d851: SQLite 238/238, PostgreSQL 238/238, MariaDB 236 + 2 skipped, typecheck, build, audits, drift clean; CI workflow now includes PostgreSQL 18 | PR to `main`, every job green (backend, PostgreSQL, frontend) | TBD |
| 5 | [ ] Real staging passed | WAITING FOR EXTERNAL EVIDENCE | `deploy.sh`, `golden-path-smoke.php`, rehearsal runbook | deploy log, smoke and Golden Path output on Linux/Nginx/PHP-FPM | TBD |
| 6 | [ ] Config audit passed | WAITING FOR EXTERNAL EVIDENCE | 26/26 on the workstation staging | `core:config-audit` output from real staging and production | TBD |
| 7 | [ ] Production keys provisioned | WAITING FOR EXTERNAL EVIDENCE | key list in the release manifest; independence check in the audit | secret-store entries (names only), KEY_INDEPENDENCE pass on the server | TBD |
| 8 | [ ] Backup operational | WAITING FOR EXTERNAL EVIDENCE | `core-backup.sh`, pgBackRest/cron/env templates | first scheduled full backup, status file healthy on System Health | TBD |
| 9 | [ ] Off-site repository operational | WAITING FOR EXTERNAL EVIDENCE | pgBackRest repository 2 template | `pgbackrest info` showing repository 2 with a backup | TBD |
| 10 | [ ] WAL archiving operational | WAITING FOR EXTERNAL EVIDENCE | postgresql.conf settings in the template | `pg_stat_archiver` showing archiving without failures | TBD |
| 11 | [ ] Restore drill passed | WAITING FOR EXTERNAL EVIDENCE | `core-restore-drill.sh` + `restore-verify.php` (passed on the workstation) | drill report from the real repository on an isolated host | TBD |
| 12 | [ ] Sanitized production migration rehearsal passed | WAITING FOR EXTERNAL EVIDENCE | `reconcile.php` (passed at 28 and 220,000 employees) | reconcile.json from the approved sanitized copy, 0 unexplained differences | TBD |
| 13 | [ ] Load test passed agreed targets | WAITING FOR EXTERNAL EVIDENCE | `core-golden-path.js`; workstation capacity measured | D-03 targets plus a k6 summary from production-like staging | TBD |
| 14 | [ ] Monitoring connected | WAITING FOR EXTERNAL EVIDENCE | `/health/live`, `/health/ready`, `/health/monitor` | monitor screenshots or exports polling them | TBD |
| 15 | [ ] Critical alerts tested | WAITING FOR EXTERNAL EVIDENCE | 16 rules with runbooks | each critical alert received in the real channel | TBD |
| 16 | [ ] Business UAT signed | WAITING FOR EXTERNAL EVIDENCE | 35 scenarios (32 + 3 settings scenarios after D-12); blank sign-off record | completed `core-uat-signoff.csv` with approval references | TBD |
| 17 | [ ] Penetration test passed or remediated | WAITING FOR EXTERNAL EVIDENCE | pen-test pack; internal pre-pen checks pass | the testers' report; no open Critical, no open High on auth, data or integrity | TBD |
| 18 | [ ] Rollback rehearsal passed | WAITING FOR EXTERNAL EVIDENCE | `rollback.sh` (17 s on the workstation) | rollback log from real staging, from the tagged release | TBD |
| 19 | [ ] Release tag created | WAITING FOR EXTERNAL EVIDENCE | proposed `core-v1.0.0-rc1` | tag on the agreed commit after CI is green; artifact checksums | TBD |
