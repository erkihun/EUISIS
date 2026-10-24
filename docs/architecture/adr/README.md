# Architecture decision records

Every significant architectural decision is written down here, so that no
rule of the platform is implicit. ADRs live in this folder (not a separate
`docs/adr/`) because the four accepted decisions already do.

## When an ADR is required

- A change to who owns or may write Golden Path data (organization, unit, position, employee, assignment, credential).
- A new or breaking API version, event version, or integration method.
- A change to authentication, authorization, organization scope or card verification.
- A new data store, a schema change that is not additive, or a retention decision.
- Anything that reverses or narrows an earlier ADR.

Small, reversible changes inside one module do not need one; the pull request is enough.

## Process

1. Copy the template below to `NNNN-short-title.md` (next number).
2. Status `proposed`; open a pull request. Reviewers: the owners named in [`.github/CODEOWNERS`](../../../.github/CODEOWNERS) for the affected paths, plus the System Owner and, for security or data changes, the Information Security Lead or Data Owner ([roles register](../../governance/roles-register.md)).
3. On approval set `accepted, <date>, decided by <role/name>`. Never invent an approval: if the deciding role is not named yet, the ADR says so and stays `proposed`.
4. An accepted ADR is not edited in place. A later ADR supersedes it, and the old one gets `superseded by NNNN`.

## Index

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-master-identity-and-organization-backbone.md) | Master identity and organization backbone (Core owns the Golden Path) | accepted 2026-10-23 |
| [0002](0002-core-write-authority-assignment-ending-and-sign-in.md) | Core write authority, ending an assignment, Core sign-in | accepted 2026-10-24 |
| [0003](0003-security-hardening-after-core-stages.md) | Security hardening: explicit organization scope, audit seals, provider-named card checks | accepted 2026-10-24 |
| [0004](0004-go-live-gates-recovery-targets-retention-and-runtime.md) | Go-live gates, recovery targets, retention, governance roles, Node 24 | accepted 2026-10-24 |

Decisions that are recorded elsewhere and would each merit an ADR when next
changed: API versioning ([api-lifecycle](../../governance/api-lifecycle.md)),
event delivery through the transactional outbox ([transactional-outbox](../../integration/transactional-outbox.md)),
card verification by signed public reference (decision D-3), PostgreSQL as the
production engine (decision D-5, while the live host runs MySQL — see
[technical debt](../../governance/technical-debt.md) TD-19).

## Template

```markdown
# ADR NNNN: <title>

Status: proposed | accepted <date>, decided by <role/name> | superseded by NNNN

## Context
What forces the decision; evidence (files, measurements, incidents).

## Decision
What is decided, stated as rules.

## Alternatives considered
Each option and why it was not chosen.

## Consequences
What becomes easier, harder, or forbidden.

## Migration impact
Data and code changes, expand → migrate → verify → contract steps, compatibility window.

## Security impact
Access, scope, PII, secrets, audit.

## Operational impact
Deployment, monitoring, backup/DR, runbooks to update.
```
