# Core Golden Path: owner matrix and risk register

No real names appear here until the organization assigns them. Every **TBD**
is a decision for the organization, not for the project team. **An unowned
P0/P1 item stays a go-live blocker.**

## Owner matrix

| Area | Owner | Backup owner | Escalation |
|---|---|---|---|
| Application (Core release, deployment, rollback) | TBD | TBD | TBD |
| Database (PostgreSQL 18, migrations, capacity) | TBD | TBD | TBD |
| Backups and restore drills | TBD | TBD | TBD |
| Backup and secret key escrow (GPG private key, pgBackRest cipher passwords) | TBD (two holders) | TBD | TBD |
| Monitoring and alerting (tool, channel, on-call) | TBD | TBD | TBD |
| Security (pen test, incidents, key rotation, break-glass approvals) | TBD | TBD | TBD |
| Network / SSL (TLS certificates, load balancer, DNS, firewall) | TBD | TBD | TBD |
| Secret store (APP_KEY, CORE_CREDENTIAL_KEYS, CORE_AUDIT_SEAL_KEY, NATIONAL_ID_HASH_KEY, monitor token) | TBD | TBD | TBD |
| Business UAT and sign-off | TBD | TBD | TBD |
| Amharic language review | TBD (native reviewer) | TBD | TBD |
| Data migration (EUISIS → Core cut-over, reconciliation sign-off) | TBD | TBD | TBD |

## Decisions the organization must take

| ID | Decision | Needed for | Status |
|---|---|---|---|
| D-01 | RPO and RTO | backup cadence, PITR, restore target | **BUSINESS DECISION REQUIRED** |
| D-02 | Concurrency targets: users, card checks per second at peak, partner API rate | load test acceptance | **BUSINESS DECISION REQUIRED**. Measured capacity is in the evidence document; no pass threshold was invented |
| D-03 | E-mail required at go-live? (mail relay, sender domain) | notifications | **OPEN**: e-mail notifications are off by default; in-app notifications work |
| D-04 | SMS at go-live? | notifications | **OPEN**: no SMS gateway exists. Proposed: "not applicable for the initial release", pending approval |
| D-05 | Server-generated PDFs at go-live? | reports, card sheets | **OPEN**: proposed "not required for the initial release" (exports are CSV/XLSX; the card print sheet and report previews are printable pages), pending approval |
| D-06 | Display time zone | screens, notifications | **PROPOSED**: store UTC, show Africa/Addis_Ababa (ADR 0012 §8), pending approval |
| D-07 | Production database engine | migrations, backups | **PROPOSED**: PostgreSQL 18, as rehearsed. MariaDB also passes the suite, but its backup and PITR design does not exist |
| D-08 | Staging environment (hosting, size) close to production | every rehearsal on real infrastructure | **OPEN** |
| D-09 | Monitoring tool and alert channel | external monitoring | **OPEN** |
| D-10 | CSP enforcement date | XSS defence in depth | **OPEN** (R-04) |
| D-11 | Authoritative release branch: `feat/core-p0-p1-gate` vs `origin/main` | release tag | **OPEN** (evidence §15) |
| D-12 | NATIONAL_ID_HASH_KEY: one explicit key shared by EUISIS and the Core (EUISIS currently derives its key from APP_KEY) | duplicate detection after import | **OPEN**: EUISIS must set the key and run its rehash before the import |

## Risk register

Severity: P1 = must be fixed or formally accepted before go-live; P2 =
acceptable with a dated plan; P3 = minor. `accepted_by` stays empty until a
named owner accepts the risk in writing. **Nothing below is accepted yet.**

| ID | Risk | Severity | Impact | Mitigation | Owner | Deadline | accepted_by |
|---|---|---|---|---|---|---|---|
| R-01 | Staging rehearsals ran on a developer workstation (Windows, `php -S` behind a Node TLS front), not Linux/Nginx/PHP-FPM | P1 | infrastructure-specific faults are not caught (FPM pools, Nginx limits, file permissions, systemd units) | repeat the rehearsal (runbooks unchanged) on real staging | TBD | TBD | — |
| R-02 | No load test on production-like hardware or against agreed targets | P1 | capacity at peak unknown; the workstation rig saturates first | run `core-golden-path.js` on staging with the D-02 targets | TBD | TBD | — |
| R-03 | Backup off-host copy, pgBackRest physical backups and PITR not exercised (only `pg_dump` to another directory on the same disk) | P1 | the RPO in minutes and loss of the host are not covered | provision pgBackRest repositories 1 and 2; drill from repository 2 | TBD | TBD | — |
| R-04 | CSP is report-only | P2 | a future XSS bug is not contained by the browser | the first staging pass found 1 violation (the inline theme script), now nonced. The second pass found 0 violations on 15 screens (evidence §7a). Set `CORE_CSP_ENFORCE=true` after the same pass on real staging, including the screens not visited (forms, dialogs, exports) | TBD | TBD | — |
| R-05 | No independent penetration test | P1 | unknown vulnerabilities | engage the testers with the pen-test pack | TBD | TBD | — |
| R-06 | No business UAT or sign-off | P1 | the workflow may not match practice | run the UAT package; get the sign-off record | TBD | TBD | — |
| R-07 | The audit seal key cannot be rotated without losing verification of older seals (one key, not versioned) | P2 | after a forced rotation, older seals verify only with the old key kept aside | rotate only on compromise; add key-versioned seals in a later release | TBD | TBD | — |
| R-08 | EUISIS derives NATIONAL_ID_HASH_KEY from its APP_KEY | P2 | duplicate checks against imported hashes fail if the keys differ | D-12 before the import | TBD | TBD | — |
| R-09 | The 220,000-employee import rehearsal used synthetic EUISIS data (`aaemployeedb_scale`); the real-data rehearsal used the local development copy (28 employees) | P1 | real production data quirks (encodings, orphans, duplicates) not yet seen | run `reconcile.php` on a sanitized production copy, in an approved environment | TBD | TBD | — |
| R-10 | Positions import without job grade or occupation masters, and placements with type "unspecified" (reconciliation "review" items) | P3 | reports by grade are incomplete until the masters are filled | the data-quality owner assigns masters after import | TBD | TBD | — |
| R-11 | Amharic wording not reviewed by a native speaker | P2 | unclear or wrong terms on screens | review list `core-amharic-review.csv` (2,921 strings) | TBD | TBD | — |
| R-12 | The Golden Path migrations hold write locks for up to about 2 minutes if run on a populated database | P3 | not at go-live (empty schema, then import); only if re-run on live data | maintenance window rule (deployment runbook) | TBD | TBD | — |
| R-13 | Branch divergence and an unpushed local branch | P1 | an ambiguous release source | D-11; push, CI and tag only after agreement | TBD | TBD | — |
| R-14 | The PgBouncer pooler is not tested | P3 | only if a pooler is introduced | rehearse on staging first | TBD | TBD | — |
| R-15 | Screens, exports and e-mail show times of day in UTC (EUISIS and the Core alike), 3 hours behind Addis Ababa | P2 | audit, approval and sign-in times read early; misread deadlines | decide D-06; one change in the shared formatter and `CoreDates`; until then, train users that the times shown are UTC | TBD | TBD | — |
