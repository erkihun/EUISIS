# EPMS strategic planning core

The strategic planning layer sits between a performance cycle and the organization plan:

1. Register bilingual strategic goals for one organization and cycle.
2. Assign a percentage weight to every goal. Active goal weights must total exactly `100.0000` before review, approval, publication, or organization-plan publication.
3. Allocate each goal to one or more organization units. Contributions must equal the goal weight exactly and exactly one allocation must be the lead unit.
4. Link organization-plan objectives to their strategic goal. Their absolute organization weights must equal that goal's weight.
5. Attach annual KPI targets to objectives. Quarterly and monthly target rows are entered explicitly; EUISIS does not divide annual values automatically.

## Workflow and immutability

Strategic goals move `DRAFT → UNDER_REVIEW → APPROVED → PUBLISHED`. Only drafts in an open cycle can be changed. A reviewer with `strategic_goals.approve` can **return** a goal that is under review, or approved but not yet published, to DRAFT with a reason (`performance.strategic-goals.return`); the reason stays on the goal until it is submitted again. Every transition locks the organization's goal rows and recomputes the complete readiness ledger inside one database transaction. Published goals are immutable; later changes should use the existing plan-version workflow.

Organization-plan validation reuses the same readiness checks, so a plan cannot be published while goal weights, unit allocations, lead ownership, or linked objective weights are incomplete.

## Access and audit

All reads are constrained by organization scope. Mutations use granular permissions for goal creation, draft editing/deletion, allocation management, approval, and publication. Creation, edits, allocations, period targets, and state transitions write EPMS audit events with actor and before/after data.

## Decimal rules

Weights and targets are stored as fixed-scale decimals. Reconciliation uses `Brick\Math\BigDecimal`; floats and tolerance comparisons are not used. A total of `99.9999` or `100.0001` is not publishable.
