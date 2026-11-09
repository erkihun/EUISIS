# Replay and reconciliation

Audience: integration administrators and partner engineers.

## Replay

Replay re-delivers already published events to one subscription. It never creates a
business event: it adds a new delivery *generation* for the same outbox row, so the
partner receives the **original** `event_id`, `sequence` and payload and
de-duplicates as usual.

| Who | How | Limits |
| --- | --- | --- |
| Administrator with `integrations.replay` | System Settings → Event Integration → subscription → Replay | Reason required; dead/skipped deliveries, a time range, or event IDs; at most 5,000 events per request. |
| The partner itself, scope `webhooks.replay` | `POST /api/v1/webhook-subscriptions/{id}/replay` | Own subscriptions only (others are 404); reason required; same criteria; 10 requests per minute. |

Only events the subscription may still see (type subscribed, scope held, inside the
organization bound) are replayed. Every replay is stored in `integration_replays`
(who, subscription, criteria, reason, count) and audited as
`integration.replay_requested`.

Ordinary Organization Admins have no replay right. Replay is granted to no role by
default (**NEEDS_POLICY_DECISION_INTEGRATION_REPLAY_ROLE**).

## Reconciliation

A partner that missed webhooks (outage, dead deliveries) recovers without replay:

1. Read the change feed from its last stored cursor (`GET /api/v1/changes`). This
   alone restores a correct state; it is the primary recovery path.
2. To check specific records, `POST /api/v1/changes/reconcile` with up to 100 UUIDs
   of one aggregate type: compare the returned `sequence` with yours.
3. Where you are behind, fetch the current record from the resource API.

Administrators see, on the Event Integration page, each application's last feed
position and how far behind the current position it is, the failed and dead
deliveries, and each subscription's health (healthy / degraded / failing / unknown,
from real delivery results only — one transient failure never marks a partner down).

## Partner off-boarding

- Suspending or revoking the application blocks its feed access and stops all its
  deliveries at the next attempt (they become `skipped`).
- Disabling one subscription stops only that subscription's deliveries.
- History (`webhook_deliveries`, attempts, replays, audit) is kept in both cases.
