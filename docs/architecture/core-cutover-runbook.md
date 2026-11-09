# Core cutover runbook

For any window in which Golden Path data moves or its writer changes: a
database migration between engines or servers, a restore, or (later) the move
to a separate Core application. **Not executed yet; rehearse on staging first.**
A maintenance window for master data is required: this procedure does not
claim zero downtime.

Prerequisites: [core-physical-separation.md](core-physical-separation.md)
readiness table green for the scope being moved; a verified backup and a tested
restore ([../backup-recovery-architecture.md](../backup-recovery-architecture.md));
[core-rollback-runbook.md](core-rollback-runbook.md) read by everyone on the call.

| # | Step | Command / check | Owner |
| --- | --- | --- | --- |
| 1 | Announce the master-data window; service modules keep running | — | release manager |
| 2 | Confirm the latest backup and its restore test | backup page, `backup:*` reports | operations |
| 3 | Integrity baseline must be clean (or every finding accepted in writing) | `php artisan backbone:audit-integrity --strict` | Core data steward |
| 4 | Freeze master-data writes | set `CORE_MASTER_DATA_WRITES=cutover` (old spelling `frozen`), `php artisan config:cache` | operations |
| 5 | Let in-flight jobs finish; stop workers | `php artisan queue:work --stop-when-empty` per queue, then stop supervisors | operations |
| 6 | Publish what the outbox still holds | `php artisan integrations:publish`, then `php artisan integrations:deliver`, until Integration Center shows 0 events waiting | operations |
| 7 | Snapshot the source | `php artisan core:snapshot --output=before.json` | operations |
| 8 | Copy / restore / switch the data (the scope-specific step) | per plan | DBA |
| 9 | Compare the target | `php artisan core:snapshot --compare=before.json` must exit 0. Before switching, the target can be read through a second connection: `php artisan core:snapshot --connection=<target> --compare=before.json`. A difference also prints the status counts that changed (e.g. one more `suspended` employee). Rehearsed by `CutoverToolingTest` (copy to another database, match, then a detected drift). | operations |
| 10 | Integrity on the target | `php artisan backbone:audit-integrity --strict` | Core data steward |
| 11 | Point the application at the target, then open writes | `CORE_MASTER_DATA_WRITES=core` (old spelling `open`), `php artisan config:cache` | operations |
| 12 | Start workers | supervisors on | operations |
| 13 | Smoke tests | `php artisan core:smoke` and the go-live smoke list | QA |
| 14 | Watch for one hour: error rate, 403s, card verification failures, outbox backlog | Intelligence system health, Integration Center | operations |
| 15 | Close the window, or roll back by the rollback runbook | — | release manager |

`php artisan list core` shows the Core tooling available at the release being
deployed.

If any check in steps 9 to 13 fails, do not open writes: follow the rollback
runbook while writes are still frozen.
