# ADR 0001: Master identity and organization backbone

Status: accepted, 23 October 2026.

## Context

Every module of the platform (cafeteria, transport, recruitment, transfer,
EPMS, daily and field work, public feedback, grievance, discipline, Situation
Room, intelligence) needs to know who an employee is, where they work and
whether their card is valid. The application is one Laravel deployment and
database, and modules have read the master tables directly. Two modules wrote
assignments on their own, and backbone code called into transport and
communication jobs.

## Decision

Separate the authoritative Golden Path, Organization → organizational unit →
position → employee → assignment → ID card, from the service modules:

1. The backbone owns these master records and their lifecycle commands; no
   service module creates a shadow employee, organization, position,
   assignment or card identity.
2. Service modules depend on backbone contracts (in process) or the versioned
   API and events (out of process), and store only their own transactions plus
   UUID references and evidence snapshots.
3. The backbone does not depend on service modules. A ratchet test enforces the
   direction and the list of exceptions can only shrink.
4. Stay one deployment for now (Mode A). Design contracts so the backbone can
   be deployed on its own later (Mode B) without changing consumers.

## Reasons

- Data consistency: one authoritative record per employee, position and card.
- Stable identity: the employee UUID survives transfers, restructuring and card
  replacement; credentials have their own identity and history.
- Reuse: every module asks the same questions the same way.
- Security: least-privilege contracts and API scopes instead of table access;
  the card validity rule exists once.
- Service integration: external systems use the same API and events.
- Independent evolution: modules change without touching master data.
- Less duplication: no module keeps its own employee or organization copy.

## Why not microservices everywhere

The modules share one database, strong foreign keys and one team. Splitting
them now would add network calls, distributed transactions and operational
load (deployments, monitoring, versioning) without a scaling or ownership need
that requires it, and would risk a distributed monolith. The logical boundary,
contracts and events give most of the benefit now and keep extraction possible.

## Consequences

- New code in service modules reads the backbone through contracts; existing
  modules migrate one at a time.
- Backbone state changes happen only through backbone commands and actions.
- Foreign keys inside the one database stay. If the backbone is ever deployed
  separately, cross-boundary keys become UUID references kept consistent by
  the API and events.
- See [master-backbone.md](../master-backbone.md) and the
  [migration plan](../backbone-refactor-migration.md).
