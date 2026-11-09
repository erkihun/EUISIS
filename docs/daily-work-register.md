# Employee Daily Plan & Work Execution Register

**የባለሞያ የእለት እቅድ ክንውን መመዝገቢያ**

The register records each employee's daily work against the main tasks of their position, and measures it against approved task standards (BPR plans) using the formulas of the official form.

It extends the Daily Activity module rather than running beside it. The daily header, items, workflow, reviewer assignments, review policy, evidence, reminders, reports and EPMS link are the existing ones (see [daily-activity.md](daily-activity.md) and [daily-activity-workflow.md](daily-activity-workflow.md)). This document covers what the register adds.

## Source-form mapping

| Form field | Where it lives |
|---|---|
| Date (ቀን) | `daily_activity_logs.activity_date` (Gregorian ISO; the Ethiopian reading is display-only) |
| Main Service (ዋና አገልግሎት) | `position_services` (existing) |
| Sub-Service (ንዑስ አገልግሎት) | `position_service_sub_services` |
| Main Tasks of the Sub-Service (ዋና ተግባራት) | `position_service_tasks` |
| Standard Measurement (ስታንዳርድ መለኪያ) | `position_service_task_standards.standard_measure` and its planned values |
| Main Task Plan (BPR) (የዋና ተግባር ዕቅድ (የBPR)) | `planned_quantity` / `planned_time_minutes` / `planned_quality`, plus `bpr_reference`, on a versioned standard |
| Start Time (የተጀመረበት ሰዓት) | `daily_activity_items.started_at` |
| Completion Time (የተጠናቀቀበት ሰዓት) | `daily_activity_items.ended_at` |
| Time Taken (የወሰደው ሰዓት) | `daily_activity_items.duration_minutes`, calculated by the server, never typed |
| Actual (ክንውን) | `quantity` (actual quantity) and `actual_quality` |
| Quantity / Time / Quality Performance | `quantity_score`, `time_score`, `quality_score` |
| Main Task Aggregate Performance (ጥቅል አፈጻጸም) | `task_score` |
| Aggregate Sub-Service Plan Performance | Calculated in the *Sub-service aggregate performance* report |

## Hierarchy

```
Position
 └─ Position Service  (Main Service, existing)
     └─ Sub-Service
         └─ Main Task
             └─ Task Standard v1, v2, … (one approved version in force on any date)
```

Every level stores `organization_id`, which is the scope boundary. The structure is master data, managed under **Position Services › Sub-services & tasks** (`/position-services/{id}/structure`). Employees never edit it.

## Standards and versions

- **Lifecycle:** a standard version is `draft`, `approved` or `retired`. Only an approved version measures work.
- **Dimensions:** a standard measures any combination of quantity, time and quality. A dimension applies only when its planned value is set, and planned values must be above zero, because they are denominators.
- **Quality:** when quality is measured, *how* it is measured must be written (`quality_measure`). The standard also says who records actual quality: the employee, or the reviewer at approval (`quality_source`).
- **Approved versions are immutable.** A change is a new version.
- **Approving a version** closes the version in force before it on the day before. A version cannot start on or before an approved version's start. When it replaces an approved version, it must start today or later, so work already open for (backdated) entry keeps its standard. A task's first version may start in the past.
- **Retiring** stops a version measuring new work.
- **Snapshots:** each item copies the planned values, units, version, BPR reference and quality definition it was measured against. Editing a draft day keeps an item's original standard. A later version never rescores recorded work.
- **Rights:**
  - `work_standards.manage` (Organizational Admin, within scope) maintains sub-services, tasks and drafts.
  - `work_standards.approve` (city-level roles) approves and retires.
  - Both are checked against the owning position service's organization scope.

## Employee workflow

1. Choose the Main Service. Sub-Services are filtered by it, and Main Tasks by the Sub-Service.
2. The standard in force on the work date is shown read-only: standard measure, BPR plan, planned time and planned quality.
3. Enter start and completion time. Time taken is shown and calculated by the server.
4. Enter the actual quantity and, when the employee records quality, the actual quality.
5. A preview of the scores is shown, labelled as a preview. The server recalculates on every save, and only its values are stored.
6. Save the draft, or submit. On submission a measured task needs its start and completion time, its actual quantity (when quantity is measured), and its actual quality (when quality is measured and the employee records it).

Other work without a task ("other activity") is still accepted unless **Require Main Task on Every Activity** is on in Daily Activity Settings.

The server resolves Task → Sub-Service → Service from master data and checks that the service belongs to the position and organization of the day's assignment. A task of another position, an inactive one, or one whose sub-service or service was sent inconsistently is refused. The standard, planned values and scores are `prohibited` in the request: a browser cannot choose or alter them.

## Reviewer workflow

Unchanged: approve, or return for correction with a comment. In addition, when a task's standard makes the reviewer the authority on quality, the reviewer enters the actual quality for that item at approval. Approval is refused until every such item has a value, and those items are rescored at that point. Reviewers never edit the employee's own values.

## Formulas

Calculated only on the server (`DailyWorkPerformanceCalculator`), with bcmath decimals and never float:

```
Quantity performance = Actual quantity / Planned quantity × 100
Time performance     = Planned time / Actual time × 100
Quality performance  = Actual quality / Planned quality × 100
Main task aggregate  = (Quantity + Time + Quality) / 3
Sub-service aggregate = (Task 1 + Task 2 + … + Task n) / n
```

Intermediate values keep 10 decimals. Stored scores have 4 (NUMERIC(12,4)) and reports show 2. The aggregate is computed from unrounded components.

**Example:** plan 10, actual 8 → 80%. Plan 60 minutes, actual 75 → 80%. Plan quality 100, actual 90 → 90%. Aggregate (80 + 80 + 90) / 3 = 83.3333% (shown as 83.33%).

**Edge cases:**
- **Above 100% is not capped.** 60 minutes planned and 30 taken is 200%.
- **Zero denominators are refused**, never turned into Infinity or zero. Planned values are validated as above zero when saved, and time taken must be above zero.
- **Values:** negative values, and completion at or before start (same-day work), are refused.
- **Incomplete dimensions:** a measured dimension with no value yet leaves the aggregate *incomplete*, never zero.

## NEEDS_DECISION

The source form is silent on these points. The system is configuration-ready and does not invent policy.

| Question | Current behaviour |
|---|---|
| Are scores capped at 100%? | No cap. Raw values are stored and shown. |
| How is actual quality measured for each task? | Each standard must state it (`quality_measure`). The organization must define the method per task. |
| Who records actual quality? | Configurable per standard: the employee, or the reviewer at approval. |
| How are dimensions a standard does not measure handled? | Setting **Main Task Aggregate Rule**. `applicable_average` (default, proposed) averages the measured dimensions. `all_three` gives no aggregate unless all three are measured. A missing dimension is never treated as zero. |
| Sub-service aggregate: all configured tasks, or only executed ones? | Only tasks executed in the period. A task's performance is the average over its executions. |
| Can the same task be recorded several times a day? | Allowed: repeated service instances are real. Each execution is scored on its own. |
| Is manager review mandatory? | Existing review policy (city-wide setting, overridable per organization). |
| Are non-working-day entries allowed? | Existing rule: not on weekends, holidays or leave. A policy change is needed to allow them. |
| What is the backdate limit and lock timing? | Existing Daily Activity settings (backdating window; submitted days are locked; corrections go through return and resubmit). |
| Who approves BPR standards? | `work_standards.approve`, held by city-level roles only. Assign it to the approving authority. |
| Overnight work (completion after midnight)? | Not supported: completion must be after start on the same day. |
| Is planned time for the whole plan quantity or per unit? | Compared as written: the planned time against the time taken for the recorded execution. |

## Relationship to EPMS

Scores are performance *evidence*, not the EPMS score. An item may still link to the employee's own KPI item (existing EPMS link). Formal EPMS scoring stays governed by the EPMS calculation rules. Daily work records are not attendance and not payroll records.

## Reports

Under Daily Activities › Reports, scoped like every Daily Activity report:

- **Main task performance (plan vs actual):** per main task, with executions, employees, planned and actual quantity, and the average quantity, time, quality and aggregate performance.
- **Sub-service aggregate performance:** per sub-service, with the tasks executed, executions and the form's sub-service aggregate.

Both use one grouped query, whose row count is the number of tasks, and include only submitted days. They export to Excel, CSV and PDF with the existing formula-injection protection.

## Scale

The module is designed for about 180,000 employees. Headers stay one per employee per day under the existing unique index. Items are indexed on `task_id`, `sub_service_id` and `task_standard_id`. Standards are resolved through `(task_id, status, effective_from, effective_to)`. The entry page loads the position's structure in four bounded queries, and reports aggregate in SQL. Missing days are calculated, never stored.

## Migration

The migrations are additive and nullable. Existing items remain valid as unstructured "other work" records. Free text is never mapped to a task, because the mapping could not be trusted.
