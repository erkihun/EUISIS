# Platform governance

How Core and the services around it are run after go-live: who decides what,
which rules are enforced by code, and where the records are kept. It builds
on the existing policies instead of repeating them. Health today:
[platform-health.md](platform-health.md).

## The permanent rule

> Core owns Organization, Organizational Unit, Position, Employee, Assignment
> and Employee ID / Credential master data. No other module creates an
> authoritative copy of them.

Services integrate with Core only through:

| Method | Where |
| --- | --- |
| Core read contracts (in-process modules) | `App\Backbone\Contracts` ([../architecture/master-backbone.md](../architecture/master-backbone.md)) |
| Core commands (in-process writes) | `App\Backbone\Commands`, Core actions |
| Core API (other systems) | `/api/v1` ([api-lifecycle.md](api-lifecycle.md)) |
| Core events and change feed | transactional outbox, `/api/v1/changes`, webhooks ([event-lifecycle.md](event-lifecycle.md)) |

Machine clients never write master data (ADR 0002). Analytics, Situation Room
and Intelligence read; they never change master data (`GovernanceDriftTest`).
Decisions are recorded as ADRs ([../architecture/adr/README.md](../architecture/adr/README.md)).

## Governance areas and owners

Role holders are named in the [roles register](roles-register.md). Until a role
is named, its sign-offs cannot be given (gate G5).

| Area | Owner (role) | Record |
| --- | --- | --- |
| Architecture | System Owner | ADRs; this page; [technical-debt.md](technical-debt.md) |
| Data ownership | Data Owner | [data-ownership.md](data-ownership.md) |
| Data quality | Data Steward | [data-stewardship.md](data-stewardship.md) |
| API contracts and consumers | Integration/API Owner | [api-lifecycle.md](api-lifecycle.md), `api:consumers` |
| Event contracts | Integration/API Owner | [event-lifecycle.md](event-lifecycle.md), `EventCatalogContractTest` |
| Security and access | Information Security Lead | [security-governance.md](security-governance.md) |
| Releases | Release Manager | [release-management.md](release-management.md), [../releases/](../releases/README.md) |
| Database changes | Database/Infrastructure Administrator with System Owner | [release-management.md](release-management.md#database-changes) |
| Integration onboarding | Integration/API Owner with Information Security Lead | [integration-onboarding.md](integration-onboarding.md) |
| Deprecation | Integration/API Owner (APIs, events), Data Steward (data) | [deprecation-register.md](deprecation-register.md) |
| Operations, backup and DR | Technical Custodian, DR Coordinator | [../operations/README.md](../operations/README.md), [../go-live/go-live-gates.md](../go-live/go-live-gates.md) |
| Incident review | Incident Manager | [security-governance.md](security-governance.md#incidents) |
| Business rules | Business Owner and module owners | [Business rules](#business-rules) below |

## Rules enforced by code (CI fails when broken)

| Rule | Test or step |
| --- | --- |
| Only Core writes master data | `BackboneBoundaryTest`, `MachineWriteBoundaryTest` |
| No new direct Golden Path reads in service modules | `ServiceModuleCoreDependencyTest` |
| Analytics and AI never change master data; scope logic stays in `OrganizationScopeService`; API controllers never return a master record directly | `GovernanceDriftTest` |
| Migrations after the baseline are additive | `MigrationSafetyTest` |
| Partner API and its OpenAPI document match | `OpenApiContractTest` |
| Published event types and payload keys stay compatible | `EventCatalogContractTest` with `tests/Fixtures/contracts/event-catalog-v1.json` |
| Amharic and English complete | `npm run i18n:audit` |
| No known PHP advisory, no high/critical npm advisory | CI `composer audit --locked`, `npm audit --audit-level=high` |
| Formatting | CI `pint --test` on app, config, database, routes, tests |
| Sensitive paths reviewed by their owners | [`.github/CODEOWNERS`](../../.github/CODEOWNERS), effective once branch protection requires code-owner review |

Changing one of these tests to let a change through is itself a change that
needs the Information Security Lead's or the Data Steward's review. The tests
are guards, not judgement: drift they cannot see (for example a new endpoint
that skips the audit log) is caught in review and in the quarterly
architecture review.

## Cadence

| When | What | Tool / record |
| --- | --- | --- |
| Every release | release gates and notes | [release-management.md](release-management.md), [../releases/](../releases/README.md) |
| Daily (scheduled) | audit-log seal | `audit:seal` |
| Weekly | integrity, smoke, outbox and webhook health | `backbone:audit-integrity`, `core:smoke`, Integration Center |
| Monthly | partner access and consumer registry | `api:access-review`, `api:consumers` |
| Monthly | privileged and unscoped accounts | `access:review`, `access:scope-gaps` |
| Monthly | data quality | `structure:audit`, `data:audit-duplicates`, `backbone:duplicate-masters` |
| Monthly | deprecation and technical debt | [deprecation-register.md](deprecation-register.md), [technical-debt.md](technical-debt.md) |
| Quarterly | architecture review (below) | minutes and ADRs |
| Quarterly | access recertification, vulnerability review, restore drill | [security-governance.md](security-governance.md), [../go-live/go-live-gates.md](../go-live/go-live-gates.md) |
| Yearly | penetration test; review of this page | [../go-live/penetration-test-scope.md](../go-live/penetration-test-scope.md) |

### Quarterly architecture review

Agenda: domain boundaries and ratchet baselines (did the allowlists shrink?),
direct dependencies on Golden Path models, API and event versions in use
(`api:consumers`), data ownership changes, technical debt and deprecation
progress, capacity trend, security findings, DR drill results and the
[platform-health](platform-health.md) scorecard. Decisions go into ADRs;
actions into the debt or deprecation register with an owner and a date.

## Change priority for Core

1. Data integrity, 2. Security, 3. Operational reliability, 4. Compatibility,
5. Performance, 6. Usability, 7. New features, unless the Business Owner
declares an emergency in writing. Feature growth in the services does not
justify changes that weaken Core.

## Business rules

Rules with business meaning are not redefined by developers alone: employee
status and employment transitions (ADR 0002), assignment rules, card validity
and replacement, organization lifecycle and restructuring, service
eligibility. A change needs the Business Owner (or the module owner) in the
pull request and, where history depends on it, a versioned rule:

- Organization structure: hierarchy versions with publish and effective dates; restructuring through change requests (proposal, legal or administrative basis, impact analysis, approval, effective date, publication, then `structure:audit`). No hierarchy edits outside that workflow.
- Performance: rating scales and KPI scoring rules are frozen once a submitted plan or agreement uses them.
- Cafeteria subsidy and transport eligibility: rule changes are dated; past transactions keep the amounts recorded at the time.
- Card display: print snapshots keep what was printed.

## Reports and analytics

| Class | Examples | Source of truth | Refresh |
| --- | --- | --- | --- |
| Operational | lists, queues, work pages | live Core and module tables through scope | on request |
| Audit | Audit Center, audit-log export | `audit_logs` (sealed daily) | on request |
| Management | Situation Room, Core dashboard figures | Core tables; cached per scope | minutes |
| Legal | court-case, discipline and appeal records | module tables | on request |
| Analytics | Intelligence analyses | read-only queries over Core and modules | scheduled |

No report is built from a copied "shadow" table. Heavy analytical work belongs
on a read replica or reporting database once one exists (none today;
[technical-debt.md](technical-debt.md) TD-15); until then it runs scheduled
or cached, not per page view.

Situation Room is read and analysis only: it records its own follow-up
actions, never changes Core records. Intelligence and the assistants may read
authorized data, summarise, suggest and flag anomalies; any change to an
organization, position, employee, assignment or credential goes through the
normal authorized workflow by a person
([../intelligence-ai-governance.md](../intelligence-ai-governance.md)). Data sent
to an AI provider is the minimum for the question, never national ID, phone,
address or photos.

## Service levels and capacity

Indicators measured today: Core availability (`core:smoke`), API latency and
errors (`api_request_logs`), card verification outcomes (verification logs),
assignment command failures (audit log and application log), queue health
(`failed_jobs`, readiness), event delivery lag (Integration Center). Formal
SLOs are set only with the Business Owner and operations; none is agreed yet,
so none is claimed.

Capacity is reviewed quarterly from `core:snapshot` counts, table and index
sizes, audit and log growth, API traffic and event volume, against the
[capacity plan](../capacity-plan.md) and the 3-million-employee readiness work
([../production-readiness/3m-employee-readiness.md](../production-readiness/3m-employee-readiness.md),
[../production-readiness/database-capacity.md](../production-readiness/database-capacity.md)).
Re-run the load scenarios ([../load-testing.md](../load-testing.md)) after a
major query, schema, hierarchy, search or API change. Data is archived only
under an approved retention decision ([retention-matrix.md](retention-matrix.md)).

## Change management and knowledge

Significant releases state business impact, training, migration,
communication, support and rollback ([release-management.md](release-management.md)).
User-facing changes get a notice in English and Amharic: what changed, from
when, and where to get help. Knowledge is kept in the repository
(architecture, runbooks, API documents, onboarding, ADRs) so that no part of
the platform depends on one person.

## Existing policies this relies on

[../change-management-policy.md](../change-management-policy.md), [../patch-management-plan.md](../patch-management-plan.md), [../vulnerability-management.md](../vulnerability-management.md), [../operations-security-policy.md](../operations-security-policy.md), [../password-security-policy.md](../password-security-policy.md), [../incident-response-plan.md](../incident-response-plan.md), [../intelligence-ai-governance.md](../intelligence-ai-governance.md).
