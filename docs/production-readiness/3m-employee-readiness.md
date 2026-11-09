# 3,000,000-employee readiness

Production Gate 2, 2026-10-20.

## Verdict: **PARTIAL**

| Question | Answer | Evidence |
| --- | --- | --- |
| Can the schema, indexes and queries hold 3M employees? | **Yes, measured** | 3,000,004 synthetic employees with full Organization → Unit → Position → Employee → Assignment → Card relationships (13 GB). Every canonical lookup takes 0.1–0.7 ms ([database-capacity.md](database-capacity.md)). |
| Do the hot paths stay fast at 3M? | **Yes, after the fixes in this gate** | Entitlement check 439 → 0.4 ms; API code filters 729 → 0.3 ms; registry search 2.7 s → 33 ms; employee-number search 1.5 s → 0.6 ms; deep paging capped. |
| Is correctness preserved under concurrency at 3M? | **Yes, measured** | No duplicate current assignment, double occupancy, duplicate transfer, duplicate idempotent transaction or deadlock in any race. One P0 (revoked card accepted) was found, fixed and re-tested over 25 rounds ([failure-testing.md](failure-testing.md)). |
| Does the platform survive load at 3M? | **Yes, on the rig, up to its knee** | 15-min soak at 40 req/s: p95 215 ms, 0.04 % unexpected, no leaks. Saturation is PHP workers (80–100 req/s on 16 single-threaded workers); the database stayed under 10 active backends ([load-test-results.md](load-test-results.md)). |
| Is throughput sufficient for the expected usage? | **Not measured** | No usage forecast exists. At the rig's floor rates, the EXPECTED scenario needs about 130 PHP workers plus PgBouncer ([capacity-model.md](capacity-model.md)). Not run on production-class hosts. |
| HA, PITR, restore of production backups | **Not ready** | No standby, no WAL archive, and no production backup has ever been restored. The drill here proved a logical dump/restore of the 3M benchmark only ([backup-pitr-restore.md](backup-pitr-restore.md)). |

**Why PARTIAL and not MEASURED_READY:** the dataset scale was measured end to end, and it
passes. The capacity to serve real 3M-employee *traffic* was measured only on a shared
workstation with `php -S`, not on the production topology. The DR and HA prerequisites do not
exist yet.

**Why not DESIGN_READY_NOT_MEASURED:** the 3M dataset *was* measured; this is not a paper
assessment.

## 3M checks requested by the gate

| Check | Result |
| --- | --- |
| 3M database snapshot | 13 GB total. `employees` 2.6 GB (1.1 GB heap + 1.45 GB indexes); `id_cards` 2.1 GB; `employee_assignments` 1.2 GB; `positions` 1.1 GB ([scale-baseline.md](scale-baseline.md)) |
| 3M lookup | employee by UUID 0.27 ms; by number 0.27 ms; card by QR UUID 0.4–0.6 ms; current assignment 0.26 ms |
| 3M pagination | Page 1: 0.09 ms. OFFSET 1.5M: 3.5–4.7 s, so web paging is now capped at page 200. Keyset anywhere in 3M: 0.24–0.34 ms (recommended for the partner list API, P1). |
| 3M search | Amharic name 19 ms; English given + father name 33 ms; employee number 0.55 ms; e-mail 50 ms; one common given name 1.2 s (accepted, P2) |
| 3M API mix | 20/50/80 req/s within targets (p95 198/237/443 ms); 15-min soak at 40 req/s p95 215 ms |
| 3M event test | outbox ~480 events/s; change feed ~3,000 events/s served; webhooks 104–175 deliveries/s; partner outage recovered with 0 loss and 0 duplicates |
| Employee list never loads all | Web: `paginate(50)`, capped at page 200. API: `per_page` ≤ 100, change feed `limit` ≤ 500. No `Employee::all()` on any request path (audit). |
| Exports | Capped at 20,000 rows (cafeteria, daily activity) or queued (Situation Room, public feedback). The organization-design export is queued. All `app/Exports` build arrays in memory, but within those caps (P2: stream them). |

## Not partitioned, not sharded, no microservices

- **No partitioning of `employees`:** 3M rows is small for PostgreSQL with the right indexes.
- **Partitioning applies to append tables only:** audit, transactions and logs, once retention
  is decided ([database-capacity.md](database-capacity.md)).
- **No new infrastructure introduced:** no read replica, materialized view, Octane or Horizon.
  Each was assessed against the measurements and was not justified yet.
