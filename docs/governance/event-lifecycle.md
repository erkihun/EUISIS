# Event lifecycle

Rules for integration events (outbox, change feed, webhooks). Catalog and
envelope: [../integration/event-catalog.md](../integration/event-catalog.md),
[../integration/schemas/event-envelope.v1.json](../integration/schemas/event-envelope.v1.json).

## Adding an event type

- [ ] Name `aggregate.past_tense_verb`; aggregate type from the catalog list.
- [ ] Payload holds identifiers and the minimum needed; no national ID, phone, address or photo.
- [ ] Recorded in the same transaction as the change (outbox), never after it.
- [ ] Scope that may receive it decided; catalog row added.
- [ ] Can be switched off with `INTEGRATION_DISABLED_EVENT_TYPES` without a release.
- [ ] Feature test that it is recorded once per change and not on a no-op.

## Changing an event

Adding optional fields keeps `event_version`. Removing or renaming a field, or
changing its meaning, ships as a new `event_version`; the old version is
published beside it until every subscriber has moved (checked through webhook
subscriptions and feed consumers).

## Retiring an event

Mark it in the catalog and [deprecation-register.md](deprecation-register.md);
announce; stop publishing only after no subscription filters on it.

## Retention

Event and delivery retention is configuration (`INTEGRATION_EVENT_RETENTION_DAYS`, `INTEGRATION_DELIVERY_RETENTION_DAYS`) and is **not set**: legal retention periods have not been decided and are not invented here. Until they are, nothing is purged.
