# Executive Situation Room — Workforce Analytics

## Source of truth

* **Who works where:** current, active assignments (`employee_assignments.is_current
  = true AND assignment_status = 'active'`), the rule D-2 occupancy used by
  registration, imports and transfers (`PositionCapacityService`). Headcount
  counts distinct employees, so a duplicated current assignment is counted
  once (and reported in Data Quality).
* **Capacity:** per active position, approved establishment slots effective
  today, or 1 when there is none — exactly `PositionCapacityService::capacity()`,
  computed in one grouped SQL query (`WorkforceQueries::capacityByOrganization`).
* **Organization:** the assignment's organization, confined to the scope before
  counting, rolled up along the published hierarchy.

## Never loads employee rows

Every figure is a `COUNT(DISTINCT ...)` / `SUM(CASE ...)` grouped in the
database. Gender is normalised and age banded **in SQL**; no birth date,
gender or name of an individual leaves the database. The largest result set
is one row per organization in scope.

## Dimensions

| Dimension | Basis | Notes |
| --- | --- | --- |
| Gender | `employees.gender` | English, single-letter and Amharic spellings normalised; anything else "not recorded". |
| Age band | `employees.date_of_birth` | Under 25, 25–34, 35–44, 45–54, 55–59 (near retirement age), 60+, unknown. |
| Employment type | `employees.employment_type` | `EmploymentType` values + not recorded. |
| Employee status | `employees.status` of current assignees | e.g. suspended while still assigned. |
| Occupation | `positions.occupation_id` → `occupations` (ISCO) | Professions are linked to occupations, not to people or positions; reported by occupation. |
| Grade | `positions.grade_level` | As recorded. |
| Education | — | **NEEDS_DATA**: only position requirements record education. |
| Length of service | — | **NEEDS_DATA**: employment start dates are not recorded. |
| Headcount over time | — | **NEEDS_DATA**: headcount is not snapshotted; reconstructing it from assignment dates is unreliable for migrated records. |

## Movement

Transfers are classified from the viewer's scope: inbound (from outside),
outbound (to outside), internal (both ends inside). Employment-status changes
are attributed to the organization the person was assigned to on the day the
change took effect, so a retirement counts where the person worked.

## Not a performance league

Organization rows are in name order. Vacancy-rate attention is a configured
threshold per organization, not a ranking.
