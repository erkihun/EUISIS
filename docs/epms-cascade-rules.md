# EPMS — Cascade Rules

Cascading turns an organization objective into unit, child-unit and position objectives while keeping the lineage, so every employee KPI can be traced back to the strategy. The rules are implemented in `PerformancePlanService` and `PlanCascadeService`.

## 1. Plan structure

| Plan type | Parent | Structure check |
|---|---|---|
| ORGANIZATION | none | one live plan per organization and cycle |
| UNIT | ORGANIZATION, or a UNIT plan of an **ancestor** unit | the parent unit must be above this unit in the unit tree |
| POSITION | the UNIT plan of the position's unit, or the ORGANIZATION plan when the position has no unit | |

Every parent must be **PUBLISHED**, in the **same cycle** and in the **same organization**. A plan cannot be created twice for the same subject in a cycle; later changes are made with a new version.

## 2. Cascade modes (parent objective → child plan)

The cascade must go to a **direct child plan**, and the child plan must be editable (DRAFT).

| Mode | Result in the child plan | Cascade type |
|---|---|---|
| ACCEPT | same wording, linked | INHERITED |
| CUSTOMIZE | own wording and weight, still linked | CUSTOMIZED |
| SPLIT | several child objectives (parts with titles and weights), each linked | SPLIT |
| CONTRIBUTE | the child supports part of the parent objective | CONTRIBUTION |
| decline | a REJECTED child objective with the reason (not allowed for **mandatory** objectives) | — |

Each child objective stores `parent_objective_id`, and each decision is written to `performance_cascades`.

When "copy targets" is on, the parent's KPI targets are copied as child targets with `parent_target_id` set. This link is what later rolls the child's measurement up into the parent (see the [calculation rules](epms-calculation-rules.md) §5).

The following are refused with a validation error:

- cascading into the objective's own plan, a sibling, or a grandchild (skipping a level);
- cascading into an unpublished parent or a child that is not in DRAFT;
- cascading into another organization's plan, which is refused by scope.

## 3. Validation before a plan is submitted (`validate()`)

- At least one active objective.
- Active objective weights total **100**.
- Each objective has at least one current target, and its target weights total **100**.
- Each target is valid for its KPI direction (positive target for HIGHER_IS_BETTER, a zero-score deviation when a TARGET_IS_BEST target is 0, milestones exist …).
- The parent plan is published.
- Every **mandatory** parent objective has been cascaded into this plan.
- There is no circular lineage.
- The effective dates are inside the cycle.

The plan page lists the problems. A plan cannot be submitted until the list is empty.

## 4. Versions

Published plans are not edited in place. **New version** copies:

- objectives, keeping `source_objective_id`;
- targets;
- cascade links.

The copy starts as a DRAFT with the next `version_no` in the same `lineage_key`. Publishing it marks the previous version SUPERSEDED and moves `live_key` to the new one. Agreements already built from v1 stay linked to v1. New agreements use the live version.

## 5. Target amendments

A target on a published plan (or an item on a live agreement) changes only through an amendment:

1. **Request:** new value and/or weight, reason, effective date.
2. **Approval:** by someone other than the requester, when `amendment_requires_approval` is on. Otherwise the request is applied at once.
3. **New version:** approval creates a new target row (`amended_from_id`, `version_no + 1`, `is_current`) and re-points children to it.

Scoring then applies each version to its own period.

## 6. From position plan to agreement

`EmployeeAgreementService::create` builds the agreement from the published position plan:

- **Items:** one per current position target. Item weight = objective weight × target weight ÷ 100, so the items total 100. Each item keeps `position_target_id`.
- **Competencies:** taken from `position_competencies`, or equal weights over the default framework.
- **Effective dates:** the intersection of the cycle and the assignment.

A manager can add *additional* items. The agreement still has to total 100 before it is sent.
