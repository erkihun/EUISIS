# Database capacity at 3,000,000 employees

Production Gate 2, measured 2026-10-20 on the synthetic benchmark database
`aaemployeedb_scale3m`. Dataset: [scale-baseline.md](scale-baseline.md).
Hardware: one Windows 11 workstation, 32 logical CPUs and 16 GB RAM, running
PostgreSQL 18.6 with **stock settings** (`shared_buffers` 128 MB, `work_mem` 4 MB,
`max_connections` 100). The application nodes and the load generator ran on the
same machine. A production database host with tuned memory settings will be
faster on the large aggregates. Nothing here is a production-hardware number.

Method: `loadtest/db/explain-hot-paths.php 5`, which runs EXPLAIN (ANALYZE, BUFFERS) on each hot
query with 5 different randomly sampled keys. Figures are the median of those runs, planning
plus execution, after VACUUM ANALYZE. The raw output is reproducible with the same command.

## Canonical lookups: no change needed

| Query (as the app issues it) | Median | Plan |
| --- | ---: | --- |
| employee by UUID (`employees.id`) | 0.27 ms | `employees_pkey` |
| employee by number (exact) | 0.27 ms | `employees_employee_number_unique` |
| card by public card UUID (QR) | 0.39–0.57 ms | `id_cards_public_card_uuid_unique` |
| card by id + token hash (card verify) | 0.26–0.33 ms | `id_cards_pkey` |
| active card of an employee | 0.10 ms | `id_cards_employee_idx` |
| current assignment of an employee | 0.26 ms | partial unique `employee_assignments_one_current_per_employee` |
| assignment → organization → unit | 0.29–0.41 ms | primary keys |
| position occupancy (current incumbents) | 0.24 ms | `ea_position_idx` |
| units of an organization (page + COUNT) | 0.19–0.33 ms | `organization_units_organization_id_index` |
| city employee list, page 1 (25 rows by number) | 0.09 ms | `employees_employee_number_unique` |
| keyset page anywhere in 3M (`number > cursor`) | 0.24–0.34 ms | `employees_employee_number_unique` |
| Amharic name search `%given father%` | 19–20 ms | `employees_full_name_trgm_idx` (Ge'ez trigrams work on this locale) |

**3M rows does not need partitioning, sharding or a different engine.** Every canonical
lookup is a sub-millisecond index probe.

## Fixes made in this gate (before → after, same queries, same data)

| Path | Before | After | Change | Risk |
| --- | ---: | ---: | --- | --- |
| `Entitlement::inForceFor` (every card verify, twice per service transaction, NFC) | **439 ms** seq scan of 3M entitlements | **0.41 ms** | index `entitlements (employee_id, service_type_id)` | +142 MB, one more index write per entitlement change |
| API code filter `employee_number=SC-…` (also org/unit/position codes) | **729 ms** ILIKE via trigram + COUNT | **0.29 ms** | case-insensitive *equality* `lower(col) = lower(?)` and index `lower(employee_number) text_pattern_ops`. Also `%`/`_` are no longer wildcards, so `employee_number=%` matched every row before. | +90 MB (employees), +95 MB (positions `lower(job_position_code)`). Semantics are now exact-match only, which the docs already promised. |
| Web registry search, name | **2,652 ms** seq scan: five-column OR with e-mail and phone unindexed | **33 ms** | search by term shape: names → `full_name`/`name_en` trigram; number/code → employee-number prefix; `@` → e-mail trigram; phone-like → phone trigram | +92 MB e-mail trigram (partial). A name no longer matches e-mail text. |
| Web registry search, employee number `SC-1234567` | **1,534 ms** | **0.55 ms** | prefix via `lower(employee_number) text_pattern_ops` | prefix, not substring, for codes |
| Web registry search, e-mail | seq scan (inside the OR) | **50 ms** | partial trigram index on `email` | as above |
| Service transactions of an employee | **782 ms** seq scan | **0.31 ms** | index `service_transactions (employee_id, occurred_at)` | +139 MB |
| Card verifications of a card | seq scan (table small here, grows with every scan) | index | index `card_verifications (id_card_id)` | small |
| Outbox claim `status='pending' ORDER BY id` | sort of all pending rows | partial index head | `integration_events (id) WHERE status='pending'` | tiny (pending rows only) |
| Web registry deep page (page 30,000 = OFFSET 1.5M) | **3.5–4.7 s** per page | not reachable | paging capped at page 200 (first 10,000 matches) with a "search or filter" notice | users must search beyond 10,000 rows |
| Dashboard, city scope, cache miss | **105 s** DB time | **57 s**, and served stale while one refresh runs | card/entitlement figures no longer join 3M employees + assignments when no scope applies; occupancy counted once per request; `Cache::flexible` (fresh 5 min, stale ≤ 30 min, one refresh under a 5-min lock) | figures may be up to 30 min old; the header shows the computation time |
| Situation Room panels, cache miss | every viewer at expiry recomputed (4–8 s per city-wide panel) | stale entry served, one refresh after the response; a cold miss computes once while concurrent viewers wait ≤ 5 s | `SituationCache` stale-while-revalidate + lock | data age shown, as before |
| Organization list deletion guard | `pluck` of every assignment row of the 25 listed organizations (~250k rows) | 25 EXISTS probes | `OrganizationDeletionGuard` | none: same reasons |

Index storage added: **≈ 558 MB on a 13 GB database (+4 %)**. Building them with
`CREATE INDEX CONCURRENTLY` on 3M rows took 2 min 2 s in total, without blocking writes
(migration `2026_10_24_100000_add_service_path_lookup_indexes`).

## Still slow, accepted for now (measured, not fixed)

| Query | Median | Why it is accepted / what would fix it |
| --- | ---: | --- |
| registry search on a single common given name (`%Bebi%`) | 1.2 s | Matches tens of thousands of rows, so the COUNT reads them all. A follow-up could show "10,000+" instead of an exact total. |
| employees of one organization, COUNT for `paginate()` (~10k each) | 0.33–0.6 s | API `/organizations/{id}/employees` and the scoped registry. A cached or estimated total would remove it (P2). |
| city employee COUNT for the API list (`status=active`) | 118 ms | Acceptable. |
| API deep pages (`?page=N`, offset paging) | grows linearly; OFFSET 1.5M = 3.5 s | Partners syncing everything should use the change feed or `updated_after`. A keyset `cursor` on the list endpoints is recommended before any partner pages through all 3M (**P1**, not implemented: it is an API contract change). |
| dashboard "duplicate active cards" check | 8.5 s | `GROUP BY employee_id` over 2.9M active/issued cards. A partial index on non-current live cards would make it instant (P2). |
| dashboard "employees by organization type" | 7.6 s | 3M-row join. Background-refreshed, see above. |
| Situation Room city headcount / occupants by position | 4.4 s / 7.7 s | 3M-row aggregates. Refreshed in the background. A summary table refreshed every few minutes is the next step if wall screens multiply (**materialized view justified at this point; not built**). |

## Connection budget

Laravel opens one PostgreSQL connection per PHP worker for the duration of a
request (no persistent connections, `config/database.php`). Queue workers and
the scheduler hold one each while running.

| Consumer | Connections |
| --- | --- |
| Web: nodes × PHP-FPM `pm.max_children` | N × C |
| Queue workers (default, notifications, long, integrations) | 1 each |
| Scheduler (`schedule:run`) and the sub-minute `integrations:*` runs | 1–3 |
| Admin / ad-hoc / backups / monitoring | reserve 10 |

**Measured peak under load:** see [load-test-results.md](load-test-results.md). At every
profile, concurrent connections stayed far below `max_connections`. The database
was not the first bottleneck; PHP workers were.

- Without PgBouncer: `max_connections` ≥ N × C + workers + 20.
  - Example: 4 nodes × 32 children + 8 workers ≈ 160, which exceeds the default 100.
- **PgBouncer recommendation:** in transaction-pooling mode once N × C exceeds about 80–100.
  - Each request holds its connection only briefly: median query time is under 1 ms, and the
    measured API median is 70–90 ms end to end, mostly PHP.
  - So a pool of 20–40 server connections serves hundreds of PHP workers.
- **Caveats with Laravel:**
  - Session-level state does not survive transaction pooling: `SET` statements, advisory
    locks, `LISTEN`/`NOTIFY`, and prepared statements on old PgBouncer (use ≥ 1.21 with
    `max_prepared_statements`).
  - The app uses none of these on the request path.
  - Migrations should connect directly, not through the pool, because
    `CREATE INDEX CONCURRENTLY` needs its own session.
  - **Status: not installed here, NEEDS_DEPLOYMENT_CONFIGURATION.**

## Timeouts and locks

- **No `statement_timeout`, `lock_timeout` or `idle_in_transaction_session_timeout` is set**
  anywhere, in either the server or the app.
- Recommended per role:
  - web/API role: `statement_timeout = 30s`, `idle_in_transaction_session_timeout = 60s`;
  - migration role: `lock_timeout = 5s`, so a migration waiting behind traffic fails fast and is
    retried instead of queueing every request behind its lock;
  - reporting/queue role: longer or unlimited statement time (the dashboard refresh needs about
    a minute today).
- **NEEDS_DEPLOYMENT_CONFIGURATION** (`ALTER ROLE … SET …`).
- **Measured lock behaviour:**
  - No deadlock in any race or load run.
  - Lock waits appear only where they are designed: card row (revoke vs. transaction),
    entitlement row (quota), position row (registration), transfer chain
    ([failure-testing.md](failure-testing.md)).
  - No transaction opens an HTTP call. Webhook delivery runs outside any database transaction.

## Write amplification

`employees` carries 22 indexes (1.45 GB of indexes for a 1.1 GB heap). Several
are low-selectivity single-column btrees with no measured reader:
`employees_status_index`, `employee_assignments_is_current_index`,
`id_cards_is_current_index`, `id_cards_reprint_required_index`, and the
duplicate `service_transactions_occurred_at_index` / `st_occurred_at_idx`.
They cost a write on every change. Recommend dropping them after a production
`pg_stat_user_indexes` review shows `idx_scan = 0` (**P2**; not dropped here
without production evidence).

Outbox overhead per write: see [queue-readiness.md](queue-readiness.md#outbox-write-overhead).

## Autovacuum and growth tables

- Stock autovacuum: 3 workers, `vacuum_scale_factor` 0.2. On the append-heavy tables
  (`audit_logs`, `cafeteria_transactions`, `service_transactions`, `card_verifications`,
  `api_request_logs`, `integration_events`, `webhook_deliveries`), 20 % of 10M+ rows means
  vacuum runs rarely and then in long passes.
- **Set per table:** `autovacuum_vacuum_scale_factor = 0.01`, `autovacuum_analyze_scale_factor =
  0.02` (or an insert threshold) for these tables, and raise `autovacuum_max_workers` to 5 on a
  production host.
- **Partitioning:** not needed for `employees` (3M rows). Monthly range partitioning becomes
  worthwhile for `audit_logs`, `cafeteria_transactions` and `service_transactions` at roughly
  50–100M rows. It makes retention a `DROP PARTITION` instead of a mass `DELETE`. That decision
  needs a retention policy first: **NEEDS_POLICY_DECISION_RETENTION**.

## Large-table migration rules (Addis One)

These rules come from the migration review in this gate. On 3M employees, a column rewrite took
32 min, a btree build 23–56 s and a GIN trigram build 23–31 s (both measured).

1. **Indexes:**
   - On PostgreSQL, build with `CREATE INDEX CONCURRENTLY IF NOT EXISTS` in a migration with
     `public $withinTransaction = false;`
   - Keep a plain index for MySQL and SQLite.
   - Pattern: `2026_10_10_300200` or `2026_10_24_100000`.
2. **New columns:**
   - Add them nullable (or with a constant default).
   - Backfill in a queued job or a chunked command (`chunkById`, throttled), never inside the
     migration.
   - Then add `NOT NULL` through a validated CHECK: `ADD CONSTRAINT … CHECK (col IS NOT NULL)
     NOT VALID; VALIDATE CONSTRAINT …;`, then `SET NOT NULL`.
3. **Foreign keys on big tables:** `ADD CONSTRAINT … NOT VALID`, then
   `VALIDATE CONSTRAINT` separately (no long write lock).
4. **Type changes (`->change()`, `ALTER … TYPE`):** these rewrite the table under an exclusive
   lock. Use add-new-column → backfill → switch reads → drop-old across releases instead.
5. **Lock timeout:** set `SET lock_timeout = '5s'` at the start of every migration that touches
   a large table, so it fails fast instead of blocking traffic.
6. **No Eloquent loops in migrations.** The historical examples flagged in this gate
   (`2026_07_13_111716`, `2026_09_23_110000`, `2026_05_12_120100`, `2026_10_11_100200`) already
   ran in development. They would take hours on a production-size register, so they must
   **never be replayed** against a full copy without being converted to chunked commands first.
7. **MySQL host:** STORED generated columns and `->change()` copy the whole table. Production is
   PostgreSQL (decision D-5), so MySQL-only paths matter only while the legacy host exists.
