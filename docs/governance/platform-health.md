# Platform health scorecard

Baseline taken 25 October 2026 from the repository, the test runs and what the
live host (erkihun.et) reported during deployment. Statuses: HEALTHY,
ATTENTION_REQUIRED, AT_RISK, NOT_MEASURED. A status is only as good as its
evidence; nothing here is estimated, and an area with no production
measurement says so. Re-score in each quarterly architecture review
([platform-governance.md](platform-governance.md)).

| Area | Status | Evidence | What moves it |
| --- | --- | --- | --- |
| Architecture | ATTENTION_REQUIRED | Core boundary, scope, contract and drift tests pass in CI; 210 service-module files still read Golden Path models directly (TD-1); physical separation not started (TD-9); live host runs MySQL against decision D-5 (TD-19) | allowlists shrinking; ADR on the production engine |
| Data quality | NOT_MEASURED | the checks exist (`structure:audit`, `data:audit-duplicates`, `backbone:audit-integrity`, `backbone:duplicate-masters`), but no production run is recorded | run them in production; record counts per rule monthly |
| Security | ATTENTION_REQUIRED | ADR 0003 controls in code; dependency audits clean; MFA required for admin roles; no penetration test (G4); no recorded production access review; secrets rotation dates not recorded; no time-limited elevation (TD-17) | `access:review` and `api:consumers` in production; penetration test; rotation dates |
| Performance | NOT_MEASURED | 3-million-employee benchmark on a development machine ([../production-readiness/load-test-results.md](../production-readiness/load-test-results.md)); no production latency figures | API latency from `api_request_logs`; load test on production-like hardware |
| Availability | AT_RISK | the live site returned HTTP 500 on 24 October (previous build could not install on PHP 8.5) and commands failed with a database access error on 25 October; no uptime monitoring or alert routing (TD-8); no agreed SLO | monitoring and alerting; SLOs agreed with the Business Owner |
| Integration | ATTENTION_REQUIRED | OpenAPI and event contracts guarded by tests; every partner route documented; partner tokens expire after 8 hours unless the application sets a lifetime in days (TD-16, resolved 2026-10-28); registry lacks owner/expiry fields (TD-18); production consumers not yet reviewed | `api:consumers` review |
| DR | AT_RISK | backups NOT_CONFIGURED on the live host; RPO 15 min / RTO 1 h and 4 h are TARGET_NOT_YET_VALIDATED; no restore or failover drill (G1, G2) | backups with monitoring; restore and failover drills recorded |
| Operations | ATTENTION_REQUIRED | readiness reported `APP_ENV=prodaction`, debug logging and an un-rotated log until corrected; scheduler cron and queue worker not confirmed on the host; runbooks exist ([../operations/README.md](../operations/README.md)) | readiness clean in production; cron and worker confirmed; audit seals running daily |

## How to re-measure

```
php artisan production:readiness
php artisan structure:audit --json
php artisan data:audit-duplicates --json
php artisan backbone:audit-integrity
php artisan access:review
php artisan access:scope-gaps
php artisan api:consumers
php artisan audit:verify
php artisan core:smoke
```

Record the date, environment and each result in the review minutes. A status
improves only on recorded evidence (a drill record, a test report, a
production run), never on a plan.
