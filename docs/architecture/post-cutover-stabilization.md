# Post-cutover stabilization

The first 30 days after Core becomes the declared system of record.

## Daily (first two weeks), then weekly

| Check | Command / place | Expected |
| --- | --- | --- |
| Golden Path integrity | `php artisan backbone:audit-integrity` | all zero |
| Core smoke | `php artisan core:smoke` | all pass |
| Go-live gate | `php artisan production:readiness` | no new FAIL |
| Outbox and webhooks | Core › Integration Center | nothing waiting > 15 min; no new dead deliveries |
| Partner access | `php artisan api:access-review` | no new findings |
| Sign-in failures, 403s, card verification failures | Core › Audit Center; Intelligence › System health | no unexplained rise |
| Change requests waiting | Core dashboard | none older than the agreed service level |
| Backups | Backup page | healthy |

## Defects

Record every defect in the acceptance matrix defect register
([../go-live/core-acceptance-matrix.md](../go-live/core-acceptance-matrix.md))
with severity. A Severity 1 (wrong access, wrong card validity, data loss) stops
further rollout and opens the rollback decision.

## Exit

Stabilization ends when for 14 consecutive days: integrity is zero, smoke
passes, no Severity 1 or 2 defect is open, and the business owner confirms.
Then the DUAL AVAILABLE pages can enter the deprecation process.
