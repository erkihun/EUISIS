# Concurrency and failure testing

Production Gate 2, 2026-10-19/20, on the isolated 3M benchmark database. No
shared or production infrastructure was disrupted. Every failure was injected
only into the benchmark database and its rig processes.

## Concurrency races (`loadtest/concurrency/race.php`)

- **Method:** N separate PHP processes each boot Laravel, prepare, wait for a common start
  instant (±1 ms), and then call the **real application action** at the same moment against
  PostgreSQL. Afterwards the harness checks the database invariant.
- **Re-run:** `DB_DATABASE=aaemployeedb_scale3m php loadtest/concurrency/race.php run <scenario> <workers> <rounds>`.

| # | Scenario (prompt item) | Workers × rounds | Outcome | Invariant check | Violations |
| --- | --- | --- | --- | --- | ---: |
| 71 | Two+ requests create the current assignment for one employee (`EmployeeAssignment::create`, current closed first) | 8 × 3 | 1 succeeds per round; 7 get `UniqueConstraintViolation` on `employee_assignments_one_current_per_employee` | exactly 1 current assignment; employee pointer → current | **0** |
| 73 | New people placed in one single-slot position at once (`RegisterEmployeeAction`) | 8 × 3 | 1 succeeds; 7 get "position no longer available" (position row `FOR UPDATE` + `PositionCapacityService`) | ≤ 1 incumbent | **0** |
| 72 | The same approved transfer implemented by 8 clicks at once (`CompleteTransferAction`) | 8 × 3 | All return *transferred* (idempotent re-entry under the application row lock) | exactly 1 new current assignment, 1 `employee_transfers` row | **0** |
| 72 | Two approved transfers of one employee to two posts at once | 2 × 3 | 1 succeeds; the other: "assignment changed after approval" | 1 current assignment, 1 transferred | **0** |
| 73 | Two employees transferred into one single-slot post at once | 2 × 3 | 1 succeeds; the other: "no available approved capacity" | 1 occupant | **0** |
| 74 | Card revoked while 11 service transactions are recorded on it (`RevokeCardAction` vs `RecordServiceTransactionAction`) | 12 × 5 (**before fix**) | 1 transaction committed **after** the revocation had committed | accepted-after-revoke = **1** | **1 (P0)** |
| 74 | same, **after fix** | 12 × 5, then 12 × 20 | transactions accepted before the revoke committed; all later ones refused `id_card_revoked` | accepted-after-revoke = 0 | **0** |
| 75 | 8 identical `POST /api/v1/services/cafeteria/transactions` with one `Idempotency-Key`, through the real HTTP kernel | 8 × 3 | 1 recorded; 7 get `409 idempotency_in_progress` | exactly 1 service transaction | **0** |
| 76 | Deadlocks across all the above | — | `pg_stat_database.deadlocks` delta | — | **0** |

### P0 found and fixed: a revoked card could still be accepted

- **The race:** `RecordServiceTransactionAction` checked eligibility (card active, not revoked)
  *before* its transaction and without a lock. A revocation committed between that check and the
  insert was ignored.
- **Reproduced on the old code:** 1 of 5 rounds recorded a meal on a card whose revocation had
  already committed. Commit order was measured in microseconds from each process's own
  `TransactionCommitted` event; the tables only store seconds.
- **Fix:**
  - The action now locks the card row (`SELECT … FOR UPDATE`) inside its transaction and
    re-checks status, revocation and expiry.
  - `RevokeCardAction` now takes the same row lock inside a transaction.
  - The two are therefore strictly ordered: a transaction that holds the lock first completes
    before the revocation; one arriving after the revocation is refused.
  - Lock order is card, then entitlement, and nothing locks them the other way round, so the
    fix cannot introduce a deadlock (none observed in 25 rounds).
- **Cost:** one row lock per card scan, which only contends on the same card.

### Also fixed in the same area

- **Idempotency key replayed across services:**
  - The key was scoped to the route *pattern* (`/services/{serviceType}/transactions`), so one
    key used for cafeteria and then transport replayed the cafeteria response.
  - The concrete path is now part of the request hash, so the second request is `409
    idempotency_key_reused`.
  - Effect on deploy: keys stored within the 24 h TTL before the deploy no longer match. A
    replay of such a request gets 409 instead of the stored response.
- **Announcement retry re-notified people:**
  - `DeliverAnnouncement` (`tries=3`) restarted from the first recipient after a timeout. People
    already notified, emailed or texted got it again.
  - It now checkpoints the last delivered user id in the shared cache after every chunk and
    resumes after it.

## Failure injection

| # | Failure | How it was injected (benchmark only) | Observed | Verdict |
| --- | --- | --- | --- | --- |
| 92 | **Web node loss**: 4 of 16 nodes killed during 40 req/s, restarted 21 s later | `Stop-Process` on the node processes behind `loadtest/rig/lb.mjs` (health probe every 2 s, 2 failures to eject) | 4,800 requests. **1 failed**, a `POST` in flight on a killed node (502). 3 GETs on refused connections were retried on another node. p95 198 ms, unchanged. The restarted nodes rejoined on their first passing probe. | **PASS.** Stateless nodes; no session affinity needed. An LB that retries only idempotent requests loses only in-flight writes. Partners retry those with `Idempotency-Key`, which is safe. |
| 94 | **Database unavailable** for 31 s during 20 req/s | `ALTER DATABASE … ALLOW_CONNECTIONS false` + `pg_terminate_backend` on the benchmark database only; then `ALLOW_CONNECTIONS true` | Failures confined exactly to 04:01:38–04:02:09: 84 × 500 (in the ~4 s before the LB ejected the nodes), then 555 × 503 "no healthy backend", answered immediately, not hanging. `/health/ready` → 503, `/health/live` → 200 (no restart storm). Full recovery within 2 s of the database returning. Integrity checks afterwards: all 0. | **PASS** for correctness and recovery. Expected unavailability: the database is the platform's single point of failure until a standby exists. |
| 93 | **Queue worker killed** while webhook jobs were queued | `Stop-Process` on `queue:work --queue=integrations`, restarted 17 s later; scheduler loop (`integrations:deliver --queue` every 10 s) kept running | 2 jobs waited in `jobs`. The restarted worker processed them and every later one. 0 failed jobs, 0 lost deliveries, 0 duplicate deliveries at the receiver. Delivery resumed at the same rate. | **PASS** (kill *between* jobs). A kill *during* a job was not caught in the window, and the job is short. That path relies on `retry_after` (960 s) plus the 120 s delivery lease, so a crashed job's deliveries are re-claimed by the next dispatch: **design-verified, not measured**. |
| 97 | **Partner outage**: webhook receiver answers 503 | receiver `POST /__mode/down` for 54 s, then `ok` | During the outage, 1,000 employee changes **all committed** (190 writes/s) and all 1,000 events were published to the feed. Deliveries became `retrying` (3,500 failed attempts). The subscriptions stayed `active` with `consecutive_failures` = 1,750. After recovery, every retrying delivery succeeded within 60 s: 0 `retrying`, 0 `dead`, failures reset to 0, 0 duplicates at the receiver. | **PASS.** The core transaction never depends on the partner; the outbox persists; retries recover. A real outage longer than 8 attempts (about 2 h with the default backoff) marks deliveries `dead`. They are replayable (`webhooks.replay`), and the change feed keeps every event. |
| 95 | Redis failure | not possible here | Redis is not on any request path in the shipped configuration ([redis-readiness.md](redis-readiness.md)). | **N/A (not measured).** |
| 96 | Storage failure | `/health/ready` test: the private storage root is not a writable directory | readiness → 503 `storage: fail` (test added). Upload and export behaviour on a failing disk was **not** exercised: a read-only or unmounted volume could not be simulated safely on this machine. | **PARTIAL:** readiness verified by test; upload/export failure behaviour **not measured**. |

## Observability during failures

- The LB contract worked: readiness drains, liveness never restarts.
- **System Health caveat:** `ComponentHealth` reports database, queue and integrations as
  *healthy* whenever a telemetry sample exists. Error rate, queue age and connection percentage
  are not compared with any threshold; they degrade only through a detection incident. During
  a database outage the collector cannot write at all, so the page shows stale or unknown,
  never a false green. Thresholds are recommended in [capacity-model.md](capacity-model.md#alerts)
  (**P2**).

## Runbooks

### Database unavailable

1. `/health/ready` returns 503 on every node and the LB drains them. Do **not** restart app
   nodes; liveness stays 200.
2. Check the PostgreSQL primary (host up? disk full? `pg_isready`). With a standby, promote it
   per the HA runbook (**NEEDS_DEPLOYMENT_CONFIGURATION_POSTGRES_HA**). Without one, restore per
   [backup-pitr-restore.md](backup-pitr-restore.md).
3. When it is back, nodes rejoin on their next probe. Queued jobs retry by themselves. The outbox
   resumes on the next `integrations:publish` (every 10 s); no event is lost, because events
   commit with the data.
4. Afterwards, run `loadtest/db/integrity-checks.sql` (read-only) against production to confirm.

### Redis unavailable (only if Redis is adopted)

- **Cache store on Redis:** pages and API return 500 and readiness fails `cache`. Either restore
  Redis or set `CACHE_STORE=database` and run `php artisan config:cache` on every node. Rate
  limits restart from zero.
- **Sessions on Redis:** users are signed out; they sign in again once it is back.

### Queue backlog

1. Find the oldest job and its queue: `SELECT queue, count(*), min(available_at) FROM jobs GROUP
   BY queue`. Also check System Health → queue.
2. If `failed_jobs` is growing, look at the exception, fix, then `php artisan queue:retry all`.
3. If it is only volume, add workers *to that queue's pool*. Each worker is one more database
   connection, so check the connection budget.
4. Webhooks: `integrations:deliver` is capped at 50 deliveries per subscription per 10 s tick
   (`INTEGRATION_WEBHOOK_PER_SUBSCRIPTION_BATCH`), so a 100k-event bulk change takes hours per
   subscriber. Raise the batch temporarily, or point the partner at the change feed.

### Web node failure

The LB ejects it after two failed readiness probes. Replace the node: it holds no state. If
several fail together, check the shared dependencies first (database, cache, storage).

### Storage failure

- Readiness fails `storage` on the affected nodes, and the LB drains them.
- Upload and export behaviour on a failing disk was not tested in this gate: verify it on
  staging.
- With object storage, check the bucket and its credentials. With a mounted volume, check the
  mount on every node, because an unmounted path can silently become a local directory.

### Partner webhook outage

- Nothing to do for the core system. Deliveries retry with backoff for 8 attempts and then
  become `dead`.
- When the partner is back:
  - `POST /api/v1/webhook-subscriptions/{id}/replay` (scope `webhooks.replay`) re-sends from a
    feed position;
  - or the partner reads `/api/v1/changes` from its last cursor.
