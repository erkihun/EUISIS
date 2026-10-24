# Core production baseline

One page that says what Core is, what has been measured, and what is still
open, with links to the evidence. Status on 24 October 2026: **not ready for a
city-scale production claim**. Nothing on this page is a production
measurement: every number comes from local benchmarks named below.

## Architecture (where to read)

| Topic | Document |
| --- | --- |
| Source of truth and enforcement | [core-authority.md](core-authority.md) |
| Core boundary and future separation | [core-physical-separation.md](core-physical-separation.md), [database-ownership.md](database-ownership.md) |
| Contracts for service modules | `app/Backbone/Contracts`, [module-integration-matrix.md](module-integration-matrix.md) |
| Partner contracts | [../integration/api-v1-contract.md](../integration/api-v1-contract.md), [../integration/openapi-v1-partner.yaml](../integration/openapi-v1-partner.yaml), [../integration/event-catalog.md](../integration/event-catalog.md) |
| Failure behaviour | [failure-modes.md](failure-modes.md) |
| Core workspace | [core-workspace.md](core-workspace.md) |

## Measured (local benchmark, not production hardware)

| Question | Result | Evidence |
| --- | --- | --- |
| Do schema and queries hold 3 million employees? | Yes: canonical lookups 0.1–0.7 ms on 3,000,004 synthetic employees | [../production-readiness/3m-employee-readiness.md](../production-readiness/3m-employee-readiness.md), [../production-readiness/scale-baseline.md](../production-readiness/scale-baseline.md) |
| Is correctness kept under concurrency? | Yes in the races tested; one P0 found and fixed | [../production-readiness/failure-testing.md](../production-readiness/failure-testing.md) |
| Partner API throughput | 80 req/s with p95 443 ms on one machine (16 single-threaded PHP servers); card-scan burst saturates near 40 scans/s on that rig | [../production-readiness/load-test-results.md](../production-readiness/load-test-results.md) |
| Outbox capacity | about 480 events/s | [../production-readiness/queue-readiness.md](../production-readiness/queue-readiness.md) |

## Not measured or not decided

| Gap | Why it blocks | Owner |
| --- | --- | --- |
| Expected usage (requests and scans per second at peak) | no usage forecast exists, so capacity cannot be sized | business owner |
| Throughput on production-class servers with PHP-FPM and PgBouncer | local rig only | operations |
| RPO and RTO | **NEEDS_DECISION** ([../backup-and-disaster-recovery.md](../backup-and-disaster-recovery.md)) | business owner + operations |
| PostgreSQL standby and failover | not deployed (**NEEDS_DEPLOYMENT_CONFIGURATION_POSTGRES_HA**, [../production-readiness/deployment-topology.md](../production-readiness/deployment-topology.md)) | operations |
| Restore test on production backups | not recorded | operations |
| Penetration test of Core and `/api/v1` | not performed | security owner |
| Central logging and alert routing | hosting decision | operations |

## Security baseline (in place)

Permission plus organization scope on every Core page and API; machine
principals read-only for master data; partner scopes checked against the
application on every call; signed webhooks; privacy-minimised public QR;
sensitive fields encrypted and redacted in audit; organization-level roles
confined to an explicit scope (ADR 0003); audit log sealed daily with keyed
checksums (`audit:verify`); provider card checks must name their provider;
`security:production-check` and `production:readiness` gates. Dependency
audit on 24 October 2026, after raising esbuild to 0.28: `composer audit` no
advisories; `npm audit` 0 vulnerabilities.
