# Core Golden Path: owner matrix and risk register

No real names appear here until the organization assigns them. Every **TBD**
is a decision for the organization, not for the project team. **An unowned
P0/P1 item stays a go-live blocker.**

## Owner matrix

Status is **BLOCKED** until a named person accepts the area. The project team
does not fill these cells. The organization does.

| Area | Primary owner | Backup owner | Deadline | Status |
|---|---|---|---|---|
| Application (Core release, deployment, rollback) | TBD | TBD | TBD | BLOCKED |
| Database (PostgreSQL 18, migrations, capacity) | TBD | TBD | TBD | BLOCKED |
| Backup (pgBackRest, WAL, off-site repository, restore drills, key escrow) | TBD | TBD | TBD | BLOCKED |
| Monitoring (tool, alert channel, on-call) | TBD | TBD | TBD | BLOCKED |
| Infrastructure (Linux staging and production, PHP-FPM, Redis, storage) | TBD | TBD | TBD | BLOCKED |
| Security (pen test, secret store, key rotation, incidents, break-glass approvals) | TBD | TBD | TBD | BLOCKED |
| Business UAT (testers, 32 scenarios, sign-off) | TBD | TBD | TBD | BLOCKED |
| Network / TLS (certificate, load balancer, DNS, firewall) | TBD | TBD | TBD | BLOCKED |
| Release management (release commit, PR, CI, tag) | TBD | TBD | TBD | BLOCKED |
| Data migration (sanitized copy, cut-over import, reconciliation sign-off) | TBD | TBD | TBD | BLOCKED |
| Amharic language review | TBD (native reviewer) | TBD | TBD | BLOCKED |

## Decision register

Nothing here is decided. Each row records a **proposal** (where the project
has one) and stays **WAITING FOR DECISION** until the decision maker,
date and reason are filled in by the organization.

| ID | Decision | Proposal (not a decision) | Decision | Decision maker | Date | Reason |
|---|---|---|---|---|---|---|
| D-01 | RPO: data loss allowed | none: business call. PITR with WAL can reach minutes; a nightly dump alone cannot | WAITING FOR DECISION | TBD | — | — |
| D-02 | RTO: time to restore service | none: business call. Logical restore measured at about 11 min for 3 million employees (local SSD) | WAITING FOR DECISION | TBD | — | — |
| D-03 | Production load targets: expected and peak concurrent users, card-check p95, search p95, API error rate, requests/s | none. The workstation's ~43 req/s saturation is **not** a requirement | WAITING FOR DECISION | TBD | — | — |
| D-04 | E-mail required for V1? | no (in-app notifications work; e-mail is off by default) | WAITING FOR DECISION | TBD | — | — |
| D-05 | SMS required for V1? | no (no gateway exists; recorded as skipped, never pretended) | WAITING FOR DECISION | TBD | — | — |
| D-06 | Server-generated PDF required for V1? | no (exports are CSV/XLSX; print sheets and report previews are printable pages) | WAITING FOR DECISION | TBD | — | — |
| D-07 | Time display policy | store, API and scheduler in UTC; screens in Africa/Addis_Ababa; one change in EUISIS and Core together, with regression tests (ADR 0012 §8, R-15) | WAITING FOR DECISION | TBD | — | — |
| D-08 | Production database engine | PostgreSQL 18, as rehearsed and certified in CI | WAITING FOR DECISION | TBD | — | — |
| D-09 | Staging infrastructure (hosting, size, close to production) | Linux, Nginx, PHP-FPM 8.4, PostgreSQL 18, Redis available, private storage, real certificate | WAITING FOR DECISION | TBD | — | — |
| D-10 | Monitoring platform and alert channel | the organization's existing tool | WAITING FOR DECISION | TBD | — | — |
| D-11 | CSP enforcement timing | enforce (`CORE_CSP_ENFORCE=true`) after a clean browser pass on real staging, incl. forms and dialogs not yet visited | WAITING FOR DECISION | TBD | — | — |
| D-12 | Release commit | (was: 15c095aa without the settings work) | **DECIDED: include the settings work** (a202bb31, rebased 064c5e4b/69e0fa03) in the release candidate, and rehearse the new candidate fully. Proposed code commit after the review fixes: **91c3d851** ([plan](core-rc1-rehearsal-plan.md)) | Erkihun (project owner), in the Claude Code session | 2026-10-26 | not stated with the decision |
| D-13 | Shared national-ID hash key | one explicit `NATIONAL_ID_HASH_KEY` set in EUISIS (then its rehash) and the same value in the Core, before the import | WAITING FOR DECISION | TBD | — | — |

## Risk register

Severity: P1 = must be fixed or formally accepted before go-live; P2 =
acceptable with a dated plan; P3 = minor. `accepted_by` stays empty until a
named owner accepts the risk in writing. **Nothing below is accepted yet.**

| ID | Risk | Severity | Impact | Mitigation | Owner | Deadline | accepted_by |
|---|---|---|---|---|---|---|---|
| R-01 | Staging rehearsals ran on a developer workstation (Windows, `php -S` behind a Node TLS front), not Linux/Nginx/PHP-FPM | P1 | infrastructure-specific faults are not caught (FPM pools, Nginx limits, file permissions, systemd units) | repeat the rehearsal (runbooks unchanged) on real staging | TBD | TBD | — |
| R-02 | No load test on production-like hardware or against agreed targets | P1 | capacity at peak unknown; the workstation rig saturates first | run `core-golden-path.js` on staging with the D-03 targets | TBD | TBD | — |
| R-03 | Backup off-host copy, pgBackRest physical backups and PITR not exercised (only `pg_dump` to another directory on the same disk) | P1 | the RPO in minutes and loss of the host are not covered | provision pgBackRest repositories 1 and 2; drill from repository 2 | TBD | TBD | — |
| R-04 | CSP is report-only | P2 | a future XSS bug is not contained by the browser | the first staging pass found 1 violation (the inline theme script), now nonced. The second pass found 0 violations on 15 screens (evidence §7a). Set `CORE_CSP_ENFORCE=true` after the same pass on real staging, including the screens not visited (forms, dialogs, exports) | TBD | TBD | — |
| R-05 | No independent penetration test | P1 | unknown vulnerabilities | engage the testers with the pen-test pack | TBD | TBD | — |
| R-06 | No business UAT or sign-off | P1 | the workflow may not match practice | run the UAT package; get the sign-off record | TBD | TBD | — |
| R-07 | The audit seal key cannot be rotated without losing verification of older seals (one key, not versioned) | P2 | after a forced rotation, older seals verify only with the old key kept aside | rotate only on compromise; add key-versioned seals in a later release | TBD | TBD | — |
| R-08 | EUISIS derives NATIONAL_ID_HASH_KEY from its APP_KEY | P2 | duplicate checks against imported hashes fail if the keys differ | D-13 before the import | TBD | TBD | — |
| R-09 | The 220,000-employee import rehearsal used synthetic EUISIS data (`aaemployeedb_scale`); the real-data rehearsal used the local development copy (28 employees) | P1 | real production data quirks (encodings, orphans, duplicates) not yet seen | run `reconcile.php` on a sanitized production copy, in an approved environment | TBD | TBD | — |
| R-10 | Positions import without job grade or occupation masters, and placements with type "unspecified" (reconciliation "review" items) | P3 | reports by grade are incomplete until the masters are filled | the data-quality owner assigns masters after import | TBD | TBD | — |
| R-11 | Amharic wording not reviewed by a native speaker | P2 | unclear or wrong terms on screens | review list `core-amharic-review.csv` (2,921 strings) | TBD | TBD | — |
| R-12 | The Golden Path migrations hold write locks for up to about 2 minutes if run on a populated database | P3 | not at go-live (empty schema, then import); only if re-run on live data | maintenance window rule (deployment runbook) | TBD | TBD | — |
| R-13 | Release source decided (D-12) but not yet verified: `feat/core-p0-p1-gate` (now including the settings work, the card template designer and the printed card from it, candidate 6bfacc4b) is local only, 0 behind `origin/main`, and CI has not run on it | P1 | an unagreed or unverified commit could be released | D-12; then a PR to `main` (CI runs only on `main` pushes and PRs), CI green including the PostgreSQL job, then the tag | TBD | TBD | — |
| R-14 | The PgBouncer pooler is not tested | P3 | only if a pooler is introduced | rehearse on staging first | TBD | TBD | — |
| R-15 | Screens, exports and e-mail show times of day in UTC (EUISIS and the Core alike), 3 hours behind Addis Ababa | P2 | audit, approval and sign-in times read early; misread deadlines | decide D-07; one change in the shared formatter and `CoreDates`; until then, train users that the times shown are UTC | TBD | TBD | — |
