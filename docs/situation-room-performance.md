# Situation Room — performance

## How the work is done

- **All aggregation happens in SQL.** Each section is one to ten grouped
  queries (`COUNT` and `SUM(CASE …)`). No row is loaded into PHP to be counted.
- **Scope is applied first.** It is an `organization_id IN (…)` filter plus a
  closure subquery, so a scoped leader's queries use the `organization_id`
  indexes.
- **No N+1.** Names (institutions, categories, employees) are fetched once
  per section with `whereIn`.
- **Progressive loading.**
  - The KPIs and the trend arrive with the page.
  - Comparison and attention are one deferred group.
  - Breakdowns and supporting cards are another.
- **Caching.**
  - Each section is cached for 120 seconds (Wall: 300 seconds) per scope
    signature, filters and section.
  - A warm hit is 0.1 ms with zero queries (measured).
  - Refresh drops the user's own keys only.

## Measured

**Setup:**

- Synthetic scale database `aaemployeedb_scale` on PostgreSQL 18.6, local
  Windows machine.
- 22,347 public submissions, 240 institutions, 220,000 employees.
- Services called directly, cold cache each run, median of 3 runs. Scripts
  are in the session scratchpad (`sr-measure.php`, `sr-scoped.php`).

This is the data size that was measured. **No claim is made for 3 million
employees or for a production volume of submissions.**

### Cold timings, last 30 days

| Section | City-wide (unrestricted) | Busiest institution | Scoped leader (one institution) |
|---|---|---|---|
| Headline (KPIs + trend) | 28.5 ms · 3 queries | 8.1 ms · 3 | 6.7 ms · 3 |
| Comparison + attention + summary | 56.5 ms · 10 | 25.9 ms · 10 | 21.8 ms · 10 |
| Breakdowns | 25.4 ms · 6 | 14.6 ms · 6 | 11.5 ms · 6 |
| Supporting indicators | 204 ms · 8 | 16.1 ms · 9 | 5.7 ms · 2 |
| Wall | 56.4 ms · 8 | 16.5 ms · 8 | 13.6 ms · 8 |
| Employee-targeted page | 24.6 ms · 3 | 5.3 ms · 3 | — |

### Cold timings, this year

| Section | City-wide | Busiest institution | Scoped leader |
|---|---|---|---|
| Headline | 30.2 ms | 8.9 ms | 6.5 ms |
| Comparison | 65.8 ms | 27.6 ms | 21.6 ms |
| Breakdowns | 42.4 ms | 15.3 ms | 12.1 ms |
| Supporting | 240 ms | 17.3 ms | 5.6 ms |
| Wall | 138 ms | 19.2 ms | 14.9 ms |

### Payload size (JSON)

- Headline: 1–2.4 KB.
- Breakdowns: up to 5.1 KB.
- Wall: up to 5.2 KB.

### Query plans

City-wide KPI query, this year:

- A sequential scan of the 22k-row table: 8.3 ms.
- This is the expected plan when nearly every row matches.

Scoped KPI query:

- A bitmap index scan on `organization_id`.
- 0.14 ms for 119 rows.

## What will grow

| Area | Cost now | At larger volumes |
|---|---|---|
| City-wide sections | Sequential scan over the period | Grows linearly with submissions. Around a million rows in the period, a partial index or summary table per day and institution would be the next step. Not built, because it is not needed at the measured size. |
| Supporting: workforce | Most of the 200–240 ms. Employee and position counts over 220k employees. | Uses the dashboard's own queries. It is cached, deferred and inside a collapsed section. |
| Institution search | `LOWER(name) LIKE` limited to 20 rows | Fine at 240 institutions |

## Not measured

- Concurrent load (k6). The public feedback load rig exists
  (`docs/public-feedback-load-testing.md`) but was not run against these
  pages.
- MySQL / MariaDB timings. Only the schema was checked there; the queries
  avoid PostgreSQL-only SQL (`SqlDateBucket` covers pgsql, mysql/mariadb and
  sqlite).
