# Go-live runbook (Core)

Use only when [core-acceptance-matrix.md](core-acceptance-matrix.md) says GO.
The general checklist is [../go-live-checklist.md](../go-live-checklist.md);
this runbook adds the Core steps. Nobody on the call shares secrets in chat.

## T-7 days

- [ ] Release candidate tagged after review; CI green.
- [ ] UAT sign-offs recorded in the acceptance matrix.
- [ ] Production branch built (`scripts/release/build-production-branch.sh`) and reviewed.
- [ ] Live runtime confirmed (PHP version and extensions; Node 24 LTS for the build, 22 LTS as the fallback; never Node 20, which is end of life).
- [ ] `php artisan production:readiness --strict` shows every go-live gate (G1–G5) as PASS ([go-live-gates.md](go-live-gates.md)).
- [ ] Restore drill done on a copy; `core:snapshot` compared before and after.
- [ ] Communication to administrators: the Core workspace, what changes, where to get help.

## T-1 day

- [ ] `php artisan production:readiness --strict` on the production host: no FAIL.
- [ ] Fresh backup verified.
- [ ] Rollback decision owner and contacts confirmed.

## Go-live window

| # | Step | Command / check |
| --- | --- | --- |
| 1 | Maintenance notice on | `php artisan down --render=errors::503` (or the load balancer page) |
| 2 | Snapshot | `php artisan core:snapshot --output=golive-before.json` |
| 3 | Deploy release | per [../runbook/deployment-and-rollback.md](../runbook/deployment-and-rollback.md) |
| 4 | Migrate | `php artisan migrate --force` (additive only) |
| 5 | Cache | `config:cache`, `route:cache`, `view:cache`, `event:cache` |
| 6 | Workers and scheduler | restart queue workers; confirm cron runs `schedule:run` |
| 7 | Data unchanged by the deploy | `php artisan core:snapshot --compare=golive-before.json` exits 0 |
| 8 | Integrity | `php artisan backbone:audit-integrity --strict` |
| 9 | Smoke | `php artisan core:smoke` |
| 10 | Up | `php artisan up` |
| 11 | Manual checks | sign in at `/core/login`; open each Core page; verify one real card at `/verify`; one partner API call with a test application |
| 12 | Decide | all pass → announce; any Severity 1 → [rollback-runbook.md](rollback-runbook.md) |

## After

Follow [../architecture/post-cutover-stabilization.md](../architecture/post-cutover-stabilization.md).
