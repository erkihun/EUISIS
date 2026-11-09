# Go-live gates

Decided by the project owner on 24 October 2026 (ADR 0004): these are formal
go-live gates, not follow-up tasks. Production go-live is **not allowed**
while G1, G2, G4 or G5 is open. `php artisan production:readiness --strict`
checks each gate from the evidence references in `config/golive.php` and
`config/backup.php`; a reference is the identifier of the signed record
(report number, memo, ticket), never the document or a secret.

Priority order (the owner's):

| Gate | What must be true | Evidence recorded as | Owner role | Blocks go-live | State (24 Oct 2026) |
| --- | --- | --- | --- | --- | --- |
| G1 Backups and database failover | Automated encrypted backups; an off-server copy; point-in-time recovery; a standby database with failover; monitoring and alerts; a rehearsed failover; the recovery runbooks reviewed | `production:readiness` "Backup and recovery ready" (backup evidence controls) and `GOLIVE_FAILOVER_DRILL` | Database/Infrastructure Administrator, DR Coordinator | yes | **open**: not deployed |
| G2 Recovery targets | RPO 15 min; RTO 1 h for an application or server failure; RTO 4 h maximum for a full database restore — approved as targets, proven by a restore drill | `BACKUP_OBJECTIVES_VALIDATED_BY` (until then the backup page shows TARGET_NOT_YET_VALIDATED) | DR Coordinator, Business Owner | yes | **open**: targets approved, not validated |
| G3 Build runtime | Node.js 24 LTS (22 LTS as the fallback) where assets are built; Node 20 is end of life | `production:readiness` G3 check; `.nvmrc` | Technical Custodian | yes (operations confirms) | **open** until the server is confirmed |
| G4 Penetration test and UAT | An independent penetration test (by a team separate from development) and business UAT sign-off, with no open P0 or P1 security or business defect | `GOLIVE_PENTEST_REPORT` + `GOLIVE_PENTEST_OPEN_P0_P1=0`; `GOLIVE_UAT_SIGNOFF` + `GOLIVE_UAT_OPEN_P0_P1=0` | Information Security Lead; Business Owner | yes | **open**: plans in [penetration-test-scope.md](penetration-test-scope.md), [uat-plan.md](uat-plan.md) |
| G5 Governance ownership | Every role in [../governance/roles-register.md](../governance/roles-register.md) named by management | `GOVERNANCE_*` keys | Project Sponsor | yes | **open**: no names recorded |
| G6 Retention schedule | HR, Legal, Records Management, Audit and Data Protection approve a period per data class | `RETENTION_<CLASS>_*` keys; `php artisan data:retention-report` | Data Owner, Audit/Compliance Owner | no: records are kept, never deleted, until approved | NEEDS_POLICY_DECISION_RETENTION for all 12 classes |

## Also needs management approval

| Decision | Proposal | State |
| --- | --- | --- |
| DR classification per critical service | See below | PROPOSED, needs approval |
| Legal, data-sharing and archival requirements (partner data, archives, national-ID handling) | Collected with the retention review | NEEDS_POLICY_DECISION |

### Proposed DR classification (needs approval)

The application and database are one system today, so a tier sets the
restore priority and what may run degraded, not separate infrastructure.

| Tier | Services | Target |
| --- | --- | --- |
| Tier 1 | Sign-in; ID-card and QR verification; service eligibility at cafeteria, transport and NFC terminals; Core master data | RPO 15 min, RTO 1 h (application) |
| Tier 2 | HR workflows (transfers, grievance, discipline, appeals, performance), partner API and webhooks, employee portal | RTO 4 h (with a database restore) |
| Tier 3 | Reports, dashboards, Situation Room, Intelligence, assistant | restored last; may stay down up to one business day |

## Recording evidence

1. The gate owner obtains the signed record.
2. The Database/Infrastructure Administrator sets the reference in the production environment (never in Git), then `php artisan config:cache`.
3. `php artisan production:readiness --strict` shows the gate as PASS.
4. The acceptance matrix sign-off table ([core-acceptance-matrix.md](core-acceptance-matrix.md)) gets the same reference, name and date.
