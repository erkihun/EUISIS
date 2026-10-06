# Performance cascade

How a strategic goal reaches a position, an employee and a daily task in EUISIS EPMS, and why each link lives where it does. Read this with [epms-strategic-planning.md](epms-strategic-planning.md), [epms-cascade-rules.md](epms-cascade-rules.md) and [epms-calculation-rules.md](epms-calculation-rules.md).

## Mapping to the conceptual model

EUISIS already had a planning hierarchy, so the cascade reuses it. No parallel `unit_plans`, `position_plans` or `position_plan_items` tables exist, and none should be added.

| Concept | EUISIS entity |
|---|---|
| Strategic Goal | `strategic_goals` (cycle + organization + `version_no`) |
| Strategic Goal Allocation | `strategic_goal_allocations` |
| Unit Plan | `performance_plans` where `plan_type = UNIT` |
| Unit Plan Item / Objective | `performance_objectives` of a UNIT plan |
| Position Plan | `performance_plans` where `plan_type = POSITION` |
| Position Plan Item | `performance_objectives` of a POSITION plan |
| KPI definition / target | `kpis` / `kpi_targets`, with quarter and month rows in `kpi_period_targets` |
| Position Service | `position_services` |
| Employee Performance Agreement / item | `employee_performance_agreements` / `employee_performance_items` |
| Daily Activity / evidence | `daily_activity_items`, `performance_evidence` |
| Actual result / appraisal | `kpi_actuals`, `kpi_contributions`, `performance_plan_scores`, `performance_results` |

## A. Master-data relationship

```
Organization → Organization Unit → Position → Position Service
```

- A position belongs to an organization and a unit. It exists across many cycles and has no `strategic_goal_id`.
- A position service is a stable responsibility of one position (`position_services.position_id`). It answers "what is this post responsible for?" and has no `strategic_goal_id`.
- Neither table has ever had a goal column, so no legacy goal mapping needed removing. A schema test guards this.

## B. Performance relationship

```
Performance Cycle + Organization
  → Strategic Goal (version)
  → Strategic Goal Allocation (unit, absolute percentage points)
  → Unit Plan → Unit Plan Item
  → Position Plan → Position Plan Item ──(optional)── Position Service
  → KPI Target (annual → quarterly → monthly where entered)
  → Employee Performance Agreement → Agreement Item
```

- Root objectives of the ORGANIZATION plan carry `strategic_goal_id`.
- Unit and position items link upstream through `parent_objective_id` to an objective of the **published parent plan**. `PerformanceCascadeService::links()` derives `strategic_goal_id` and `strategic_goal_allocation_id` from that lineage. A goal can never be attached directly to a unit or position item.
- `strategic_goal_allocation_id` names the allocated unit: the plan's own unit, or its nearest allocated ancestor for a descendant team. A goal that allocates nothing to the unit's line is refused.
- `position_service_id` is optional per item. It must be an active service of the plan's own position and organization, and the backend refuses any other. One service can support several items, cycles and goals without being duplicated.
- A position plan's parent must be the published UNIT plan of the position's unit. A position without a unit cannot be planned until it is placed.

The distinction in practice:

| | Position Service | Position Plan Item |
|---|---|---|
| Question | What is this post responsible for? | What must it deliver this cycle? |
| Lifetime | Master data, many cycles | One plan version in one cycle |
| Example | Employee Recruitment | 2026: 95% of approved requests within the service standard. 2027: cut average processing time by 15%. |

## C. Traceability relationship

```
Daily Activity → Agreement Item → Position Plan Item (→ Position Service)
  → Unit Plan Item → Strategic Goal Allocation → Strategic Goal
```

`PerformanceCascadeService` resolves every direction from the stored lineage. One eager-loaded objective graph per cycle and organization avoids N+1 queries.

| Question | Method |
|---|---|
| Lineage of one item, goal to service | `trace(objective)` |
| Which goal a daily task contributed to | `activityTrace(dailyActivityItem)` |
| Which goals a plan version contributes to | `goalsForPlans(plans)` |
| Which goals a position contributes to, per cycle and version | `goalsForPosition(position, ?cycleId)` |
| Which goals an employee contributed to, per agreement and assignment | `goalsForEmployee(employee, ?cycleId)` |
| Which units, positions, services, KPIs and employees deliver a goal | `goalCascade(goal, actor)` |

Unlinked operational work stays unlinked. The service never guesses a goal from names or codes.

## Final architecture

```
Performance Cycle + Organization
        │
        ▼
Strategic Goal ─────────────── weight_percent (absolute, Σ = 100)
        │
        ▼
Strategic Goal Allocation ──── organization_contribution_percent (absolute, Σ = goal weight)
        │
        ▼
Organization Unit
        │
        ▼
Unit Plan (performance_plans, UNIT)
        │
        ▼
Unit Plan Item / KPI Target ── local weight (Σ = 100 per plan)
        │
        ▼
Position Plan (performance_plans, POSITION)
        │
        ├──── Position (master)
        │        │
        │        ▼
        │   Position Services (master)
        │        │
        ▼        ▼
Position Plan Item ──────────── local weight (Σ = 100 per plan), optional service link
        │
        ▼
Employee Agreement Item ─────── objective weight × target weight ÷ 100
        │
        ▼
Daily Activity / Evidence ───── evidence and source quantities, never a score by itself
        │
        ▼
KPI Actual → Plan Score / Appraisal Result
```

## Weight semantics

Three different quantities are stored in three different fields. They are never interchangeable.

| Weight | Field | Meaning | Invariant |
|---|---|---|---|
| A. Organization goal weight | `strategic_goals.weight_percent` | Absolute share of organization performance | Active goals for the cycle and organization total exactly 100.0000 before review, approval or publication |
| B. Unit allocation | `strategic_goal_allocations.organization_contribution_percent` | Absolute organization percentage points delivered by a unit | Each share is positive. The draft total may be below the goal weight but never above it. At publication the total equals the goal weight exactly (30 → 15 + 10 + 5, not 100) |
| C. Local plan weight | `performance_objectives.weight` / `local_weight_percent` | Share of one unit's or position's own workload | Active items of a plan total 100 at submit and publish. Drafts may be incomplete. The two fields must agree when both are given |

Two further weights sit inside a plan: KPI target weights total 100 within each objective, and agreement item weight equals objective weight × target weight ÷ 100.

The existing methodology requires exactly one lead allocation per goal at publication (`epms-strategic-planning.md`). This rule was already defined before this work and is enforced; no additional government policy is assumed.

All values are DECIMAL columns, reconciled with `Brick\Math\BigDecimal`. There are no floats and no tolerances: 99.9999 is not publishable.

## Achievement and contribution

An allocation's contribution is in organization percentage points:

```
contribution = allocation points × achievement ÷ 100
15 × 90 ÷ 100 = 13.5000 points     (not 90 points, not 30 × 90)
```

`PerformanceCascadeService::contribution()` is the single implementation. `allocationContributions(goals)` applies it for each allocation:

1. Take the allocated unit's published UNIT plan in the goal's cycle.
2. Read its latest stored plan score (`performance_plan_scores`, produced by `PerformanceAggregationService::planScore`).
3. Achievement is the weighted average, by local weight, of the scores of that plan's items linked to the allocation. Amended goals copy allocations, so items linked to an earlier version of the same goal and unit count too.
4. Items without any reported KPI achievement are excluded and the allocation is marked incomplete. An unmeasured allocation shows "not yet measured"; it never counts as zero.

The goal page shows each allocation's achievement, its points and the as-of date, plus the goal's total points with an incomplete flag. All of it is calculated on the server.

This is a derived accountability view. The official organization score remains the organization plan score: KPI actuals rolled up through `parent_target_id` and `kpi_contributions` by each KPI's aggregation method. Employee appraisal scores are never summed into a unit or the organization, and each source row reaches exactly one parent, so nothing is double-counted.

## Versioning and history

1. Published goals, allocations, plans and targets are immutable. Changes go through reason-required draft successors: `StrategicPlanningService::newVersion` (goal and its allocations), `PerformancePlanService::newVersion` (plan, items, targets, period targets, cascade rows) and `TargetAmendmentService`.
2. A goal amendment keeps its code, so the version family stays identifiable. Only one pending successor may exist at a time.
3. A child plan amended after its parent changed must name the published parent version. Mapping follows `source_objective_id` ancestry. A missing or ambiguous successor raises `NEEDS_DECISION` and the transaction rolls back. Matching names are never treated as proof.
4. Plans snapshot their organization unit. Moving a position to another unit does not rewrite earlier plans, and agreements still resolve their historical plan.
5. Deletion is limited to drafts: draft goals without objectives or successors, allocations no item references, and items or targets of DRAFT plans. There is no plan delete route. Positions and services are soft-deleted, and items reference services with a restricting foreign key.

## Employees, transfers and daily evidence

- An agreement is created from the employee's active primary assignment (`current_assignment_id`) and the published position plan matching that assignment's cycle, organization, unit and position. It is not taken from a bare `employee.position_id`.
- Agreement items inherit position targets. Employee-specific changes go through the amendment workflow and never modify the position plan.
- Completing a transfer closes the old agreements the day before the new assignment starts. Old plan, service, target, actual, evidence and result links stay attached to the old assignment. `goalsForEmployee` returns one row per agreement with its own assignment, position, unit and plan.
- A daily task may link to an agreement item of the same employee, assignment and agreement period. DAILY_ACTIVITY KPIs use approved quantities (or the configured submission policy) and exclude unlinked or wrong-assignment work. Counting tasks never produces a score, and a period with no eligible measurement stays null rather than zero.

## Client service feedback

```
Client scans employee QR → rates one active service of the employee's current position
  → employee_service_feedback (organization, unit, position and service frozen at submission)
  → KPI with system source service_feedback.average_rating
  → agreement item → position plan item (→ its position service) → strategic goal
```

- The public form lists only active services of the employee's current position. The server refuses any other service.
- An agreement item counts a rating when its KPI uses the feedback source and the rating was given to the same employee, organization and position during the agreement period. If the item's plan item names a position service, only that service's ratings count; otherwise all of that position's evaluated services count.
- Only services marked **Use for Performance Evaluation** count. A deactivated or deleted service keeps the ratings it already earned.
- Every review status counts. Review moderates the comment, and a hidden comment's rating still counts (`ServiceFeedbackStatus`). Restricting this to reviewed feedback would be a policy change.
- Each submission re-measures that month for the matching ACTIVE, AGREED or UNDER_REVIEW agreement items (`ServiceFeedbackPerformanceSync`). Finalized and closed agreements are never changed. If measurement fails, the rating is still stored and the error is logged; the item's sync action re-measures it.
- System actuals are stored as one verified row per calendar month, clipped to the agreement period. Re-syncing any range replaces those months, so ratings are never counted twice. Use `RATIO_FROM_TOTALS` aggregation: Σ ratings ÷ Σ responses across months, in DECIMAL. A month without ratings stores nothing, so no rating is never treated as a rating of zero.
- After a transfer, new ratings carry the new position and reach only the new agreement.

## Authorization

Mutations require the named permission, organization scope (`EpmsAccess` / `OrganizationScopeService`) and, for unit planning, explicit unit reviewer coverage. Job titles grant nothing. An organizational admin cannot edit another organization's plans. A unit manager edits only covered units. An employee cannot edit position plans. Goal cascades show employee names only to actors allowed to view those agreements. Plan scores, contributions and contributing goals appear only to actors with `performance_plans.view` in that organization.

## Schema

| Migration | Change |
|---|---|
| `2026_09_26_100000_add_strategic_planning_core` | `strategic_goals`, `strategic_goal_allocations` (unique goal + unit), `kpi_period_targets`, objective `strategic_goal_id` / `absolute_weight_percent` / `local_weight_percent`, PostgreSQL range checks |
| `2026_10_04_100000_link_performance_items_to_allocations_and_services` | Nullable objective `strategic_goal_allocation_id` and `position_service_id` (restrict on delete, indexed). Goal `version_no`, `supersedes_goal_id` and `change_reason`. Goal uniqueness becomes cycle + organization + code + version |

Existing goals default to version 1. No link was backfilled by guessing. Rollback of the second migration refuses to run once goal versions or new links exist (`NEEDS_DECISION`), so history cannot be collapsed silently.

Uniqueness that allows valid history: one live plan per subject and cycle (`live_key`), versions unique per lineage, one allocation per goal and unit, one goal code per version.

## Data decisions

- The local PostgreSQL audit at migration time found 3 goals and no allocations, plans, objectives, services, agreements or daily items. No position or service had a goal field.
- **NEEDS_DECISION:** one active position had no unit. Its placement is a business decision, so none was guessed; that position cannot get a position plan until it is placed.
- An upstream successor that cannot be mapped uniquely during a plan amendment is refused as `NEEDS_DECISION`.

## Demo fixture

`DemoPerformanceSeeder` (ORG-5, all values marked DEMO): goals 30 / 40 / 30. The shared 30% goal is allocated 15 / 10 / 5. Published organization, Planning unit and position plans use the existing tables. Two position items of 50% each reuse one position service with KPI targets. A draft agreement for the assigned employee and one draft daily task show the trace without creating an official actual or score. Repeat runs keep existing fixtures.
