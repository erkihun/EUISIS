# Core platform — production readiness checklist

Status as of 2026-10-25, after the P4/P5 gate (ADR 0011) and the production
certification pass that followed it. Every item is
**Pass**, **Fail**, **Not Tested** or **Not Applicable**, with its evidence.
Nothing is marked Pass without a test, a command result or a measurement. An
item that needs infrastructure, a person or a decision outside the code stays
Not Tested until that happens; update this file with the evidence when it does.

Evidence abbreviations: **S** = backend suite on SQLite (212 tests),
**P** = the same suite on PostgreSQL 18.6 (212 tests), **UAT** =
`tests/Feature/CoreGoldenPathUatTest.php`, **Perf** = the scale review in ADR
0011 §9.

## Security

| Item | Result | Evidence |
|---|---|---|
| Organization scope fails closed; cross-organization UUIDs answer 404 | Pass | S, P (`CoreScopeFailClosedTest`, UAT negative) |
| Granular permissions and policies (permission + scope + state) | Pass | S, P (`CorePermissionsAndPoliciesTest`) |
| Web Integration Center writes need `integrations.manage` | Pass | S, P (`CoreSecurityHardeningTest`) — fixed in P3 |
| Two-step sign-in enforced for privileged accounts | Pass in code | S, UAT (enforced → redirected). **Production must set `CORE_MFA_ENFORCE=true`** (`core:config-audit` fails otherwise) |
| Sessions end on role, scope, deactivation, password reset, MFA reset | Pass | S (`CoreOperationsTest`, existing account tests) |
| Sign-in throttled, failures and lockouts audited without the e-mail | Pass | S (`CoreOperationsTest`) |
| API per-client rate limits and scopes | Pass | S, P (`CoreIntegrationTest`) |
| Break-glass: second-person approval, time limit, audited | Pass | S, P (`CoreSecurityHardeningTest`) |
| Card credentials: hashed tokens, key versions, revocation, QR without personal data | Pass | S, P (`CoreCardProductionTest`) |
| `db:seed` creates no account and no default password; `core:seed-uat` refuses production | Pass | `CoreDeploymentSafetyTest` — fixed in certification |
| Security headers (nosniff, frame DENY, no-referrer, HSTS over HTTPS) | Pass | `CoreDeploymentSafetyTest` |
| Content security policy enforced | Not Tested | sent as `Content-Security-Policy-Report-Only`; enforce after a browser pass shows no violation |
| Dependency advisories | Pass | `composer audit`: none; `npm audit --omit=dev`: 0 vulnerabilities (2026-10-25) |
| Independent penetration test | Not Tested | needs a security tester |
| Production configuration (`core:config-audit`) on the live server | Not Tested | run on the server; the local `.env` is a development one |

## Data integrity

| Item | Result | Evidence |
|---|---|---|
| Database invariants (one current placement, one live card, one active template, append-only audit) | Pass | S, P (constraint tests across gates) |
| Data-quality checks, severity and issue lifecycle | Pass | S, P (`CoreOversightTest`) |
| EUISIS → Core import keeps identifiers and backfills masters | Pass | S (`CoreImportTest`) |
| Import of production EUISIS data, compared with `core:snapshot` | Not Tested | needs the production data and an operator |

## Migrations

| Item | Result | Evidence |
|---|---|---|
| Every migration applies to an empty database on SQLite and PostgreSQL | Pass | S, P (`RefreshDatabase` on every test) |
| The P3–P5 migrations roll back and re-apply on 300,000 employees without losing a row | Pass | `emscoredb_perf` (synthetic): `migrate:rollback --step=9` in 1 s, `migrate` in 160 s (credentials backfill 2 min 6 s for 245,000 cards, provenance 29 s); employees, placements, positions, audit rows and cards counted equal before and after |
| Placement backfill links each employee's placements in date order | Pass | S, P (`CoreAssignmentProvenanceTest`) |
| Rehearsal on a copy of the production database | Not Tested | needs the production data and an operator |

## Golden Path

| Item | Result | Evidence |
|---|---|---|
| Positive path, organization → verified credential, every stage authorized, scoped and audited | Pass | UAT positive (S, P) |
| Negative path (19 refusals) | Pass | UAT negative (S, P) |
| UAT by business users on the synthetic dataset (`core:seed-uat`) | Not Tested | needs the users; the dataset command is ready and tested |
| Business sign-off | Not Tested | a person's decision; not recorded here |

## APIs

| Item | Result | Evidence |
|---|---|---|
| OpenAPI matches the routes, events and card codes | Pass | `CoreOpenApiTest` |
| API dates canonical ISO whatever the language | Pass | `CoreOperationsTest` |
| Partner systems integrated against the contract | Not Tested | needs the partner teams |

## Audit

| Item | Result | Evidence |
|---|---|---|
| Hash-chained seals; changed or removed rows detected | Pass | S, P (`CoreSecurityHardeningTest`) |
| Sensitive downloads, exports, settings, roles, scopes, MFA reset, credential rotation audited | Pass | S (per-feature tests) |
| Seal heads kept off the database server (log shipping) | Not Tested | needs the log pipeline |
| Separate `CORE_AUDIT_SEAL_KEY` in production | Not Tested | a secret to provision |

## Backups

| Item | Result | Evidence |
|---|---|---|
| Health shows "unknown" when backups are not integrated, never healthy | Pass | `CoreOperationsTest` |
| Database and storage backups running, with their status file | Not Tested | infrastructure |
| Restore drill | Not Tested | infrastructure and an operator |

## Queues and scheduler

| Item | Result | Evidence |
|---|---|---|
| Failed, stuck and retrying jobs visible; retry/remove audited, no payloads shown | Pass | `CoreOperationsTest` |
| Scheduled commands record their runs | Pass | `CoreSystemHealthTest` |
| A queue worker and the scheduler running in production | Not Tested | infrastructure (`QUEUE_CONNECTION` must not be `sync`; `core:config-audit` fails otherwise) |

## Storage

| Item | Result | Evidence |
|---|---|---|
| Evidence, imports and exports on private disks; exports expire | Pass | S (evidence, import, export tests) |
| Production disks, permissions and capacity | Not Tested | infrastructure |

## Notifications

| Item | Result | Evidence |
|---|---|---|
| In-app notifications after commit, scoped, in both languages | Pass | `CoreOperationsTest` |
| A failing e-mail never undoes a change | Pass | `CoreOperationsTest` |
| E-mail delivery through a real mailer | Not Tested | needs mail configuration |
| SMS | Not Applicable | the Core has no SMS gateway (recorded as skipped, never pretended) |

## Monitoring

| Item | Result | Evidence |
|---|---|---|
| System Health from real measurements; slow requests and job timings counted | Pass | `CoreSystemHealthTest`, `CoreOperationsTest` |
| External monitoring and alerting on the logs | Not Tested | needs the monitoring stack |

## Localization

| Item | Result | Evidence |
|---|---|---|
| Amharic and English keys complete; glossary terms | Pass | `npm run i18n:audit` (strict): 0 blocking findings |
| Ethiopian calendar for Amharic, ISO for English, in exports and e-mail | Pass | `CoreOperationsTest` |
| Review of the Amharic wording by a native speaker | Not Tested | needs a reviewer |

## Reports

| Item | Result | Evidence |
|---|---|---|
| Scope-safe reports, sensitive columns withheld, historical structure | Pass | `CoreOversightTest` |
| Exports queued, audited, formula-safe, requester-only | Pass | `CoreOversightTest` |
| Server-rendered PDF with embedded Amharic fonts | Not Applicable | exports offer a printable page instead (ADR 0011 §2) |

## Performance

| Item | Result | Evidence |
|---|---|---|
| Lists, searches, verification and reports at 300,000 employees (single user) | Pass | Perf: lists 27–69 ms, search ≤ 214 ms, card check ≤ 2 ms, scan 8.5 s |
| Load test at production concurrency on production-like hardware | Not Tested | needs the environment |

## UAT

| Item | Result | Evidence |
|---|---|---|
| Automated UAT (positive and negative) | Pass | UAT |
| Staging deployment rehearsal | Not Tested | needs a staging server |
| Application rollback to the previous release on a server | Not Tested | needs a staging server; the database side is rehearsed (Migrations) |
| UAT with business users and their sign-off | Not Tested | people |
