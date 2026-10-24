# ADR 0004: Go-live gates, recovery targets, retention, governance roles and runtime

Status: accepted, 24 October 2026. Decided by the project owner (two written
recommendations, in English and Amharic, adopted as decisions). Items marked
"management" still need named people or a policy approval; nothing here
records an approval that has not happened.

## Decisions taken now

1. **Go-live gates.** Six formal gates in the owner's order: G1 backups and
   database failover, G2 recovery targets, G3 build runtime, G4 penetration
   test and UAT, G5 governance roles, G6 retention. Go-live is not allowed
   while G1, G2, G4 or G5 is open ([../../go-live/go-live-gates.md](../../go-live/go-live-gates.md)).
   `production:readiness --strict` enforces them from evidence references
   (`config/golive.php`).
2. **Recovery targets.** RPO 15 minutes; RTO 1 hour for an application or
   server failure; RTO 4 hours maximum for a full database restore. They are
   the configured targets (`BACKUP_RPO`, `BACKUP_RTO`) and show as
   **TARGET_NOT_YET_VALIDATED** — never "configured" or "achieved" — until a
   restore drill met them and its record is named in
   `BACKUP_OBJECTIVES_VALIDATED_BY`. Until then backup readiness stays NOT_READY.
3. **Backups, PITR, failover, restore drill: mandatory** before go-live,
   including an off-server copy, monitoring and a rehearsed failover
   ([../../runbooks/database-failover.md](../../runbooks/database-failover.md), `GOLIVE_FAILOVER_DRILL`).
4. **Node.js 24 LTS** for building assets, Node 22 LTS as the fallback; Node 20
   is end of life (Vite needs 20.19+ or 22.12+). `package.json` engines
   `>=22.12.0`, `.nvmrc` 24, CI builds on `.nvmrc`, and readiness checks the
   Node on the server's PATH.
5. **Penetration test: mandatory**, by an independent team or at least one
   separate from development ([../../go-live/penetration-test-scope.md](../../go-live/penetration-test-scope.md)).
   **UAT sign-off: mandatory**, by HR, Institution Admin, ID Officer,
   Provider, Auditor, Employee and the relevant committees on real business
   scenarios ([../../go-live/uat-plan.md](../../go-live/uat-plan.md)). Go-live is
   not allowed with an unresolved P0 or P1 security or business defect; the
   open counts must be recorded as 0.
6. **Retention mechanism.** The application supports retain, archive and
   purge_when_authorized per data class, and never deletes a record class
   without an approved period and approval reference. The automatic deletion
   of audit (730 days), verification (365) and API logs (90) stops; those logs
   are kept until approved. Operational housekeeping (notifications,
   reminders) is unchanged ([../../governance/retention-matrix.md](../../governance/retention-matrix.md)).

## Needs management or policy approval

| Item | Recorded as | Until approved |
| --- | --- | --- |
| Retention periods per class (HR, Legal, Records Management, Audit, Data Protection) | `RETENTION_<CLASS>_*` | NEEDS_POLICY_DECISION_RETENTION; records kept |
| Named governance roles (Project Sponsor, Business Owner, System Owner, Data Owner, Data Steward, Technical Custodian, Information Security Lead, Database/Infrastructure Administrator, DR Coordinator, Incident Manager, Integration/API Owner, Release Manager, Audit/Compliance Owner, module owners) | `GOVERNANCE_*`; [../../governance/roles-register.md](../../governance/roles-register.md) | NAME_REQUIRED; gate G5 closed |
| DR classification per critical service | proposal in the gates document | PROPOSED |
| Legal, data-sharing and archival requirements | retention and data-sharing review | NEEDS_POLICY_DECISION |

## Consequences

- A production deployment of this release with no gate evidence fails
  `production:readiness --strict`, as intended.
- Database growth: the three log classes now grow until their retention is
  approved; `data:retention-report` shows row counts for the review.
- The backup page shows "Target set, not yet validated" for RPO and RTO.
