# Core platform — production readiness checklist

Status as of **2026-10-26**, after the go-live blocker closure (ADR 0012).
Every item is **Pass**, **Fail**, **Not Tested** or **Not Applicable**, with
its evidence. Nothing is marked Pass without a test, a command result or a
measurement. **Pass (rehearsed)** means it passed on the workstation staging
rehearsal, not on production infrastructure.

Evidence abbreviations:

- **S / P / M**: the backend suite on SQLite, PostgreSQL 18.6 and MariaDB 10.4.
- **UAT**: `CoreGoldenPathUatTest`.
- **E§n**: [core-go-live-evidence.md](../go-live/core/core-go-live-evidence.md), section n.
- **R-nn**: the [risk register](../go-live/core/core-owner-matrix-and-risks.md).

## Security

| Item | Result | Evidence |
|---|---|---|
| Organization scope fails closed; cross-organization UUIDs answer 404 | Pass | S, P (`CoreScopeFailClosedTest`, UAT negative) |
| Granular permissions and policies (permission + scope + state) | Pass | S, P (`CorePermissionsAndPoliciesTest`) |
| Two-step sign-in enforced; session id renewed at sign-in and after MFA | Pass | S (`CorePrePenTest`, `CoreOperationsTest`); E§6 restore drill |
| CSRF, XSS escaping, SQL in search, mass assignment, role escalation, upload content checks, X-Forwarded-For throttle spoofing | Pass (internal) | `CorePrePenTest`, 8 checks; **not** a penetration test |
| Trusted proxies honoured (HTTPS and client address behind TLS) | Pass (rehearsed) | `CoreGoLiveOperationsTest`; E§7: redirects stay https after 2e8e8042 |
| Separate, independent keys (credentials, seal, national ID, monitor) | Pass (rehearsed) | `core:config-audit` KEY_INDEPENDENCE; E§3 |
| Credential key rotation (add, rekey, retire) | Pass (rehearsed) | E§3: 4/4 credentials valid at each step |
| Audit seal key rotation | Fail (by design today) | not versioned (R-07); rotate only on compromise |
| Content security policy | See E§7a | nonce for the one inline script (2c8518fe); enforcement is a switch (`CORE_CSP_ENFORCE`) |
| Dependency advisories | Pass | `composer audit`: none; `npm audit --omit=dev`: 0 (2026-10-25) |
| Independent penetration test | Not Tested | pack ready: `core-pentest-pack.md` (R-05) |
| Production configuration (`core:config-audit`) on a production-like server | Pass (rehearsed) | E§2: 26/26 on staging with APP_ENV=production. Not yet on Linux (R-01) |

## Data integrity and migration

| Item | Result | Evidence |
|---|---|---|
| Database invariants | Pass | S, P, M |
| Every migration applies on SQLite, PostgreSQL and MariaDB; 9 roll back and re-apply on MariaDB | Pass | S, P, M; ADR 0012 §9 |
| Rollback and re-apply of the P3–P5 migrations on 300,000 employees | Pass | readiness review 2026-10-25 (`emscoredb_perf`) |
| Go-live sequence (empty schema, then import), reconciled | Pass | E§8: 0 rejected, 0 duplicates, 0 orphans on 28 and 220,000 employees |
| Imported cards keep verifying (legacy credentials) | Pass | `CoreImportTest`; E§8 (220,000 credentials); staging remediation E§8 |
| Import of a sanitized production copy | Not Tested | R-09 |

## Golden Path and UAT

| Item | Result | Evidence |
|---|---|---|
| Automated UAT, positive and 19 refusals | Pass | UAT (S, P, M) |
| The Golden Path over HTTPS on a deployed release | Pass (rehearsed) | E§7: 5 runs, 23.7–36.7 s, 36 checks each |
| UAT with business users | Not Tested | `core-uat-package.md` (R-06) |
| Business sign-off | Not Tested | `core-uat-signoff.csv` is blank |

## Deployment and rollback

| Item | Result | Evidence |
|---|---|---|
| Artifact build, deploy, configuration gate, smoke | Pass (rehearsed) | E§7 |
| A deployment the configuration review refuses leaves the previous release serving | Pass (rehearsed) | E§7: efcf13c1 and 982aa159 refused |
| Application rollback (code and assets; database left additive) | Pass (rehearsed) | E§7: 17 s; Golden Path passed on N after the rollback |
| Fix forward | Pass (rehearsed) | E§7: 49 s |
| On Linux/Nginx/PHP-FPM | Not Tested | R-01 |

## Backups

| Item | Result | Evidence |
|---|---|---|
| Backup run: encrypted, checksummed, copied, status file | Pass (rehearsed) | E§5 (pg_dump path; the off-host copy is on the same disk) |
| Restore drill on a real restored instance | Pass (rehearsed) | E§6: 120 s, fingerprints identical, MFA sign-in, documents, credentials |
| pgBackRest, WAL, PITR, off-site repository | Not Tested | R-03 |
| RPO / RTO | Not Tested | BUSINESS DECISION REQUIRED (D-01/D-02) |

## Queues and scheduler

| Item | Result | Evidence |
|---|---|---|
| Scheduler running, every task overlap-protected | Pass (rehearsed) | E§11; `CoreGoLiveOperationsTest` |
| Workers serve every queue (`notifications`, `default`) | Pass (rehearsed) | E§11: 45 waiting jobs found and processed after the fix (15c095aa) |
| A queue nobody serves shows as a warning | Pass | `CoreGoLiveOperationsTest` |

## Monitoring

| Item | Result | Evidence |
|---|---|---|
| Probes and monitor endpoint (19 metrics, no personal data) | Pass | `CoreGoLiveOperationsTest`; E§7 smoke |
| External monitoring and alerting connected; alerts proven to fire | Not Tested | D-10; `core-monitoring-and-alerts.md` |

## Performance

| Item | Result | Evidence |
|---|---|---|
| Single user at 300,000 employees | Pass | ADR 0011 §9 |
| Concurrency (workstation rig) | See E§9 | measured capacity, no invented threshold |
| Load on production-like hardware against agreed targets | Not Tested | R-02, D-03 |

## Localization

| Item | Result | Evidence |
|---|---|---|
| Keys complete, glossary | Pass | `npm run i18n:audit`: 0 blocking |
| Native Amharic review | Not Tested | `core-amharic-review.csv` (R-11) |
| Times shown in Addis Ababa time | Fail (today UTC) | R-15, D-07 |

## Notifications and reports

| Item | Result | Evidence |
|---|---|---|
| In-app notifications delivered by the workers | Pass (rehearsed) | E§11: 118 delivered |
| E-mail through a real mailer | Not Tested | D-04 |
| SMS | Not Applicable (pending approval) | D-05 |
| Server-generated PDF | Not Applicable (pending approval) | D-06 |
