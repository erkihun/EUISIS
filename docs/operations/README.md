# Operations

Where to go for each operational situation. The documents live where they were
written; this page only points at them.

## Checks to run

| When | Command |
| --- | --- |
| Before any production deployment | `php artisan production:readiness --strict` (includes `security:production-check` and the Core section) |
| After every deployment | `php artisan core:smoke` |
| Daily in the first weeks, then weekly | `php artisan backbone:audit-integrity`, `php artisan api:access-review` |
| Before and after a data move or restore | `php artisan core:snapshot --output=…` / `--compare=…` |
| Before retiring a legacy master | `php artisan backbone:duplicate-masters` |

## Runbooks

| Situation | Document |
| --- | --- |
| Deploy and roll back a release | [../runbook/deployment-and-rollback.md](../runbook/deployment-and-rollback.md), [../production-runbook.md](../production-runbook.md) |
| Go-live | [../go-live/go-live-runbook.md](../go-live/go-live-runbook.md), [../go-live-checklist.md](../go-live-checklist.md) |
| Go-live rollback | [../go-live/rollback-runbook.md](../go-live/rollback-runbook.md) |
| Core data move or restore window | [../architecture/core-cutover-runbook.md](../architecture/core-cutover-runbook.md), [../architecture/core-rollback-runbook.md](../architecture/core-rollback-runbook.md) |
| Backups | [../runbooks/database-backup.md](../runbooks/database-backup.md), [../runbooks/backup-health-troubleshooting.md](../runbooks/backup-health-troubleshooting.md) |
| Restore and disaster recovery | [../runbooks/disaster-recovery.md](../runbooks/disaster-recovery.md), [../runbooks/postgresql-pitr.md](../runbooks/postgresql-pitr.md), [../runbooks/restore-validation.md](../runbooks/restore-validation.md), [../runbooks/object-storage-recovery.md](../runbooks/object-storage-recovery.md) |
| Security incident | [../incident-response-plan.md](../incident-response-plan.md), [../incident-response.md](../incident-response.md) |
| Lost keys or secrets | [../key-recovery-runbook.md](../key-recovery-runbook.md) |
| Integration problems | [../architecture/integration-operations.md](../architecture/integration-operations.md) |
| Slow pages | [../runbooks/web-performance.md](../runbooks/web-performance.md) |
| Public feedback problems | [../runbooks/public-feedback-troubleshooting.md](../runbooks/public-feedback-troubleshooting.md) |
| Intelligence alerts | [../intelligence-runbooks.md](../intelligence-runbooks.md) |

## Policies

Change management [../change-management-policy.md](../change-management-policy.md),
patching [../patch-management-plan.md](../patch-management-plan.md),
vulnerabilities [../vulnerability-management.md](../vulnerability-management.md),
operations security [../operations-security-policy.md](../operations-security-policy.md),
governance [../governance/platform-governance.md](../governance/platform-governance.md).

## Capacity and resilience evidence

[../architecture/core-production-baseline.md](../architecture/core-production-baseline.md) and [../production-readiness/](../production-readiness/).
