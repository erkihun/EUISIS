# Dashboard Architecture

## Goals

- Provide a role-aware, organization-scoped executive overview without leaking out-of-scope counts.
- Return aggregate data only for heavy datasets.
- Keep the controller thin and move query logic into dedicated dashboard services.
- Avoid PII in dashboard props.

## Service Split

- `DashboardController`
  - validates filters
  - enforces `dashboard.view` or mapped read access
  - returns one Inertia payload
- `DashboardDataService`
  - orchestrates section permissions, caching, and payload assembly
- `DashboardScopeService`
  - resolves date ranges, accessible organization scope, and provider/service scope
- `DashboardMetricService`
  - computes KPI cards, workflow queues, alerts, and recent activity
- `DashboardChartService`
  - computes chart-specific aggregate series

## Scope Rules

- Super Admin and City Admin can resolve citywide scope.
- Scoped administrative users resolve only their accessible organization subtree.
- Provider users resolve provider/service aggregates only.
- Dashboard sections are hidden unless the user has the mapped permission set.

## Returned Data Shape

- `filters`
  - selected date range and organization selector options
- `can`
  - per-section visibility booleans
- `kpis`
  - small array of cards with value, trend, icon, and tone
- `cards`
  - section helper metrics for progress bars and summary panels
- `charts`
  - keyed arrays for distributions, trends, rankings, and lifecycle funnels
- `workflowQueues`
  - bounded actionable queue counts with links
- `alerts`
  - bounded alert list with severity and links
- `recentActivity`
  - latest safe audit summaries only

## No-PII Rule

- The dashboard does not return employee phone, date of birth, salary, QR token, token hash, photo path, signature path, or document paths.
- Audit summaries show event and subject-type context only.
- Organization and provider names are allowed because they are operational entities, not personal identifiers.

## Performance Approach

- Aggregate queries only for large datasets
- top-N limits for provider and chart rankings
- bounded recent activity list
- short TTL caching keyed by user, scope, and filters
- no full employee, card, or transaction collections returned to the frontend
