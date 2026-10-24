# Platform governance

How Core and the EUISIS services are run after go-live: who decides what, which
rules are enforced by code, and where the records are kept. It builds on the
existing policies instead of repeating them.

## Roles

| Role | Decides | Named in |
| --- | --- | --- |
| Business owner | scope, priorities, acceptance, RPO/RTO, retention | acceptance matrix sign-offs |
| Core data steward | master-data quality, integrity findings, legacy retirement | [data-stewardship.md](data-stewardship.md) |
| Security owner | access model, partner access, pen tests, incidents | [security-governance.md](security-governance.md) |
| Integration owner | partner onboarding, scopes, API and event versions | [integration-onboarding.md](integration-onboarding.md), [api-lifecycle.md](api-lifecycle.md) |
| Release manager | release contents, change windows, rollback calls | [release-management.md](release-management.md) |
| Operations lead | deployment, backups, monitoring, on-call | [../operations/README.md](../operations/README.md) |
| Module owners | their service module and its move onto Core contracts | [../architecture/module-integration-matrix.md](../architecture/module-integration-matrix.md) |

People are assigned to these roles by the business owner; until then each row is **unassigned** and the related sign-off cannot be given.

## Rules enforced by code (CI fails when broken)

| Rule | Test |
| --- | --- |
| Only Core writes master data | `BackboneBoundaryTest`, `MachineWriteBoundaryTest` |
| No new direct Golden Path reads in service modules | `ServiceModuleCoreDependencyTest` |
| Migrations after the baseline are additive | `MigrationSafetyTest` |
| Partner API and its OpenAPI document match | `OpenApiContractTest` |
| Amharic and English complete | `npm run i18n:audit` |
| Formatting | Pint |

Changing one of these tests to let a change through is itself a change that needs the security owner's or the data steward's review.

## Cadence

| When | What |
| --- | --- |
| Every release | [release-management.md](release-management.md) checklist |
| Weekly | integrity, smoke, outbox and webhook health |
| Monthly | `api:access-review`; review [deprecation-register.md](deprecation-register.md) and [technical-debt.md](technical-debt.md) |
| Quarterly | access recertification, dependency and vulnerability review, restore drill, governance review of this page |

## Existing policies this relies on

[../change-management-policy.md](../change-management-policy.md), [../patch-management-plan.md](../patch-management-plan.md), [../vulnerability-management.md](../vulnerability-management.md), [../operations-security-policy.md](../operations-security-policy.md), [../password-security-policy.md](../password-security-policy.md), [../incident-response-plan.md](../incident-response-plan.md), [../intelligence-ai-governance.md](../intelligence-ai-governance.md).
