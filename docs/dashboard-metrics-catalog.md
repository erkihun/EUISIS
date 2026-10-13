# Dashboard Metrics Catalog

One canonical definition per metric. The purpose of this file is to stop the same
metric being computed two different ways on two different screens — if a number
appears on a dashboard, a report and an export, all three read the same service
method documented here.

**Before adding a metric, check whether it is already below.** If it is, reuse
the method. If your variant differs, change the canonical definition rather than
adding a second one.

## Where the logic lives

| Layer | Responsibility |
| --- | --- |
| `DashboardScopeService` | Resolves organization scope, date window, previous window, Top-N limits |
| `DashboardMetricService` | KPIs, section cards, workflow queues, alerts, and the scoped query builders every metric starts from |
| `DashboardChartService` | Chart series, all via `DashboardMetricService` query builders |
| `DashboardDataService` | Caching and payload assembly |

No analytics SQL in controllers or React. Charts receive small typed payloads
(`{key, value}` / `{label, value}`), never Eloquent models.

## Scope and freshness (applies to every metric)

**Scope.** Every metric starts from a scoped query builder
(`employeeQuery`, `cardQuery`, `transactionQuery`, …). Scope is a `WHERE`/`JOIN`
in SQL **before** aggregation. A city-wide figure is never computed and then
filtered in PHP — that would be both a security and a performance defect.

Users with `Super Admin` or `City Admin` get global access; others are limited to
their assigned organizations. A scoped user with no organizations gets
`whereRaw('1 = 0')` — an explicit empty result, never an unscoped one.

**Freshness.** All figures below are cached **120 seconds**, keyed by resolved
scope + visible sections, and shared between users with the same scope (risk
register DB-5). The header's refresh time is per user. So every metric is
**near-real-time**, not live, and the dashboard says so.

Cache keys include organization scope, so an organization-scoped viewer can never
be served a city-wide cache entry. Tested in
`employee status buckets are keyed by scope and never leak across organizations`.

**Owner.** Platform / HRIS team for all entries unless stated.

## Employee metrics

### activeEmployees — KPI
- **Means:** Employees currently in `active` status, in scope.
- **Source:** `employees.status`
- **Calculation:** `employeeStatusCount($scope, 'active')` — a key lookup into
  the memoised grouped query, **not** its own `COUNT`.
- **Stock or flow:** **Stock.** Point-in-time, not date-filtered.
- **Trend:** Newly *registered* in the period vs the previous period — a
  **flow**, labelled `newlyRegisteredVsPrevious`. It does not measure growth of
  the total; there is no headcount snapshot to support that.
- **Drill-down:** `employees.index`
- **Freshness:** 120 s cache.

### registeredEmployees — KPI
- **Means:** All employees in scope regardless of status.
- **Calculation:** `employeeTotalCount($scope)` — `array_sum()` of the memoised
  buckets. **Not** a separate `COUNT(*)`.
- **Stock or flow:** Stock.
- **Trend:** Same flow as above.
- **Drill-down:** `employees.index`

> **Canonical rule.** `activeEmployees` and `registeredEmployees` both derive
> from `employeeStatusCounts()`. One grouped scan serves both plus the
> `employeesByStatus` chart — measured 775 ms → 216 ms at 3M rows. Do not
> reintroduce a standalone employee `COUNT`; see
> [dashboard-performance.md](dashboard-performance.md).

### employeesByStatus — chart (donut)
- **Calculation:** `employeeStatusCounts($scope)`, the same map as the KPIs, so
  the chart and the strip cannot disagree.

### employeesByAge — chart (bar)
- **Means:** Age distribution in fixed bands.
- **Source:** `employees.date_of_birth`
- **Calculation:** `CASE` over the birth date into `under_20`, `20_29`, `30_39`,
  `40_49`, `50_59`, `60_plus`, `unknown`; `GROUP BY` the expression.
- **Null handling:** A missing or future birth date is **`unknown`**, never 0 and
  never silently a band.
- **Cost:** ~400 ms at 3M (no usable index — the bands are computed). Accepted
  behind the cache; see Remaining expensive analytics in the performance doc.

### employeesBySex / employeesByEmploymentType / employeesByOrganizationType — charts
- **Calculation:** `COALESCE(NULLIF(column, ''), 'unknown')`, `GROUP BY`,
  ordered by count.
- **Null handling:** Blank and null both become **`unknown`**, kept as a visible
  category rather than dropped — a distribution that hides its gaps misleads.

### employeeRegistrationsTrend — chart (line)
- **Means:** Employees registered per day over the window.
- **Source:** `employees.created_at`
- **Calculation:** Grouped by date (`CAST(... AS date)` on Postgres).
- **Stock or flow:** **Flow.**
- **Cost:** ~66 ms at 3M — uses `employees_created_at_idx`.

### dataQualityWarnings — KPI
- **Means:** Employee records flagged as possible duplicates.
- **Source:** `employee_duplicate_flags`
- **Tone:** Warning, but neutral at zero (no data quality problem is good news).

## Position metrics

### positionsByOccupancy — chart
- **Means:** Approved positions split by whether they are filled.
- **Caution:** Occupancy is derived from current assignments. Do not present a
  vacancy figure as an approved-headcount gap unless the approved establishment
  is the source — they are different numbers.

### positionsByGradeLevel / positionsByJobFamily / positionsByOrganization — charts
- **Calculation:** Grouped counts over scoped positions.
- **Top-N:** `positionsByOrganization` is limited to `top_limit` (10). With many
  organizations, a full list belongs in a report, not a chart axis.

## Organization metrics

### organizations / organizationUnits — KPIs
- **Calculation:** Scoped `COUNT`. Bounded by scope (21 organizations today), so
  these do not need consolidation.

### organizationsByType / organizationsByStatus — charts
- **Calculation:** Grouped counts.

## Card and verification metrics

### cardsByStatus / cardRequestsByStatus — charts
- **Calculation:** `cardStatusCounts()` / `cardRequestStatusCounts()` — memoised
  grouped queries, scope-keyed, reused by the KPIs, the funnel and the queues.
- **Zero handling:** A status with no rows is a **real 0**, not a missing key.

### cardLifecycleFunnel — chart
- **Calculation:** Summed buckets from the memoised request map; the first step
  equals the total by construction (asserted in tests).

### pendingCardRequests / pendingApprovals — KPIs
- **Means:** Items awaiting action.
- **Tone:** Neutral at zero.
- **Drill-down:** The filtered queue.

### verificationAllowedDenied / denialReasons / verificationTrend — charts
- **Source:** `card_verifications`
- **Top-N:** `denialReasons` limited to `top_limit`.

## Transaction and provider metrics

### serviceTransactionsTrend / transactionsByServiceType / transactionsByStatus — charts
- **Source:** `service_transactions`
- **Scope:** Applied via an `employee_id` **subquery**, deliberately not a join
  on employees + assignments — that saved two joins per transaction for global
  and provider dashboards (load test 2026-10-11).

### providersTopUsage — chart (Top-N ranking)
- **Top-N:** `top_limit` (10).
- **Note:** This is a Top-N of a larger set. If the remainder is ever material
  to a total being shown, add an explicit **Other** bucket rather than letting
  the ranking imply it is the whole.

### providersByServiceType / entitlementsByServiceType / entitlementsByStatus — charts
- **Calculation:** Grouped counts over scoped rows.

## Transfer metrics

### transfersByStatus / transferAging — charts
- **Source:** `employee_transfers`
- **Aging:** Buckets by age of the pending request — an exception view, meant to
  surface what is stuck.

## Audit

### auditEventDistribution — chart
- **Source:** Audit log, gated behind the audit permission (`reports.view` alone
  is **not** sufficient — the feed names actors and actions).
- **Note:** Audited page views are excluded from the activity feed but retained
  in the log.

## Rules for new metrics

1. **One definition.** Add it here, implement it once in
   `DashboardMetricService`, and reuse it everywhere.
2. **Scope in SQL** before aggregating. Never filter a global result in PHP.
3. **Aggregate in Postgres.** No `Model::all()` into PHP for counting.
4. **Say stock or flow,** and never label a flow as if it were a change in a
   stock.
5. **Bound it.** Top-N for rankings, date windows for trends, no unbounded
   category axis.
6. **Zero is not missing.** Render a real 0; use the empty state for no data;
   keep `unknown` visible.
7. **Reuse a memoised grouped query** when several places want buckets of the
   same table.
8. **Do not invent a target.** Show progress only against a configured one.
9. **Never rank employees by complaint volume.** Public feedback counts are not
   a performance measure.

## Out of scope

**Transport Management.** Transport dashboards, services and routes exist and are
permission-gated in this codebase, but Transport is stated as outside current
production scope. No new Transport analytics are added, and the existing ones are
left untouched. This contradiction between the stated scope and the shipped code
should be reconciled by the product owner — flagged, not resolved here.
