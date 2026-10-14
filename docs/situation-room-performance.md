# Executive Situation Room — Performance

## Design rules

* **Scope first.** Every query is confined to the viewer's organizations
  (`SituationScope::apply`, `WHERE organization_id IN (...)` or no filter for a
  city-wide viewer) before it groups or counts. Nothing is counted and filtered
  afterwards.
* **Aggregate in PostgreSQL.** `COUNT`, `SUM(CASE ...)`, `GROUP BY`; the largest
  result set is one row per organization in scope. No employee, submission or
  log row is ever loaded into PHP.
* **One current assignment per employee** is guaranteed by the unique partial
  index `employee_assignments_one_current_per_employee`, so people are counted
  with `COUNT(*)` over current assignments instead of `COUNT(DISTINCT)`.
* **Shared figures once per request.** `SituationMemo` (scoped singleton,
  flushed when a request builds its context) holds the published hierarchy
  version, the roll-up map, capacity, headcount and workforce distributions.
* **Short-lived cache** (`cache_seconds`, default 300) keyed by scope, filters
  and detail grants; failed panels are never cached; every view shows when it
  was computed.
* **Progressive loading.** The page frame paints first; figures are an
  Inertia deferred prop; the chart library is lazy-loaded.
* **Top-N** (`top_n`, default 10) with an "Other" bucket for long tails;
  employee tables paginated (25).

## Index added

`esf_org_type_submitted_idx (organization_id, type, submitted_at)` on
`employee_service_feedback` — scope, then type, then period. Existing indexes
used: `dal_org_date_idx`, `ea_organization_current_idx`,
`employee_assignments_one_current_per_employee`, `organization_closure_unique`.

## Measurements

PostgreSQL 16, scratch database with synthetic volume: 422 organizations
(Bureau → 11 subcities × 10 woredas, 300 institutions), 20,000 positions,
120,000 employees with current assignments, 400,000 public submissions over
13 months, 600,000 daily activity logs. Every category computed **uncached**
in one PHP process (`perf-measure.php`), period = this month.

City-wide viewer — all 16 categories: **7105 ms → 2588 ms**
(year: 9159 → 5341 ms).

| Category | Before | After | Queries before → after |
| --- | ---: | ---: | --- |
| overview | 788 ms | 234 ms | 22 → 19 |
| workforce | 2326 ms | 519 ms | 55 → 8 |
| structure | 536 ms | 14 ms | 53 → 7 |
| service_delivery | 69 ms | 42 ms | 8 → 8 |
| complaints | 255 ms | 177 ms | 27 → 13 |
| feedback | 250 ms | 182 ms | 29 → 15 |
| tip_offs | 232 ms | 176 ms | 29 → 15 |
| daily_operations | 594 ms | 251 ms | 27 → 11 |
| performance | 3 ms | 4 ms | 6 → 6 |
| movement | 49 ms | 6 ms | 22 → 8 |
| field_work | 31 ms | 7 ms | 20 → 13 |
| cafeteria | 33 ms | 10 ms | 19 → 12 |
| cases | 63 ms | 15 ms | 27 → 13 |
| critical_attention | 19 ms | 17 ms | 0 → 0 |
| trends | 1020 ms | 470 ms | 65 → 21 |
| data_quality | 835 ms | 466 ms | 17 → 14 |

Subcity viewer (11 organizations) — all 16 categories: **1400 ms → 879 ms**
(year: 1943 → 965 ms).

| Category | Before | After | Queries before → after |
| --- | ---: | ---: | --- |
| overview | 120 ms | 104 ms | 21 → 18 |
| workforce | 387 ms | 240 ms | 49 → 8 |
| structure | 132 ms | 8 ms | 47 → 7 |
| service_delivery | 5 ms | 10 ms | 8 → 8 |
| complaints | 53 ms | 44 ms | 25 → 13 |
| feedback | 45 ms | 32 ms | 27 → 15 |
| tip_offs | 35 ms | 30 ms | 27 → 15 |
| daily_operations | 65 ms | 21 ms | 25 → 11 |
| performance | 2 ms | 2 ms | 6 → 6 |
| movement | 17 ms | 8 ms | 22 → 10 |
| field_work | 14 ms | 13 ms | 19 → 13 |
| cafeteria | 13 ms | 18 ms | 18 → 12 |
| cases | 25 ms | 20 ms | 25 → 13 |
| critical_attention | 19 ms | 16 ms | 0 → 0 |
| trends | 199 ms | 95 ms | 59 → 21 |
| data_quality | 267 ms | 216 ms | 17 → 14 |

A viewer opens one category at a time; the slowest single category is
≈ 0.5 s city-wide uncached, and repeat views within `cache_seconds` are served
from cache. Performance, Movement, Field Work, Cafeteria and Cases were
measured with little data in those tables; their queries use the same
scope-first pattern on indexed organization columns.

### Query plans (subcity scope, EXPLAIN ANALYZE)

| Query | Plan | Time |
| --- | --- | ---: |
| Complaints received per organization this month | Bitmap Index Scan on `esf_org_type_submitted_idx` | 0.8 ms |
| People reporting per organization (daily register) | Bitmap Index Scan on `dal_org_date_idx` | 3.9 ms |
| Headcount per organization | Bitmap Index Scan on `ea_organization_current_idx` | ≤ 29 ms |

## Optimisations made after measuring

* Workforce and Structure recomputed capacity, headcount and the roll-up per
  panel (55 and 53 queries); memoised per request → 8 and 7 queries,
  2.3 s → 0.5 s and 536 ms → 14 ms city-wide.
* `COUNT(DISTINCT employee_id)` over current assignments replaced by `COUNT(*)`
  (identical by the unique index).
* Headcount no longer joins `employees` (foreign key guarantees the person).

## Watch in production

* City-wide twelve-month trends (≈ 0.5–1 s uncached): keep `cache_seconds`
  ≥ 300 for wall displays.
* If submissions grow past several million, consider a daily summary table
  for the twelve-month trends; not needed at the measured volume.
