# Cafeteria settlement and reporting rules

Part of the [cafeteria policy architecture](cafeteria-policy-architecture.md). Implemented by `CafeteriaSettlementService` and `CafeteriaAnalyticsReportService`.

## Three owners, never confused

| Question | Answer | Column |
|---|---|---|
| Who pays the subsidy (billing owner)? | the **employee organization** on the service date | `employee_organization_id` |
| Who is paid (payee)? | the **provider** that operated the scanned cafeteria | `provider_id` |
| Where was the service? | the **cafeteria** scanned | `cafeteria_provider_id` |

Billing is never grouped by the organization a cafeteria "primarily serves".

## Settlement

A settlement is one provider and one period:

1. **Preview** (Cafeteria Management → Settlements → Create): unsettled, accepted transactions of the provider in the period, grouped by employee organization and cafeteria.
2. **Draft**: stores the lines and links each transaction (`cafeteria_settlement_id`), so no transaction can be settled twice; overlapping drafts cannot take the same rows (they are locked).
3. **Finalize**: releases any transaction reversed meanwhile, recomputes, freezes. A transaction in a finalized settlement can no longer be reversed.
4. **Cancel** (draft only): releases the transactions.

Every amount is the transaction's **applied snapshot** (`subsidy_amount_applied`, `employee_payable_amount`); provider payable = subsidy + employee share. A policy change after the fact moves nothing.

Example — Provider A:

| Employee organization | Cafeteria | Transactions | Organization subsidy | Provider payable |
|---|---|---|---|---|
| Organization 1 | Main | … | 120 × n | … |
| Organization 2 | Main | … | 150 × n | … |
| Organization 2 | Branch | … | 150 × n | … |

The settlement page shows both views: liability per employee organization, and lines per service location.

## Legacy transactions

Transactions recorded before policies keep their stored amounts (`pricing_source = legacy`). Their payee comes from the cafeteria's provider; their billing organization only from the one employee assignment effective on the transaction date. When that cannot be determined it stays empty and the settlement line reads **Unattributed (legacy)** — it is never guessed from today's assignment.

## Reports (Cafeteria Management → Analytics Reports)

Daily Transactions · Organization Subsidy Usage · Organization Liability · Provider Payables · Cafeteria Usage · Network Usage · Branch Usage · Employee Usage · Advance Usage · Policy History · Missing Policy · Access Matrix · Settlement Report. Filters: period, employee organization, provider, network, cafeteria; organization-scoped administrators see only their organizations. CSV export (`cafeteria_transactions.export`) uses UTF-8 with BOM and formula-injection protection.

Every transaction can be reported by employee organization, provider, network, cafeteria, policy version, service date and entitlement date.

## Dashboard alerts

Configuration health on the cafeteria dashboard (users with `cafeteria_policies.view`): active assignments without a binding policy; organization access without a policy; policies without organization access; policies ending within 30 days without an approved successor (never renewed automatically); and counts of access, assignments and policies awaiting a decision.

Covered by `tests/Feature/Cafeteria/SettlementTest.php` and `AdminWorkflowTest.php`.
