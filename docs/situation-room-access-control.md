# Executive Situation Room — Access Control

**Permission decides WHAT; organization scope decides WHERE.** Both are
enforced on the server for every request; the browser only hides links.

## Grants (`situation_room.*`)

| Grant | Opens |
| --- | --- |
| `view` | The launcher, Overview, Structure, Critical Attention, Trends, Data Quality. Required for everything else. |
| `wall.view` | Wall Mode. |
| `reports.view` | Executive Reports (exports). |
| `workforce.view` | Workforce, Movement. |
| `public_voice.view` | Complaints, Feedback, Tip-offs, Service Delivery. |
| `employee_breakdown.view` | The employee-level table inside one focused organization (audited per view). |
| `operations.view` | Daily Operations, Field Work. |
| `performance.view` | Performance (EPMS). |
| `cafeteria.view` | Cafeteria. |
| `cases.view` | Grievance, Discipline & Appeal summary (counts only). |
| `actions.view` / `actions.manage` | Read / write the Executive Action Register. |

The migration grants all of them to Super Admin, System Admin, City Admin and
Public Service Bureau Admin. Every other role receives them deliberately
through the role pages.

Panels inside a category are also gated: Overview, Trends, Critical Attention
and Data Quality show only the figures of domains the viewer holds.

## Scope

`SituationScopeResolver` asks `OrganizationScopeService` for the viewer's
organizations (self, subtree on the published hierarchy, or city-wide), and
intersects them with the subtree of the focused organization. A focus outside
the scope is refused with **403 before any query runs** — a forged
`organization_id` can narrow a view but never widen or shift it.

Hierarchy roll-up: a parent sees its descendants rolled up; a child never sees
its sibling or its parent. Tested on Bureau → Subcity A (Woreda A1, A2) /
Subcity B (Woreda B1) in `tests/Feature/SituationRoom/SituationRoomScopeTest.php`.

Users with no scope record are unrestricted — the existing semantics of
`OrganizationScopeService` (flagged in the handoff: assign a scope to every
non-city-wide account).

## Privacy rules

* No submitter name, contact, address, narrative or evidence ever enters a
  Situation Room payload or export (tested per category).
* Employee names appear only in `*_employees` tables: grant required, one
  focused organization required, name order, paginated, the viewer's own
  record excluded, every view audited (`situation_room.employee_breakdown_viewed`).
  They never reach the wall or an export.
* Small-group rule: where a count could point to a person (rating
  distributions, disciplinary and grievance caseload by organization), counts
  from 1 to k−1 are withheld and shown as "fewer than k" (`small_cell_threshold`,
  default 5).
* Cache keys include scope, filters and detail grants, so two viewers share an
  entry only when they would see exactly the same thing.

## Action Register

Listed, opened and changed only for organizations in scope; another scope's
action is a 404. The organization cannot be changed after creation. Create and
update are audited (`situation_room.action_created` / `action_updated`).
Actions are never deleted (cancel with an outcome note).

## Audit

`situation_room.employee_breakdown_viewed`, `situation_room.exported`,
`situation_room.action_created`, `situation_room.action_updated`. Aggregate
views are not audited individually.
