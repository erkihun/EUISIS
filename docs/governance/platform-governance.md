# Platform governance

How Core and the EUISIS services are run after go-live: who decides what, which
rules are enforced by code, and where the records are kept. It builds on the
existing policies instead of repeating them.

## Roles

The roles, their decisions and their holders are in the
[roles register](roles-register.md): Project Sponsor, Business Owner, System
Owner, Data Owner, Data Steward, Technical Custodian, Information Security
Lead, Database/Infrastructure Administrator, DR Coordinator, Incident
Manager, Integration/API Owner, Release Manager, Audit/Compliance Owner and
one owner per module. Management names every holder before go-live (gate
G5); one person may hold several roles, but ownership is explicit and not
"IT owns everything". Until a role is named, the sign-offs that depend on it
cannot be given.

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
