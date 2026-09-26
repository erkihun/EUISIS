# Cafeteria entitlement rules

Part of the [cafeteria policy architecture](cafeteria-policy-architecture.md). Implemented by `CafeteriaEntitlementService`.

## Two gates, both must pass

1. **The cafeteria can serve now** (operational, per location): active and open (`operational_status`), within opening hours if set, and open on the date by its physical calendar — cafeteria or global special days, system day rules, weekend defaults, holiday scan mode. Denials: `cafeteria_closed`, `cafeteria_temporarily_closed`, `cafeteria_outside_opening_hours`, `cafeteria_closed_weekend`.
2. **The employee is entitled** (the employee organization's policy):
   - the weekday is enabled in the policy (`not_entitlement_day` otherwise) — Organization A Mon–Fri and Organization B Mon–Sat eating in the same cafeteria each follow their own policy;
   - `exclude_public_holidays` + a public holiday (`public_holidays`, the existing calendar) → not entitled (`cafeteria_closed_holiday`);
   - a special *no-subsidy* day → not entitled; a special *subsidy* day entitles regardless of weekday;
   - `block_employee_leave` + an active leave/exclusion (`employee_cafeteria_exclusions`, the authoritative leave record today) → `employee_on_leave`.

A non-entitlement day may still be served **employee-paid** when the system's weekend/holiday/leave scan mode says so; no entitlement is consumed.

## One entitlement, every location

The entitlement ledger is `cafeteria_transaction_consumed_days`. Its uniqueness boundary is

```
active_key = employee | entitlement_date | entitlement_type | slot
```

with a **UNIQUE index** — deliberately without the cafeteria. A reversal clears the key and frees the entitlement everywhere. (A nullable unique key works on MySQL, PostgreSQL and SQLite alike; MySQL has no partial indexes.)

A Monday meal at a branch consumes Monday. A later scan at the main cafeteria, another branch or a service point finds Monday used and follows the policy's **extra-scan policy**:

| `extra_scan_policy` | second use of a day |
|---|---|
| `block` (default, EUISIS baseline) | refused: `already_scanned_today` (a scan today) / `entitlement_already_consumed` (used ahead earlier) |
| `deduct_next_available` | consumes the next unused day of the current window |
| `employee_paid` | served; the employee pays the full provider price; nothing consumed |

`max_daily_uses` > 1 adds slots per date (breakfast + lunch, for instance); each slot is one entitlement.

## Advance use

With `allow_advance_usage`, a "use remaining week" scan consumes the service day and the rest of the current window (`week_start_day`…`week_end_day`, default Monday–Friday), capped by `advance_max_days` when set:

- Monday → the remaining weekdays; Tuesday → fewer; … Friday → Friday only;
- past unused days are never claimable; next week is never borrowed;
- leave days are excluded when the policy blocks leave and `exclude_leave_days_from_subsidy` is on;
- days already used anywhere are skipped.

All dates of one scan are claimed together in one transaction: if any is taken, **none** is kept.

## Concurrency and idempotency

- The scan locks the ID card and employee rows first, serializing every scanner working on that employee at any branch.
- The claims are inserted inside a savepoint; the UNIQUE `active_key` makes the database refuse a second claim even if two scanners checked availability at the same moment. The loser gets `entitlement_already_consumed` and keeps nothing (no partial consumption, on any engine).
- `scan_nonce` and `scan_request_hash` are unique: a scanner retry is answered with the original transaction (`scan_request_already_processed`), never a second one. NFC nonces are namespaced by terminal. A retry that races the original is answered the same way.

## Transaction flow

Credential → employee → card and employee eligibility → assignment on the service date → employee organization → cafeteria → provider → network → access → service assignment → policy → cafeteria availability → working day, holiday, leave → entitlement dates → lock and claim across all branches → price from the policy → transaction + claims + subsidy ledger + snapshot → audit.

## Snapshot

Every policy-priced transaction stores: `employee_assignment_id`, `employee_organization_id`, `cafeteria_provider_id` (location), `cafeteria_service_network_id`, `provider_id` (payee), `cafeteria_service_assignment_id`, `cafeteria_service_policy_id`, `cafeteria_policy_version`, `subsidy_amount_applied`, `employee_contribution_applied` / `employee_payable_amount`, `provider_price_applied` (unit), `total_amount_applied`, `currency_code`, `pricing_source = policy`, `service_terminal_id`, and `policy_snapshot` (JSON: version, amounts as strings, working days, advance and leave rules, entitlement dates). Money is `DECIMAL(12,2)`, computed in integer cents. A later policy change never touches it.

Covered by `tests/Feature/Cafeteria/AdvanceUsageTest.php`, `ConcurrencyAndIdempotencyTest.php`, `PolicyVersioningTest.php`, `SecurityTest.php` and `tests/Feature/CafeteriaOperationalSettingsTest.php`.
