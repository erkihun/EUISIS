# PostgreSQL at scale

Target: 3,000,000 employees, event tables growing to hundreds of millions of
rows. Related: [capacity plan](capacity-plan.md) · [load testing](load-testing.md)
· [risk register](production-risk-register.md) · [PITR runbook](runbooks/postgresql-pitr.md).

## Connection budget

Every PHP-FPM child and queue worker holds at most one connection while it runs.

```
connections = Σ(app nodes × pm.max_children) + Σ(queue workers) + scheduler (1–2)
            + admin/migration/backup tooling (~5) + replication slots
```

Example: 4 nodes × 40 FPM children + 12 workers + 2 + 5 = **179** direct connections.
PostgreSQL performance degrades well before a `max_connections` of several hundred,
so do not solve saturation by raising `max_connections`.

**Recommendation: PgBouncer in transaction-pooling mode** in front of the primary,
with a server pool sized to the database host (≈ 2–4 × CPU cores, e.g. 50–100), and
clients pointing at PgBouncer. Before enabling transaction pooling, verify in staging
(NEEDS_DECISION until done):

- set `PDO::ATTR_EMULATE_PREPARES => true` for the pgsql connection (server-side
  prepared statements do not survive transaction pooling);
- session state (`SET search_path`, `SET TIME ZONE`, advisory locks held across
  transactions) is not relied on — Laravel sets `search_path`/timezone per connection,
  which must be configured at the PgBouncer/role level instead;
- `php artisan migrate` runs directly against PostgreSQL, not through the pooler
  (concurrent index builds need a session).

Status: **not deployed — recommendation**. No pooler config is tracked in the repo.

## High availability and recovery

Streaming replica (standby) plus pgBackRest WAL archiving and PITR are described in
[backup-recovery-architecture](backup-recovery-architecture.md) and the
[go-live checklist](go-live-checklist.md) backup gates. **RPO and RTO are
NEEDS_DECISION** (business owner); the former 1h/4h figures were never approved.
Failover is a manual promotion until an orchestrator (e.g. Patroni) is chosen —
NEEDS_DECISION.

## Read replicas

Not used today (no read/write split in `config/database.php`). Candidates once
measured load justifies them: executive dashboards, historical reports, analytics
exports. Never route a write-then-read workflow (approve → redirect → show) to a
replica; use a separate `pgsql_reporting` connection only in report services.

## Indexes added in this hardening

`2026_10_10_300200_add_hot_path_indexes` (built `CONCURRENTLY IF NOT EXISTS`):

| Table | Index | Serves |
| --- | --- | --- |
| employee_assignments | `(employee_id, is_current)`, `(organization_id, is_current)`, `(position_id)` | scope joins, current placement, occupancy counts, FK deletes |
| id_cards | `(employee_id, is_current)` | self-service, approval, snapshots |
| employee_duplicate_flags, employee_documents | `(employee_id)` | employee list counts, dashboard |
| positions | `(organization_unit_id)` | unit filters |
| card_verifications | `(created_at)` | dashboard "today" |
| notifications | `(notifiable_type, notifiable_id, created_at)`, partial unread | inbox and badge |
| audit_logs | `(organization_id, created_at)`, `(actor_user_id)` | scoped audit viewer |
| daily_activity_reminders | `(activity_date, reminder_type, employee_id)` | reminder exclusion |
| employees | `(created_at)`, `(updated_at)`, GIN trigram on `full_name`, `name_en`, `employee_number` | sorts, partner sync, `ILIKE '%term%'` search |
| cafeteria_transactions | `(employee_id, transaction_date)`, `(cafeteria_provider_id, transaction_date)` | per-day scan check, provider lists (were missing on fresh installs) |
| service_transactions | `(occurred_at)` | dashboard |

Integrity (partial unique) indexes: one live current ID card, one current
assignment per employee, one transfer per vacancy application, one service
transaction per (provider, reference).

The trigram indexes need the `pg_trgm` extension. If the application role cannot
create extensions, a DBA runs `CREATE EXTENSION pg_trgm;` once, then re-runs the migration.

## Partitioning

Nothing is partitioned today. Partitioning is justified only for append-only,
date-queried event tables, by monthly RANGE on the event date:

| Table | Key | Why |
| --- | --- | --- |
| cafeteria_transactions (+ consumed_days, subsidy_ledger) | transaction_date / consumed_date | ~1 row per eligible employee per working day; every query has a date range |
| service_transactions | occurred_at | 1 per scan |
| daily_activity_logs (+ items/histories) | activity_date | 1 per employee per working day (~780M/year at 3M) |
| daily_activity_reminders | activity_date | queried for one date only; old partitions can be dropped |
| audit_logs | created_at | append-only; enables detach-and-archive retention |
| notifications, card_verifications, nfc_verification_logs, api_request_logs, security_events | created_at / occurred_at | append-only logs |

Not candidates: master tables (employees, positions, organizations) and
`field_work_location_events` (capped at two rows per participant).

Converting a live table is a planned migration: create the partitioned parent,
attach the existing table as the first partition (or copy in batches), and move
unique keys to include the partition key (e.g. `ctcd_active_entitlement_unique`).
**NEEDS_DECISION** — schedule after load tests confirm the per-table growth.

## Retention

Separate business/legal retention from online performance retention. Grievance,
discipline, audit and HR history are never deleted for performance. Online
retention periods for logs (audit online window, notifications, verification logs,
reminders, security events) are **NEEDS_DECISION**; until decided only
`api:prune-logs` (90 days) and `nfc:prune-challenges` run.

## Maintenance

- Keep autovacuum on. For the high-write tables (cafeteria_transactions,
  daily_activity_*, audit_logs, notifications, jobs, sessions, cache) lower
  `autovacuum_vacuum_scale_factor` (e.g. 0.02–0.05) per table once measured.
- `pg_stat_statements` on; review top total-time and mean-time queries weekly.
- Watch `pg_stat_user_tables` for `seq_scan` on large tables, dead tuples, and
  temp-file spills (`log_temp_files = 0` in staging).
- WAL volume tracks write volume (scans, daily activity, audit, notifications); size
  the pgBackRest repository and archive throughput from measured daily WAL.

## Large-table migration rules

1. Additive first: new nullable column / column with a constant default (metadata-only
   on PG 11+), new table.
2. Indexes `CONCURRENTLY` in a migration with `$withinTransaction = false`.
3. Foreign keys on big tables: add `NOT VALID`, then `VALIDATE CONSTRAINT` separately.
4. Backfills run as a resumable command or job, chunked by key, throttled, with
   progress — never one UPDATE over the table, never row-by-row inside a migration.
5. Switch reads/writes in code, deploy, then contract (drop the old column) in a later release.
6. Never `ALTER COLUMN … TYPE` on a large live table without a rewrite plan.
7. A new unique constraint checks for existing duplicates and stops with
   NEEDS_DATA_REVIEW; it never edits data.

Already-applied historical migrations that would lock a large table (non-concurrent
indexes, row-by-row backfills, column type changes) are listed under DB-8 in the risk
register; they have run on current databases and are not re-run.
