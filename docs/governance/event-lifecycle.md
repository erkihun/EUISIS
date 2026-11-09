# Event lifecycle

Rules for integration events (outbox, change feed, webhooks). Catalog and
envelope: [../integration/event-catalog.md](../integration/event-catalog.md),
[../integration/schemas/event-envelope.v1.json](../integration/schemas/event-envelope.v1.json).
The code catalog is `App\Services\Integration\Events\EventCatalog`; the
published contract partners built against is
`tests/Fixtures/contracts/event-catalog-v1.json`. Owner: Integration/API Owner.

## Catalog (event_version 1)

Producer: Core, through the transactional outbox (`IntegrationEventRecorder`,
`MasterDataEventProjector`), in the same database transaction as the change.
Authorized consumers: registered applications holding the scope, within their
organization bounds (change feed and webhook subscriptions). Ordering:
`sequence` 1, 2, 3 … per aggregate, allocated in commit order; no ordering
across aggregates. Delivery: at least once; consumers de-duplicate on
`event_id`. Retention: see below.

| Event types | Scope (who may receive) | PII class | Notes |
| --- | --- | --- | --- |
| `organization.created`, `.updated`, `.status_changed` | `organizations.read` | none (public-sector structure) | |
| `organization_unit.created`, `.updated`, `.moved`, `.status_changed` | `organization_units.read` | none | |
| `position.created`, `.updated`, `.status_changed`, `.archived` | `positions.read` | none | |
| `position.occupied`, `.vacated` | `positions.read` | none: never names the employee | seat taken or released |
| `hierarchy_version.published` | `organization_structure.read` | none | |
| `employee.created`, `.updated`, `.status_changed` | `employees.basic_read` | personal: employee UUID, number, status; `changed_fields` names categories, not values | no name, national ID, phone, address or photo |
| `assignment.created`, `.updated`, `.closed`, `employee.assignment_changed`, `employee.transferred` | `employee_assignments.read` | personal: placement of an identified employee | |
| `id_card.issued`, `.reprinted`, `.revoked`, `.status_changed`, `.activated`, `.suspended`, `.expired` | `id_cards.verify` | personal: card ↔ employee link and status | no card number or QR reference |

The exact payload keys per type are in the published contract file and in
[../integration/event-catalog.md](../integration/event-catalog.md).
`EventCatalog::validate` refuses any other key and any nested object at publish
time, so a full model can never leak into an event.

## Adding an event type

- [ ] Name `aggregate.past_tense_verb`; aggregate type from the catalog list.
- [ ] Payload holds identifiers and the minimum needed; no national ID, phone, address or photo.
- [ ] Recorded in the same transaction as the change (outbox), never after it.
- [ ] Scope that may receive it decided; catalog row and PII class added (table above).
- [ ] Added to `tests/Fixtures/contracts/event-catalog-v1.json` and the event catalog document in the same change (`EventCatalogContractTest` fails otherwise).
- [ ] Can be switched off with `INTEGRATION_DISABLED_EVENT_TYPES` without a release.
- [ ] Feature test that it is recorded once per change and not on a no-op.

## Changing an event

Adding an optional payload key keeps `event_version` (record it in the
contract file). Removing or renaming a key, changing its meaning, or changing
a type's aggregate, scope or tombstone rule is breaking: it ships as a new
`event_version`, and the old version is published beside it until every
subscriber has moved (checked through webhook subscriptions, feed consumers
and `api:consumers`). `EventCatalogContractTest` fails on any breaking edit
of a published type, so this cannot happen silently.

## Retiring an event

Mark it in the catalog and [deprecation-register.md](deprecation-register.md)
(date, replacement, consumers, deadline, removal date, owner); announce; stop
publishing only after no subscription filters on it.

## Retention

Outbox and change-feed events, webhook delivery logs and dead-lettered
deliveries are kept until `INTEGRATION_EVENT_RETENTION_DAYS` and
`INTEGRATION_DELIVERY_RETENTION_DAYS` are set, and they are **not set**: legal
retention periods have not been decided and are not invented here
(NEEDS_POLICY_DECISION_EVENT_RETENTION). Until they are, nothing is purged,
which also keeps every event available for consumer recovery and audit. They
are part of the data-sharing review in the [retention matrix](retention-matrix.md).
