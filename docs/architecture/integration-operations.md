# Integration operations

Day-to-day running of the Core integration platform. Partner-facing contracts
are in [../integration/](../integration/); this page is for operators.

## Moving parts

| Part | Runs as | Watch |
| --- | --- | --- |
| Outbox recording | inside each Core transaction (`integration_events`) | `INTEGRATION_EVENTS_ENABLED`, `INTEGRATION_DISABLED_EVENT_TYPES` |
| Publisher | scheduler, `integrations:publish` every 10 s, one server | events waiting and oldest age (Integration Center) |
| Webhook delivery | scheduler, `integrations:deliver --queue` every 10 s; queue `integrations` | failed and dead deliveries, degraded subscriptions |
| Retention | `integrations:purge` daily 03:25 | only when retention days are configured |
| Change feed | `/api/v1` change feed with cursors | partners' cursor lag (feed consumers) |
| Partner API | `/api/v1`, Sanctum tokens, scopes, organization bounds, rate limits | `api_request_logs`, Integration Center |

## Routine tasks

| Task | How |
| --- | --- |
| Register a partner | Core › Integration Center → API management; narrowest scopes; organization bounds; IP allow-list where possible; token with expiry ([../integration/partner-onboarding.md](../integration/partner-onboarding.md)) |
| Review access (monthly) | `php artisan api:access-review` |
| Rotate a webhook secret | subscription page; old secret stays valid for the overlap (`INTEGRATION_WEBHOOK_ROTATION_OVERLAP_HOURS`) |
| Replay events to a partner | Integration Center replay (bounded by `replay_max_events`) ([../integration/replay-and-reconciliation.md](../integration/replay-and-reconciliation.md)) |
| Switch off a misbehaving event type | add it to `INTEGRATION_DISABLED_EVENT_TYPES`, `config:cache` |
| Revoke a partner | remove the scope (immediate), revoke tokens, or disable the application |

## Incidents

| Symptom | First check |
| --- | --- |
| Events waiting keep rising | scheduler running? `integrations:publish` errors in the log? |
| Deliveries failing for one partner | receiver status and TLS; subscription marked degraded after 3 failures |
| Partner gets 403 | token scope vs application allowed scopes; organization bounds |
| Partner gets 429 | per-application rate limit |
| Partner reports missing changes | compare their change-feed cursor with the latest position; replay |

Never paste tokens or webhook secrets into tickets, chat or documents.
