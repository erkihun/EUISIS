# Production cutover

Status on 24 October 2026: **not performed, blocked.** This page states what
"cutover" means for Core in this codebase, the go/no-go conditions and the
blockers. No production system was touched by this work.

## What cutover means here

Core already runs in production as part of EUISIS (same application, same
database). The remaining cutover is operational, not a data move:

1. Core is declared the system of record for the data in [core-authority.md](core-authority.md) (already true in code).
2. Administrators use the Core workspace (`/core`) for master data; the old sidebar entries stay as DUAL AVAILABLE until the deprecation criteria are met.
3. Partners and service applications read through `/api/v1`, the change feed and webhooks.
4. Legacy paths are retired by [legacy-deprecation.md](legacy-deprecation.md).

A data move to a separate Core database is a later, separate cutover ([core-cutover-runbook.md](core-cutover-runbook.md)).

## Go / no-go conditions

| # | Condition | How to check | State |
| --- | --- | --- | --- |
| 1 | Release merged after review and deployed by the operations team | change ticket | pending |
| 2 | Migrations applied, none pending | `php artisan production:readiness --strict` | per environment |
| 3 | Golden Path integrity clean | `php artisan backbone:audit-integrity --strict` | per environment |
| 4 | Backups, PITR, standby failover and restore drills (gates G1, G2: RPO 15 min, RTO 1 h / 4 h approved as targets) | `production:readiness --strict` Go-live gates and Operations sections | **not ready**: not deployed; targets TARGET_NOT_YET_VALIDATED |
| 5 | Core smoke checks pass | `php artisan core:smoke` | per environment |
| 6 | UAT signed by the business, no open P0/P1 (gate G4) | [../go-live/uat-plan.md](../go-live/uat-plan.md) | **not done** |
| 7 | Independent penetration test, no open P0/P1 (gate G4) | [../go-live/penetration-test-scope.md](../go-live/penetration-test-scope.md) | **not done** |
| 8 | Live server runtime confirmed: Node 24 LTS (22 LTS fallback; Node 20 is end of life) for the build, PHP extensions (gate G3) | Technical Custodian | **not confirmed** |
| 9 | Rollback rehearsed | [core-rollback-runbook.md](core-rollback-runbook.md) | **not done** |
| 10 | Governance roles named by management (gate G5) | [../governance/roles-register.md](../governance/roles-register.md) | **not done** |

Cutover may start only when every row is met. Today rows 4, 6, 7, 8, 9 and 10 are open. The gates are defined in [../go-live/go-live-gates.md](../go-live/go-live-gates.md).

## Steps once go

Follow [../go-live/go-live-runbook.md](../go-live/go-live-runbook.md). Afterwards, [post-cutover-stabilization.md](post-cutover-stabilization.md).
