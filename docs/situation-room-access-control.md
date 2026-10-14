# Executive Situation Room — Access Control

**Permission decides WHAT; organization scope decides WHERE.** Both are
enforced on the server for every request; the browser only hides links.

## Grants (one set for the whole room)

| Grant | Opens |
| --- | --- |
| `situation_room.view` | The launcher, Overview, Structure, Critical Attention, Trends, Data Quality and the Action Register (read). Required for everything else. |
| `situation_room.wall_mode` | Wall Mode. |
| `situation_room.export` | Executive Reports (CSV/Excel/PDF of a category) and the queued Community Voice export. |
| `situation_room.view_employee_targeted` (+ `service_feedback.view_employee_target`) | Employee-level tables inside one focused organization and the targeted-employee pages (audited). |
| `situation_room.view_detail` | Submission text on the targeted-employee drill-down (audited). |
| `situation_room.manage_actions` | Record and update actions in the Action Register. |
| `situation_room.workforce.view` | Workforce, Movement. |
| `situation_room.public_voice.view` | Community Voice by Institution, Complaints, Feedback, Tip-offs, Service Delivery. |
| `situation_room.operations.view` | Daily Operations, Field Work. |
| `situation_room.performance.view` | Performance (EPMS). |
| `situation_room.cafeteria.view` | Cafeteria. |
| `situation_room.cases.view` | Grievance, Discipline & Appeal summary (counts only). |
| `system-settings.manageSituationRoom` | System Settings › Situation Room. |

The first six come from `2026_10_14_130000_register_situation_room_permissions`;
the per-domain grants from `2026_10_14_300000_create_situation_room`, which
also gives `public_voice.view` to every role that already held
`situation_room.view` (so nobody loses Community Voice) and gives the whole set
to Super Admin, System Admin, City Admin and Public Service Bureau Admin.
Every other role receives grants deliberately through the role pages.

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
  Requires `situation_room.view_employee_targeted` and
  `service_feedback.view_employee_target`.
  They never reach the wall or an export.
* Small-group rule: where a count could point to a person (rating
  distributions, disciplinary and grievance caseload by organization), counts
  from 1 to k−1 are withheld and shown as "fewer than k" (`small_cell_threshold`,
  default 5).
* Cache keys include scope, filters and detail grants, so two viewers share an
  entry only when they would see exactly the same thing.

## Action Register

One register for the whole room (`situation_room_actions`, append-only
history in `situation_room_action_events`; see
[situation-room-security.md](situation-room-security.md)). Category panels
link into it with `source_type = panel` and `source_reference =
"<category>:<panel key>"`, once the view is focused on one institution.
Actions are listed and changed only for institutions in scope; recording one
never changes the records it is about.

## Audit

`situation_room.employee_breakdown_viewed`, `situation_room.exported`, and the
Community Voice / Action Register events (`situation_room.detail_viewed`,
`export_requested`, `action_created`, `action_assigned`,
`action_status_changed`). Aggregate views are not audited individually.
