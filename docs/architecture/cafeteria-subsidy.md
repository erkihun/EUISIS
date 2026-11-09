# Cafeteria Subsidy Architecture

Provider scope is centralized in `CafeteriaProviderAccessService`.

- `accessibleProviderIds(User $user)` returns an empty array for all-provider users and provider IDs for operators.
- `canAccessProvider()` guards explicit provider access.
- `filterProviderScopedQuery()` applies provider filters to transaction, dashboard, and report queries.

Calendar metadata is centralized in `CafeteriaCalendarService`. The scan page consumes backend day states instead of deriving cafeteria rules in React. Calendar states include available, consumed, closed, public holiday, special open/closed/no-subsidy day, employee leave, weekend state, and past unclaimable state.

Replay prevention is implemented on cafeteria transactions with `scan_nonce`, `scan_request_hash`, and `fulfilled_at`. The scan request hash stores a hash of the QR reference, provider, usage mode, and scan timestamp; the raw QR token is never logged or stored by the cafeteria module.

Reversals mark consumed-day rows with `reversed_at` and `reversed_by` through the reversal action.
