# Cafeteria policy architecture

How EUISIS decides, for one scan, **who may eat where, on whose terms, who pays and who is paid**.
Companion documents: [network access](cafeteria-network-access.md) · [entitlement rules](cafeteria-entitlement-rules.md) · [settlement rules](cafeteria-settlement-rules.md).

## The one rule

> The **employee's organization** owns the entitlement and the bill. The **scanned cafeteria** is where the service happened. That cafeteria's **provider** is paid.

The physical cafeteria never decides a subsidy. There is no global financial setting and no financial fallback: **no approved policy → no transaction**, with the message
*"No active cafeteria service policy is configured for your organization and this cafeteria."* /
*"ለተቋምዎ እና ለዚህ ካፍቴሪያ የሚሰራ የካፍቴሪያ አገልግሎት ፖሊሲ አልተዘጋጀም።"*

## Scope hierarchy

| Layer | Table | Holds | Never holds |
|---|---|---|---|
| System default | `cafeteria_settings` | week window, scan-mode fallbacks, safety limits, defaults that **prefill** new policies | subsidy, contribution, price |
| Provider (payee) | `providers` (+ `provider_services` `cafeteria`) | identity, contact, settlement details | an organization's subsidy |
| Network | `cafeteria_service_networks` | one provider's group of locations | access |
| Cafeteria (location) | `cafeteria_providers` | main / branch / service point, parent, opening hours, operational status, capacity | access, subsidy |
| Organization access | `organization_cafeteria_access` (+ `organization_cafeteria_location_access`) | may this organization eat in this network — primary cafeteria, cross-location, exceptions, dates | money |
| Service assignment | `cafeteria_service_assignments` | provider authorized to serve an organization (provider-wide, network or cafeteria), dates | money |
| Service policy | `cafeteria_service_policies` | versioned, dated money and entitlement terms of **one organization** at one provider scope | — |
| Entitlement ledger | `cafeteria_transaction_consumed_days` | each consumed entitlement (employee + date + type + slot) | — |
| Transaction | `cafeteria_transactions` | the historical snapshot: owner, location, payee, policy version, applied amounts | — |
| Settlement | `cafeteria_settlements` (+ lines) | provider payable by employee organization and cafeteria | — |

`cafeteria_providers` keeps its historical name: transactions, menus, orders, terminals and special days already point at it. It is the **location**; `providers` is the **payee**.

## Policy resolution — `CafeteriaPolicyResolver`

Input: employee, scanned cafeteria, service date. Steps:

1. Employee assignment **effective on the service date** (not "current") → **employee organization**. A transfer changes the policy from its effective date, never retroactively.
2. Cafeteria active and not archived → its **provider** (active) → its **network** (active, same provider).
3. **Organization access** to that network, effective on the date — and the location allowed: an explicit location exception wins; otherwise the primary cafeteria is allowed, other locations only with cross-location usage.
4. Effective **service assignment** for organization + provider covering the cafeteria (cafeteria → network → provider-wide).
5. **Policy** of the employee organization for that provider, binding on the date, whose own assignment is in effect; most specific scope wins: organization + cafeteria → organization + network → organization + provider.

Denial codes: `no_employee_assignment`, `cafeteria_inactive`, `provider_inactive`, `cafeteria_not_in_network`, `no_cafeteria_access`, `location_not_allowed`, `no_service_assignment`, `no_active_policy` (each has an EN/AM message in `lang/*/cafeteria-policy.php`).

## Policy model and versioning

A policy holds: `daily_subsidy_amount`, `employee_contribution_amount`, `provider_price`, `currency_code`, `max_daily_uses`, `allow_advance_usage`, `advance_max_days`, `extra_scan_policy`, Monday–Sunday flags, `exclude_public_holidays`, `block_employee_leave`, `effective_from/to`.

**Pricing** (`CafeteriaTransactionPricingService`, integer cents): per entitlement consumed, the provider is owed `provider_price`, the employee organization pays `daily_subsidy_amount`, the employee pays the rest. The form enforces `provider_price = daily_subsidy + employee_contribution`. An employee-paid scan consumes no entitlement: the employee pays the full provider price.

Lifecycle (`CafeteriaPolicyWorkflowService`):

```
draft ──submit──▶ under_review ──approve──▶ approved ──(date)──▶ active ──▶ superseded | expired
  ▲                    │ return                         └──cancel (before its date)──▶ cancelled
  └────────────────────┘
```

- Only **drafts** are edited. A change to approved terms is **Create New Version**: a draft pre-filled from the current terms (`policy_group_id`, `version_no + 1`, `supersedes_policy_id`).
- **Maker-checker**: the approver must differ from the drafter and submitter (`config/cafeteria.php`, `CAFETERIA_POLICY_DISTINCT_APPROVER`).
- Approval **locks the organization** and the scope's rows, trims the superseded version to end the day before, and refuses any overlap with another binding policy of the same `scope_key` (organization | provider | network | cafeteria).
- Binding follows **dates**: approved, active, superseded and expired policies govern the dates they cover. An approved future version never applies early; nobody has to press "activate" on the day. `cafeteria:sync-policy-statuses` (daily, 00:05) only updates the labels.
- Cancelling an approved future version restores the end date it had trimmed.
- Nothing is renewed automatically; the dashboard lists policies ending within `CAFETERIA_POLICY_EXPIRY_WARNING_DAYS` (30).

## System defaults vs. policy vs. operations

Fallback exists only for **operational** values: explicit policy → system default. For **financial** values there is none.

| Setting | Now |
|---|---|
| `default_daily_subsidy_amount` | read-only legacy value; never used |
| `allow_upfront_weekday_usage`, `exclude_public_holidays`, `excess_amount_mode`, `block_cafeteria_during_employee_leave`, weekend service flags | **prefill** new policies; each organization's policy decides |
| `week_start_day`, `week_end_day` | advance-use window (system) |
| `weekend_scan_mode`, `holiday_scan_mode`, `leave_scan_mode` | whether a non-entitlement day may be served employee-paid (system) |
| `closed_weekend_default`, Saturday/Sunday service, day rules, special days | physical opening of cafeterias (operational) |
| `max_transaction_amount_per_scan`, `max_extra_amount_per_week` | safety limits (system) |

Physical availability (cafeteria) and entitlement (employee policy) are **separate gates that must both pass** — see [entitlement rules](cafeteria-entitlement-rules.md).

## QR and NFC — one service chain

`CafeteriaQrScanService::process()` receives either a QR token or an `IdCard` a server-side NFC verifier resolved. Only credential verification differs; both run `CafeteriaPolicyResolver` → `CafeteriaEntitlementService` → `CafeteriaTransactionPricingService` → `CafeteriaTransactionService`. NFC takes the cafeteria from the **registered terminal** (`service_terminals.cafeteria_provider_id`), never from the request, and records `service_terminal_id`.

## Never trusted from a client

`organization_id`, `provider_id`, network, `policy_id`, subsidy, price, contribution, `meal_amount`, `scanned_at`. The scan form rejects `meal_amount`, `requested_subsidy_amount` and `scanned_at` (`prohibited`); the server uses its own clock and the resolved policy. A terminal's `meal_amount` stays in its signed NFC context but is not money.

## Permissions

`cafeteria_networks.view|manage`, `cafeteria_access.view|manage|approve`, `cafeteria_assignments.view|create|update|end|approve`, `cafeteria_policies.view|create|update_draft|submit|review|approve|activate|end`, `cafeteria_transactions.export`, `cafeteria_settlements.view|manage` (catalog: `database/seeders/data/cafeteria-policy-permissions.php`; granted to **Cafeteria Admin**). The scanner role (**Cafeteria Operator**) gets none of them.

Organization scope: access, assignment and policy pages list only rows of the admin's organizations, and every action re-checks the stored organization (`OrganizationScopeService`) — a changed `organization_id` in a request is refused. Provider portal users see only their own provider's active cafeterias (the switcher ignores another provider's location) and only those cafeterias' transactions.

## Audit

Network created/updated, location added/updated (including moves, closures, hours), access granted/changed (primary cafeteria, cross-location — with before/after)/approved/ended, assignment created/changed/approved/ended, policy created/draft changed/new version/submitted/returned/approved (with the subsidy, working-day and advance-rule changes)/activated/superseded/ended/cancelled, settlement created/finalized/cancelled; scans processed or rejected (access denials with the resolution identifiers). No raw QR token, NFC secret or card UUID is logged.

## Administration pages

Cafeteria Management: Dashboard (with configuration health) · Scan · Providers · Cafeteria Networks (tree) · Cafeterias · Organization Access · Service Assignments · Service Policies (list, A–G form, current-vs-new preview with warnings, version history, workflow) · Working Days · Public Holidays · Transactions · Ledger · Settlements · Reports · Analytics Reports · System Defaults.

## Migration and legacy data

Migrations `2026_09_27_000100`–`000500` add the tables and columns; nothing is dropped and no money is rewritten.

- Every existing cafeteria becomes a **main** location of its provider (`location_type = main`); it needs a network and organization access before it serves under the new model.
- Legacy transactions keep their stored amounts, get `pricing_source = legacy`, their payee from the cafeteria's provider and their billing organization **only** from the one assignment effective on the transaction date (else it stays NULL and appears as *unattributed*).
- The ledger backfill keeps the earliest un-reversed claim of each employee/date; historical double use is kept but flagged `legacy_duplicate`.
- `php artisan cafeteria:legacy-report` classifies every setting (SYSTEM_DEFAULT / ORGANIZATION_POLICY / CAFETERIA_OPERATIONAL / PROVIDER_OPERATIONAL / OBSOLETE), every legacy subsidy rule and every unplaced cafeteria. `--create-drafts --actor=<user id>` drafts (never approves) policies for organization-specific rules that have an active assignment.

### NEEDS_DECISION

1. **Global legacy subsidy rules** (`applies_to = all_employees`) cannot say which organizations they covered. Until policies are approved per organization, scans are refused — plan the switch-over (create networks, access, assignments, approve policies) before deploying.
2. **Sub-organizations** do not inherit a parent's access or policy. Each organization needs its own (explicit by design; a bulk "apply to sub-organizations" action could be added).
3. **Price = subsidy + contribution** is enforced. Confirm this matches the contracts, including cases where the provider price varies by menu.
4. **Legacy `cafeteria_provider_branches`** rows are not converted automatically; add them as branch locations of their network.
5. A **PostgreSQL exclusion constraint** on policy date ranges (`btree_gist`) would add a database-level overlap guard on PostgreSQL; the portable guard is the locked transactional check.
6. Settlements are provider-level and visible to holders of `cafeteria_settlements.view` across organizations; decide whether organization-scoped finance users need a filtered view.
