# ADR 0019: Strategy hierarchy entered the WLMS way

- Status: accepted (2026-10-31)
- Context: the owner asked for the strategic overview, main strategy, strategic goal, objective,
  main/detailed activity levels of the WLMS project (github.com/erkihun/wlms), with its formulas
  and its entry process. WLMS had no achievement calculation, only weight rules; EUISIS EPMS
  already had goals, allocations, plans, KPIs and scoring, but nothing above the goal and nothing
  below the objective.

## Decision

Add the missing levels to `Modules/Performance` and enter them on two WLMS-style forms, without a
second planning system:

| Level | Table | Weight rule (exact decimals, `Dec`) |
|---|---|---|
| Main strategy | `performance_main_strategies` (per cycle and organization) | Σ main strategies ≤ 100 |
| Strategy | `performance_strategies` | Σ strategies of a main strategy = its weight |
| Strategic goal | `strategic_goals.strategy_id` | goal weight = strategy weight |
| Objective | `performance_objectives` in the organization plan | Σ objectives of a goal = goal weight |
| Activity | `performance_objective_activities` | Σ activities of an objective = 100, at least two |

- **Form 1 (main strategy):** title, weight, description and a table of strategies with a live total.
- **Form 2 (strategic goal):** pick a strategy from a list grouped by main strategy; the goal's weight
  comes from it; objectives with their activity tables (activity, time in minutes, weight) and live totals.
- **Strategic overview:** counts, the main-strategy total against 100, each strategy's share of its
  main strategy (strategy ÷ main × 100) and the state of its goal.
- **The WLMS entry flow (2026-10-31, owner's follow-up):** a "Main Strategies" list and a "Strategic
  Goal" list, each with view, edit and delete, a detail page and a form that returns to its list.
  Layout, field order, wording (`performance.strategy.main.*` / `goals.*`, taken from WLMS
  `lang/{am,en}/app.php`), numbering (objective #M.S.O, activity M.S.O.A) and the weight display
  "Given: x% | y%" (the entered weight, then its share of the level above) follow WLMS, as do the
  defaults (two strategies at 50%, two activities at 50%), the add/remove-last buttons, the
  balanced / needs-fix badge, the collapsible main-strategy groups and the "active strategic goal"
  flag (`strategic_goals.is_active`, a label only).

Decisions taken where the request left room:

1. One goal per strategy, as in WLMS. The goal's name, code (`SG-<main>-<strategy>`) and weight come
   from the strategy; a draft goal follows later edits of the strategy, a goal under review or
   published locks the strategy's title and weight, and a strategy or main strategy with a goal
   cannot be deleted.
2. Objectives are stored in the organization's live plan for the cycle (created as a draft when
   there is none), so cascading, KPIs, readiness and scoring keep working. They can be edited only
   while the goal and that plan are drafts.
3. Main and detailed activities are one level, as in WLMS ("ዋና / ዝርዝር ተግባራት").
4. WLMS is single-language: the text typed on the forms is stored in both the Amharic and English
   fields of goals and objectives.
5. Rows are updated by id, never deleted and recreated, so references survive edits.
6. Existing permissions are reused: `strategic_goals.view/create/update/delete_draft` for the
   hierarchy, the plan permissions for the objectives, all inside the organization scope (ADR 0003).
7. Allocation to units and the review → approve → publish workflow stay on the Strategic goals page.
8. Where WLMS and EPMS differ, EPMS rules win and the WLMS screen shows them: a new goal's single
   objective starts at the strategy weight (WLMS put 100, which can never balance); only draft goals
   can be edited or deleted, and deleting one also removes its objectives from the draft plan; a
   strategy that already has a goal is left out of the goal form's list, as in WLMS.

9. One way in (2026-10-31 walkthrough): goals are registered only on the strategy forms. The old
   Strategic goals page became "Goal allocation & approval" (የግቦች ድልድልና ማጽደቅ): its add button
   opens the Strategic Goal form, a strategy goal's edit and delete go through the strategy form and
   service, and the server refuses a name, code or weight change there. The dashboard's setup steps
   follow the same order: cycle → strategy and goals → allocation and approval → plans → agreements.

## Consequences

- Goals entered on the old Strategic goals page before this change (no strategy) still work and are
  not shown on the overview.
- When every strategy has a goal and the main strategies total 100, the goals total 100 as the
  existing organization readiness check requires.
