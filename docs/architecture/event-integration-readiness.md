# Event integration layer — readiness report

Date: 19 October 2026. Scope: the transactional outbox, change feed, signed webhooks,
replay, reconciliation, subscriptions, monitoring and administration added on top of
the hardened partner API ([master-backbone-remediation-implementation.md](master-backbone-remediation-implementation.md)).
Documentation: [`docs/integration/`](../integration/) — consumer guide, event catalog,
change feed, webhook security, transactional outbox, replay and reconciliation,
partner onboarding, OpenAPI and the envelope JSON Schema.

## 1. What exists now

| Capability | Implementation |
| --- | --- |
| Reliable domain events | `HasIntegrationEvents` on Organization, OrganizationUnit, Position, Employee, EmployeeAssignment, IdCard; `MasterDataEventProjector`; explicit `employee.transferred` and `hierarchy_version.published`. |
| Transactional outbox | `integration_events`, written in the domain transaction (saves outside a transaction are wrapped). Rolled-back change → no event (tested). |
| Ordered change feed | `feed_position` stamped by the publisher under the feed-state lock, commit-visibility order, gap-free; keyset `GET /api/v1/changes`. |
| Per-aggregate order | `sequence` per (aggregate type, UUID) under a row lock; unique index. |
| Tombstones | `change_kind: tombstone` for archived, inactive, closed, revoked, separated; soft deletes, never hard deletes. |
| Signed webhooks | HMAC-SHA256 `t.event_id.body`, `Addis-Signature`, replay window, rotation with overlap. |
| Retries / dead / history | Bounded backoff with jitter (8 attempts, 30 s → 1 h), permanent 4xx/3xx dead at once, `webhook_delivery_attempts` metadata. |
| Replay | New delivery generation for the same event; admin (`integrations.replay`) and partner self-service (`webhooks.replay`), audited. |
| Reconciliation | `POST /api/v1/changes/reconcile`, watermark, consumer-position report. |
| Subscriptions | Scope-bound event types, SSRF-checked HTTPS URL, encrypted secret shown once, enable/disable, synthetic test event. |
| Versioning | `event_type` + integer `event_version`; v1 catalog with whitelisted fields, validated at publish. |
| Idempotent consumer contract | Stable `event_id`, at-least-once documented; exactly-once not claimed. |
| Monitoring | Event Integration page (KPIs from real rows; UNKNOWN when unmeasurable); telemetry signals and the `event_integration` detection rule with configurable limits (blank = no alert). |
| Rollout | `INTEGRATION_EVENTS_ENABLED` / `_CHANGE_FEED_ENABLED` / `_WEBHOOKS_ENABLED`; shadow mode = events only. |

## 2. Measurements (local, not production)

Developer laptop (Windows), PostgreSQL 18.6, PHP 8.4, one process, inside a rolled-back
transaction; 20,000 synthetic events over 20 organizations; partner HTTP faked.

| Measure | Result |
| --- | --- |
| Organization save, events off → on | 1.6 ms → 2.8 ms per save (≈ 1.2 ms outbox overhead) |
| Publisher throughput (500-row batches, one 1,000-event subscription fan-out) | ≈ 1,185 events/s |
| Feed query, scoped partner, 100 rows, cursor mid-feed (p50 / p95 of 20) | 13.9 ms / 17.2 ms |
| Feed plan | Index scan on `(event_type, feed_position)`, 120 shared buffers, 11.8 ms execution |
| Pending-outbox and due-delivery lookups | Index-driven, < 5 ms on empty/near-empty queues |
| Delivery processing (fake 200 partner) | ≈ 4.0 ms per delivery including attempt and audit rows |

Before the publisher memoised organization grants per run it reached 573 events/s;
the per-run cache doubled it. These figures say nothing about 3 million employees
or production hardware; the 3M load gate remains open (F11).

## 3. Tests

`tests/Feature/Integration/` — 73 tests: outbox atomicity and rollback, minimisation
(no national ID / phone / names), separation tombstone, assignment sequence, card
revocation once and only once, position archive tombstone, organization events,
outbox switch, queue backend down, contract rejection, commit-order feed numbering
with a late-committing row, catalog integrity; feed authentication/scope/disabled,
full paging without duplicates, limit validation, tampered and borrowed cursors,
type filtering, organization isolation (scoped subtree, unreviewed), concurrent
inserts while paging, reconciliation after a missed webhook (verification API agrees),
watermark, transfer E2E (one transfer event, generic event suppressed, identities and
card UUID unchanged, source-only minimised view, destination full view); signature
verification (tamper, wrong secret, stale timestamp), signed revocation delivery,
retry classification (2xx/429/5xx/timeout/network/400/401/403/404/302), 500 → timeout
→ success, 401 dead at once, redirect never followed, exhaustion → dead → replay with
the original event id, duplicate delivery processed once, secret rotation with overlap
and encrypted storage, 13 SSRF destinations refused, allowlisted internal host,
unauthorised event types refused, scope loss → skipped, partner outage keeps the
master change, crashed worker lease recovered, per-aggregate delivery ordering,
partner IDOR and replay permission, shadow mode; admin permissions, secret shown once,
delivery history and payload permissions with audit, disable, synthetic test event,
real KPIs, detection rule silent without a limit and firing with one, retention never
purging pending events.

Full suite (parallel, 19 October 2026): 4,384 passed, 1 skipped, 4 failed in 32 min.
The 4 failures are `RichTextArchitectureTest` source scans that break on Windows path
separators; they fail the same way without this work.

## 4. P0 / P1 check

| Blocker | Status |
| --- | --- |
| Lost domain events | None found: outbox row in the domain transaction; publisher is transactional; tests. |
| Event for a rolled-back transaction | Tested: none. |
| Cross-organization leakage | Feed and deliveries filter by organization in SQL; transfer source view minimised; tests. |
| Unsigned webhook | Every delivery signed; tests verify with the reference verifier. |
| SSRF to internal addresses | Policy at save and send, IP pinning, no redirects; tests. Egress firewall remains an infrastructure control. |
| Secret exposure | Encrypted at rest, hidden from serialisation, shown once, never logged. |
| Replay bypass | Separate permission/scope, own subscriptions only, audited; tests. |
| Unauthorised change feed | Application principal + `changes.read` + domain scopes + bound; tests. |
| Raw sensitive employee data in events | Whitelisted payloads, publish-time contract check; tests. |
| Replay corrupting domain data | Replay touches only delivery rows. |
| No reliable retry / no history | Backoff, dead state, attempts table. |
| Feed skips events / broken cursor | Commit-order positions, signed cursor; tests. |
| Duplicate logical events under retry | Events come from committed state transitions; a repeated revoke emits nothing; deliveries unique per (subscription, event, generation). |
| Partner cannot recover | Feed from cursor, reconcile, replay. |
| No tombstone | `change_kind: tombstone`. |
| Pilot fails | Cafeteria verifies every scan live (no cached eligibility), so events are an addition, not a dependency; the feed and webhooks are ready for it. The cafeteria application does not yet consume events (next step, optional). |

## 5. Open items

- NEEDS_POLICY_DECISION_EVENT_RETENTION (nothing is purged until decided).
- NEEDS_POLICY_DECISION_PARTNER_TRANSFER_EVENT_VISIBILITY (source partners get the minimised variant).
- NEEDS_POLICY_DECISION_INTEGRATION_REPLAY_ROLE (replay and payload inspection granted to no role; Super Admin only).
- Multi-worker publisher/dispatcher concurrency was verified by design (feed-state row lock; conditional-update claims with leases) and single-process tests, not by a multi-process stress run.
- The `employee.updated` change categories cover partner-visible fields only; private-field changes intentionally emit nothing.
- 3M-scale load and failover remain the separate production gate (F11).

## 6. Verdict

The event integration layer is implemented with no unresolved P0 or P1 item in its
own scope, in shadow-mode-first, pilot-ready form.

**EVENT_INTEGRATION_READY_FOR_PILOT** — for the Cafeteria pilot on the change feed,
then webhooks, after the operational steps in [partner-onboarding.md](../integration/partner-onboarding.md).
Not a claim of 3M-employee capacity.
