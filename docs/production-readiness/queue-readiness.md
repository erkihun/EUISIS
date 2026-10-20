# Queue, scheduler and integration-event readiness

Production Gate 2, 2026-10-20. All measurements were taken on the 3M benchmark database:
PostgreSQL `database` queue driver, local synthetic webhook receiver
(`loadtest/rig/webhook-receiver.mjs`). No partner endpoint was contacted.

## Queue inventory

| Queue | Jobs | Longest `$timeout` | Notes |
| --- | --- | --- | --- |
| `default` | announcement target counts, channel/employee membership syncs, public-feedback / Situation Room / organization-design exports, design import batches, queued notifications (daily activity, field work, transfer announcements) | 900 s (`ProcessDesignImportBatch`) | exports and imports share this queue with operational work, see isolation below |
| `notifications` | `DeliverAnnouncement`, 9 queued notifications | 900 s (`DeliverAnnouncement`) | |
| `long` | assessment snapshots and generation, grievance escalation, plan score recalculation, transfer audience notification, incident explanation | 600 s | |
| `integrations` | `DeliverSubscriptionWebhooks`, one job per subscription, unique for 300 s | 300 s | **No worker for it was documented.** Added to the runbook, load-balancing guide and go-live checklist in this gate. |

- **Connection setting:** `retry_after` is 960 s, above every job timeout (enforced by
  `production:readiness`).
- **Failed jobs:** the `database-uuids` driver stores them in `failed_jobs`. Retry with
  `queue:retry`. Nothing is dropped silently.
- **Horizon:** absent. It needs Redis queues, and the database queue has no need for it at the
  measured volume.

## Isolation and worker budget

- **Isolation:**
  - Heavy work (exports, imports, assessment generation, announcement fan-out) runs on `default`,
    `long` and `notifications`. Webhooks have their own queue.
  - Run each queue as its own supervised pool, as in [../load-balancing.md](../load-balancing.md),
    so a 15-minute export cannot block a notification or a webhook.
  - Exports (`ExportPublicFeedbackReport`, `ExportSituationRoomReport`,
    `ExportOrganizationDesignReport`) and `ProcessDesignImportBatch` sit on `default`. At city
    scale, move them to a separate `exports` pool (**P2**).
- **Worker sizing:**
  - Each worker holds one PostgreSQL connection and about 40–70 MB of PHP memory (measured per
    request, [capacity-model.md](capacity-model.md)).
  - Start with 2 × default, 2 × notifications, 1 × long and 1–2 × integrations: 6–7 connections.
  - Webhook throughput scales with integrations workers only until the dispatcher contends on
    the same due rows (see below).

## Scheduler safety

- **All 23 scheduled tasks** use `withoutOverlapping()->onOneServer()`, so a second scheduler
  host is harmless. The lock lives in the shared cache.
- **Fixed in this gate:**
  - The 10-second `integrations:publish` / `integrations:deliver` tasks used the default
    24-hour overlap mutex. One crashed run would have stopped publishing for a day.
  - They now use `withoutOverlapping(5)`.
  - Overlap is harmless here anyway: the publisher serializes on a row lock and the dispatcher
    claims rows atomically.
- **Sub-minute tasks** need `schedule:run` from cron every minute, or `schedule:work`.

## Outbox write overhead

`loadtest/integration/outbox-bench.php write-overhead 1000`: 1,000 single-row employee updates
through Eloquent, each in its own transaction, at 3M employees.

| | p50 | p95 | mean |
| --- | ---: | ---: | ---: |
| outbox on (records `employee.updated`) | 3.76 ms | 4.72 ms | 3.94 ms |
| outbox off | 2.87 ms | 4.34 ms | 2.97 ms |
| **added by the outbox** | **+0.9 ms (+31 %)** | +0.4 ms | +1.0 ms |

The event insert and the per-aggregate sequence row lock commit in the same transaction as the
change. A business write can never commit without its event, and an event can never be
published for a rolled-back write.

## Publisher throughput

`loadtest/integration/outbox-bench.php backlog` creates the backlog: Eloquent updates in
500-row transactions, **229–233 writes/s** from one process. Two webhook subscriptions were
active, so every event also created two delivery rows.

| Run | Events | Time | Throughput | Notes |
| --- | ---: | ---: | ---: | --- |
| 1 publisher (in process) | 101,057 | 209 s | **484 events/s** (+968 deliveries/s) | 204 batches of 500; slowest batch 1.16 s |
| 4 concurrent `integrations:publish` | 30,000 | 64 s | **469 events/s** total | 6,500 + 9,000 + 7,000 + 7,500 = exactly 30,000; no event published twice, no gap |

The publisher is **safe but not scalable by adding processes**: it holds a single-row lock on
`integration_feed_state` for each batch, which is what makes feed positions gap-free and ordered.

- **Measured ceiling:** about 480 events/s, roughly 41M events/day.
- **Headroom:** every scenario in [capacity-model.md](capacity-model.md) fits under that.
  A bulk import of 100k changes takes about 3.5 minutes to reach the feed.
- **Claim query** (`status='pending' ORDER BY id LIMIT 500`) with 101k pending:
  - 0.38 ms with the new partial index `integration_events_pending_id_idx`;
  - 65 ms as a sort over every pending row without it, growing linearly with the backlog.

## Change feed

The `change_feed` endpoint was run alone at 30 requests/s for 60 s by 24 synthetic clients,
each following its own cursor through 131k events (100 per page).

| Requests | Events served/s | p50 | p95 | p99 | Errors |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1,800 | ~3,000 | 74 ms | 184 ms | 223 ms | 0 |

- Pages are about 61 KB of JSON each, uncompressed on this rig. Enable gzip at the proxy.
- The cursor lookup is an index range scan on `integration_events_feed_position_unique`.

## Webhook delivery

| Dispatchers | Delivered | Time | Rate | Duplicates at receiver |
| ---: | ---: | ---: | ---: | ---: |
| 1 | 12,600 | 120 s | **104/s** | 0 (per delivery id) |
| 4 concurrent | 21,021 | 120 s | **175/s** total | 0 |

- **Why it's slow:** delivery is sequential HTTP per dispatcher (10 s timeout, 3 s connect),
  outside any database transaction.
- **Why more dispatchers help less than linearly:** they compete for the same oldest due rows,
  and the claim is an optimistic compare-and-set.
- **Practical rate:** for one slow partner (200–500 ms per call), expect 2–5 deliveries/s per
  subscription per worker.
- **Fan-out gap:** the dispatcher is the bottleneck for high event volume × many subscriptions.
  At 484 events/s × 2 subscriptions, delivery (175/s) falls behind publishing. A bulk change of
  100k events takes about 20 minutes to drain to two subscribers. Acceptable for master-data
  sync; partners needing faster bulk sync should use the change feed (3,000 events/s measured).

## Failure behaviour

See [failure-testing.md](failure-testing.md): queue-worker kill, partner outage, database outage.
