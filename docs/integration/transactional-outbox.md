# Transactional outbox

Audience: engineers operating or extending the Addis One event integration layer.

## Flow

```
domain transaction ──► integration_events (pending)      same commit, or neither
        │
scheduler, every 10 s: integrations:publish  ──► feed_position stamped (change feed)
                                             └─► webhook_deliveries (one per subscription)
scheduler, every 10 s: integrations:deliver --queue ──► one queued job per subscription
                                                        └─► signed HTTPS POST, retries, dead, replay
```

No domain code calls a partner, a queue or the network. A partner outage, a slow
endpoint or a Redis outage cannot fail or lose a valid master-data change.

## Where events come from

| Source | Mechanism |
| --- | --- |
| `Organization`, `OrganizationUnit`, `Position`, `Employee`, `EmployeeAssignment`, `IdCard` | `HasIntegrationEvents` trait: the model observer `MasterDataEventProjector` records events from `created` / `updated` / `deleted` / `restored`. A save that is not already in a transaction is wrapped in one, so the outbox row is atomic with the change. |
| Transfers (`CompleteTransferAction`, `CompleteVacancyTransferAction`) | `TransferEvents` records one `employee.transferred` and suppresses the generic `employee.assignment_changed` for the same move. |
| Hierarchy publication (`PublishHierarchyVersionAction`) | One global `hierarchy_version.published`. |

Only real changes emit: a save that changes nothing, or only fields partners never
see (national ID, phone, address, date of birth, …), records nothing.
Query-builder writes (`Model::query()->update()`) bypass model events and emit
nothing — master-data code must use model saves. The only such write found on a
master table is `JobDescriptionService` stamping `job_description_id` on current
assignments, which is not part of the partner contract.

Hard deletes: none of the six master models is hard-deleted by application code
(Organization, OrganizationUnit and Position soft-delete; Employee, assignments and
cards change status). A soft delete becomes a tombstone event.

## Tables

| Table | Purpose |
| --- | --- |
| `integration_events` | Outbox and change feed. `event_id` (UUIDv7, unique), `event_type`, `event_version`, `aggregate_type`, `aggregate_uuid`, `sequence` (unique per aggregate), `organization_id` / `related_organization_id` / `is_global` (authorization columns, relational), `change_kind` (`upsert` / `tombstone`), `occurred_at`, `effective_at`, `correlation_id`, `payload` (JSONB, minimised), `metadata` (internal only), `status` (`pending` / `published` / `rejected`), `feed_position` (unique). |
| `integration_aggregate_versions` | Per-aggregate sequence counter, taken under a row lock. |
| `integration_feed_state` | One row: the last feed position handed out. Its row lock serialises publishers. |
| `integration_feed_consumers` | Last position each application read. |
| `webhook_subscriptions`, `webhook_deliveries`, `webhook_delivery_attempts`, `integration_replays` | Push delivery, history and audit. |

Indexes follow the hot queries: `(status, available_at, id)` for the publisher,
`(event_type, feed_position)`, `(organization_id, feed_position)`,
`(related_organization_id, feed_position)` for the feed, `(status, next_attempt_at)`
and `(webhook_subscription_id, aggregate_key, feed_position)` for delivery. JSONB is
not indexed; nothing authorises on payload content.

## Ordering

- **Per aggregate:** `sequence` is 1, 2, 3 … for each `(aggregate_type, aggregate_uuid)`,
  allocated in commit order under a row lock.
- **Feed:** `feed_position` is stamped by the publisher holding the
  `integration_feed_state` lock, only on rows already committed. A row whose
  transaction commits late gets a later position, never one below what a consumer
  has read. The feed is gap-free and append-only.
- **No global business-time order** across unrelated aggregates is claimed beyond the
  feed position.

## Multiple workers and crashes

- Publishers take turns on the feed-state lock; each row is positioned once. A crash
  before commit leaves rows pending.
- Deliveries are claimed by a conditional update with a lease (`locked_until`). A
  worker that dies leaves an expired lease; the delivery is retried
  (at-least-once).

## Contract validation

At publish time each payload is checked against `EventCatalog` (known type,
whitelisted keys only, no nested objects, identity present, ≤ 16 KB). A failing row
becomes `rejected` with the reason, is never delivered and is counted on the
Event Integration page.

## Rollout switches (`config/integration.php`)

| Env | Default | Effect |
| --- | --- | --- |
| `INTEGRATION_EVENTS_ENABLED` | `true` | Record outbox rows. With the other two off this is **shadow mode**: events are generated and published to the internal feed for validation; nobody outside sees them. |
| `INTEGRATION_CHANGE_FEED_ENABLED` | `false` | `GET /api/v1/changes` answers (404 `change_feed_disabled` otherwise). |
| `INTEGRATION_WEBHOOKS_ENABLED` | `false` | Deliveries are created and sent. |

## Retention

`integration.retention.events_days` / `deliveries_days` are **null**:
**NEEDS_POLICY_DECISION_EVENT_RETENTION** — nothing is purged. When configured,
`integrations:purge` (daily) deletes in chunks only finished deliveries older than
the window and published events older than the window that no delivery references.
Pending and rejected events are never purged.

## Partitioning

Not done. At the measured volume (see the readiness report) the indexes keep the
feed query in single-digit-to-low-double-digit milliseconds. Range partitioning by
`feed_position` is the documented future option once volume is measured to need it.
