# Transport — Contracts, Invoices and Settlement

Behind `transport_settlement`. Money is integer cents (`TransportMoney`), stored `DECIMAL(14,2)`, never floating point.

## Contracts (`transport_contracts`)
Reference + `version_no`; status `draft → active → superseded | ended`, `cancelled` for drafts. A version carries `settlement_model`, `settlement_period`, `currency_code`, `terms` (only the rates the model needs), `service_coverage`, the signed agreement file and an authorization reference. Approval is by someone other than the drafter (`transport_contract.changed` audit). A statement snapshots the version it applied (`contract_snapshot`); later versions change nothing historical.

### Settlement models (`TransportSettlementModel`) and required terms
| Model | Terms | Lines |
| --- | --- | --- |
| `per_verified_boarding` | `rate_per_boarding` | verified_boardings × rate |
| `per_employee_per_day` | `rate_per_employee_day` | employee_days × rate |
| `per_completed_trip` | `rate_per_trip` | completed_trips × rate |
| `fixed_periodic` | `fixed_amount_per_period` | fixed amount |
| `fixed_plus_usage` | `fixed_amount_per_period`, `rate_per_boarding` | fixed + boardings × rate |
| `minimum_guarantee_plus_usage` | `minimum_amount_per_period`, `rate_per_boarding` | boardings × rate, topped up to the minimum |
| `capacity_based` | `rate_per_seat_trip` | seat-trips (capacity × completed trips) × rate |
| `other_approved_model` | — | no amount; the approver enters it (`NEEDS_POLICY_DECISION_TRANSPORT_SETTLEMENT_MODEL`) |

No rate is ever defaulted; a missing rate blocks approval of the contract and flags the statement (`policy_gaps`).

## Invoices (`transport_invoices`) — the provider's claim
`draft → submitted → under_review → approved | returned | disputed | withdrawn`. The provider states claimed boardings / trips / employee-days / amount with an attachment; staff take it under review or return it with a reason. A claim is never a verified figure.

## Statements (`transport_settlements`)
`TransportSettlementService::compute(provider, contract, period, actor, invoice?)`:
1. `basis()` counts from evidence only: verified boardings, unique riders, employee-days, completed and cancelled trips, by route, with data-quality notes (boardings on uncompleted trips, offline pending). Only verified rows not yet on another statement are taken and are stamped with the statement id (released again if the statement is rejected or cancelled).
2. `TransportSettlementCalculator` applies the snapshot's rates → lines and `calculated_amount` (or none + gap).
3. `claimed_amount` is copied from the invoice; `adjustment_total` sums approved adjustments; `approved_amount` is set only at approval.

`draft → under_review → approved → paid`, or `rejected` / `cancelled`. The computer cannot approve (`settlement_requires_distinct_approver`); pending adjustments block approval; a model without an amount needs an explicit approved amount. Audit: `transport_settlement.computed / reviewed / approved / rejected / payment_recorded`.

## Adjustments and disputes (`transport_settlement_adjustments`)
Signed amount (and optional quantity) with a reason and evidence; origin `organization_adjustment` (staff) or `provider_dispute` (portal, until the statement is final; marks the invoice `disputed`). Decided approve/reject by a different person; approved ones change `adjustment_total` only — the evidence and the calculated amount never move.
