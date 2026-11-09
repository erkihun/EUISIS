# Partner onboarding (event integration)

Audience: Addis One integration administrators onboarding a partner system.

## 1. Application and credentials

System Settings → API Management (see [partner-authentication.md](partner-authentication.md)):

1. Register the application; it starts **scoped** with no organizations.
2. Choose **organization access**: city-wide only for city-level systems; otherwise
   *scoped* with self or subtree grants.
3. Grant scopes — least privilege:
   - `changes.read` to pull the change feed;
   - the domain scope for each event family (`organizations.read`,
     `organization_units.read`, `positions.read`, `employees.basic_read`,
     `employee_assignments.read`, `id_cards.verify`, `organization_structure.read`);
   - `webhooks.manage` (read-only view of its own subscriptions and deliveries) and
     `webhooks.replay` (bounded self-service replay) only if needed.
4. Issue a token (shown once). No second credential system exists for events.

## 2. Webhook subscription (optional)

System Settings → Event Integration → New Subscription (`integrations.manage`):

1. Pick the application, a name, the HTTPS callback URL and the event types — only
   types its scopes allow can be chosen.
2. Save: the signing secret is shown **once**. Hand it to the partner through the
   agreed secure channel.
3. **Send Test Event**: a synthetic `webhook.test` (never a real business event)
   proves reachability, TLS and signature verification.
4. The subscription starts with events published after its creation. History comes
   from the change feed (bootstrap) or an explicit replay.

Partners cannot create subscriptions, change URLs or obtain secrets themselves.

## 3. Test environment

Use a non-production Addis One with `INTEGRATION_EVENTS_ENABLED=true`,
`INTEGRATION_CHANGE_FEED_ENABLED=true`, `INTEGRATION_WEBHOOKS_ENABLED=true`. For a
partner running on a private network in test only, add its host to
`INTEGRATION_WEBHOOK_INTERNAL_HOSTS`; never in production without a decision.

## 4. Production activation (phased)

1. **Shadow mode** — events on, feed and webhooks off. Check the Event Integration
   page: pending drains, no rejected events.
2. **Change feed** — enable; onboard the pilot partner (Cafeteria) on the feed:
   bootstrap from the watermark, then poll.
3. **Webhooks** — enable; add the pilot subscription (`id_card.revoked`,
   `id_card.status_changed`, `employee.status_changed`, `employee.transferred`,
   `assignment.closed`). Watch delivery health for a week.
4. Further partners (Transport reuses the same infrastructure — no separate webhook
   system).
5. Set the System Health limits `integration_outbox_oldest_minutes` and
   `integration_dead_deliveries` (blank = no alert; no SLA is invented).

Run workers for the `integrations` queue (`php artisan queue:work --queue=integrations`)
separately from the default queue so a failing partner never starves core jobs.

## 5. Secret rotation

Event Integration → subscription → Rotate Secret. The new secret is shown once; the
old one keeps signing for the overlap window (24 h default). Confirm with the partner
that both are accepted, then let the overlap expire.

## Open decisions

- NEEDS_POLICY_DECISION_EVENT_RETENTION — how long events and deliveries are kept.
- NEEDS_POLICY_DECISION_PARTNER_TRANSFER_EVENT_VISIBILITY — whether a source-only
  partner may learn a transfer's destination.
- NEEDS_POLICY_DECISION_INTEGRATION_REPLAY_ROLE — which role, besides Super Admin,
  may replay and inspect payloads.
