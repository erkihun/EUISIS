# Performance Scale Plan

## Target

Support a dashboard experience that remains safe and responsive as the platform approaches 300,000+ employees and high verification/transaction volume.

## Current Controls

- Dashboard payloads use aggregate counts, grouped series, and bounded lists only.
- `recentActivity` is limited to 12 items.
- provider ranking and top charts are limited to 10 records.
- Dashboard figures are cached for 120 seconds per scope/filter/visible-sections combination and shared by users with the same scope (risk register DB-5); the header stays per user.
- Measured limits and sizing: [load-testing.md](load-testing.md), [capacity-plan.md](capacity-plan.md).
- Organization subtree resolution reuses closure paths instead of recursive runtime traversal.

## Query Strategy

- Employee, card, entitlement, transaction, verification, provider, and transfer metrics are computed with SQL aggregates.
- Organization scope filtering is applied through current assignment joins or scoped organization filters.
- Provider-only users are hard-limited to their authorized provider/service scope.
- Date filters constrain trend queries to bounded windows.

## Known Future Hardening

- Add cache invalidation hooks for high-sensitivity dashboards if the acceptable TTL becomes too coarse.
- Add dedicated reporting/materialized aggregate tables when transaction volume exceeds interactive query comfort.
- Add PostgreSQL query-plan review and partial-index validation against production-like data.
- Add scheduled executive snapshot exports instead of real-time heavyweight reporting.
