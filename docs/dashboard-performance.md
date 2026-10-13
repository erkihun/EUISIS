# Dashboard Performance

Measured findings for the dashboard analytics path, the changes made, and what
remains expensive. Every number here was measured, not estimated; where a figure
is a projection it says so.

## How these numbers were produced

Measurements ran against an isolated clone of the development database
(`aaemployeedb_scaletest`, created `TEMPLATE aaemployeedb`) loaded with
**3,000,027 employees** — the city-wide design target. The development database
itself holds 27 employees, which is why it cannot be used to validate any of
this: a plan that looks fine on 27 rows tells you nothing about 3M.

- PostgreSQL 18.6, local, default `work_mem`, 2 parallel workers available.
- `employees` after load: **1336 MB** total relation size.
- `ANALYZE employees` run before measuring, so plans reflect real statistics.
- Timings are the median of warm-cache runs unless marked cold.

Reproduce with:

```bash
psql -d postgres -c "CREATE DATABASE aaemployeedb_scaletest TEMPLATE aaemployeedb;"
# then the generate_series insert in this document's history, and ANALYZE
```

The clone is disposable. Drop it when finished — it is not part of any
environment and must never be promoted.

## Baseline: what the dashboard queries cost at 3M employees

| Query | Plan chosen | Time |
| --- | --- | --- |
| `COUNT(*)` all employees | Parallel Seq Scan (2 workers) → Finalize Aggregate | 381 ms |
| `COUNT(*) WHERE status='active'` | Parallel Seq Scan (**index not used**) | 324 ms |
| `GROUP BY status` | Parallel Seq Scan → Partial HashAggregate | 383 ms |
| Trend window on `created_at` | **Bitmap Index Scan** on `employees_created_at_idx` | 66 ms |
| `employeesByAge` (CASE over `date_of_birth`) | Parallel Seq Scan | ~400 ms |

Two conclusions drive everything below.

**The `created_at` index works.** The trend queries filter to a bounded date
window and get a bitmap index scan, returning in 66 ms against a 1.3 GB table.
Date-bounded analytics are not the problem.

**`employees_status_index` cannot help a status count.** `status='active'`
matches ~88% of rows, so the planner correctly prefers a parallel sequential
scan over an index scan plus heap fetches. This is not a missing-index problem
and adding an index will not fix it — at that selectivity no btree index can.
The only lever is **how many times the table is scanned**.

## Change: one scan serves the employee KPIs and the status chart

### What was wrong

Three separate full scans of the largest table ran on every uncached dashboard
load:

1. `employeeQuery($scope)->count()` — registered employees (KPI)
2. `employeeQuery($scope)->where('status','active')->count()` — active (KPI)
3. `GROUP BY employees.status` in `DashboardChartService::employeesByStatus()` —
   the status chart

Items 1 and 2 are derivable from item 3. Worse, the chart's numbers were
computed independently from the KPI strip's, so the two could disagree on screen
while both claimed to be authoritative — the duplicated-metric-logic hazard.

### What changed

`DashboardMetricService::employeeStatusCounts()` resolves every bucket in one
grouped query, memoised per request and **keyed by scope**. The registered total
is `array_sum()` of the buckets; the active count is a key lookup; the chart
reads the same map.

| | Queries on `employees` | Measured time |
| --- | --- | --- |
| Before | 3 scans | 775 ms (two KPI scans alone) |
| After | **1 scan** | **216 ms** |

That is a ~560 ms reduction per uncached load on the KPI pair, plus the
eliminated third scan for the chart.

The pattern already existed for `id_cards` and `card_requests`
(`cardStatusCounts`, `cardRequestStatusCounts`); this extends it to the table
where it matters most. Scope keying is tested — see
`employee status buckets are keyed by scope and never leak across organizations`.

### Tests covering it

- `grouped employee counts match per-status counts and one scan serves both KPIs`
  — each bucket equals its independent `COUNT`, and the sum equals the total.
- `the employee status buckets are fetched once per scope` — asserts by **query
  count**, not timing, that three reads issue one query.
- `employee status buckets are keyed by scope and never leak across organizations`
  — warms the widest scope first (the ordering that would leak) and proves the
  narrower scope still gets its own number.

## Change: a flow delta no longer wears a stock comparison label

Not a performance fix, but it was found during this audit and it is a
correctness defect.

The "Active Employees" KPI shows a **stock** (every active employee). Its delta
was computed by `employeeTrendCount()`, which counts employees whose
`created_at` falls inside the selected period — a **flow**. Both were labelled
`vs previous period`.

At city scale the card read:

> Active Employees · 3,000,000 · ▲ +42 vs previous period

A reader cannot tell whether headcount grew by 42 or whether 42 people were
registered. The label now names the measure (`newly registered vs previous
period` / `አዲስ የተመዘገቡ ከቀድሞ ጊዜ ጋር ንፅፅር`), and each trend carries its own label
rather than inheriting a hardcoded one.

There is **no historical headcount snapshot** in the schema, so a true
stock-over-stock comparison is not available. Inventing one would require a
daily headcount rollup; that is recorded under Remaining work rather than built
speculatively.

No percentage is displayed, so the zero-previous-value division hazard does not
arise. If a percentage is ever added, the previous value must be checked for
zero first.

## Index review

Existing indexes on `employees` and `employee_assignments` already cover the
dashboard's filter and group columns:

- `employees_status_index` (`status`)
- `employees_created_at_idx` (`created_at`) — **used**, 66 ms trend windows
- `employees_current_assignment_id_index` (`current_assignment_id`)
- `employees_date_of_birth_index` (`date_of_birth`)
- `ea_organization_current_idx` (`organization_id, is_current`) — composite,
  serves scope joins
- `employee_assignments_one_current_per_employee` — partial unique on
  `is_current = true`

**No indexes were added.** The measured plans show the remaining cost is
whole-table aggregation at low selectivity, which an index cannot improve. Adding
one would grow the 1.3 GB table and slow writes while changing no plan. This is a
deliberate decision backed by the plans above, not an omission.

## Caching

Dashboard figures are already cached for 120 seconds per
scope/filter/visible-section combination and shared between users with the same
scope (risk register DB-5); the header stays per user. See
[performance-scale-plan.md](performance-scale-plan.md).

Cache keys include organization scope, so a city-wide result cannot be served to
an organization-scoped viewer. Proven by
`memoised status counts are keyed by scope and never leak across organizations`
and the employee equivalent.

The 120 s TTL means the figures are **near-real-time, not live**. The dashboard
header shows a refresh time so the reader is not misled about freshness.

## Remaining expensive analytics

Honest list of what is still slow at 3M, and why it was left alone.

1. **`employeesByAge` (~400 ms).** A `CASE` over `date_of_birth` cannot use the
   index because the buckets are computed expressions. Options would be a
   generated column plus index, or a summary table. Not done: the 120 s cache
   already absorbs it, and age distribution is not a metric that needs to be
   live. Revisit only if it shows up in production traces.

2. **Whole-table status aggregation (~216 ms).** Now one scan instead of three.
   Below this lies a summary table or materialized view, which would add refresh
   ownership, staleness semantics, and failure modes. **Not justified by these
   measurements** — 216 ms behind a 120 s cache is not the bottleneck. Revisit if
   p95 dashboard response regresses past ~1 s with the cache warm.

3. **No historical headcount series.** Trends are flows (arrivals per period),
   not stock-over-stock. A daily headcount rollup would enable true growth
   percentages. Deferred deliberately: it is new persisted state with its own
   backfill and correctness burden, and nothing currently displayed is wrong
   without it now that the labels are honest.

## Decisions explicitly not taken

- **No materialized views.** The measured cost does not justify the refresh,
  staleness, and failure-handling burden. Revisit with production traces.
- **No read replica routing.** Same reason; and the dashboard's cached figures
  are not consistency-critical reads that would benefit.
- **No new indexes.** See Index review.

These are reversible decisions recorded with their evidence, so a future change
can argue against the measurements rather than rediscover them.
