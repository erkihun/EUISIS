# Situation Room — security and privacy

## Permissions

| Permission | Grants | Default holders |
|---|---|---|
| `situation_room.view` | Overview, institution drill-down, Action Register list | None (Super Admin through `Gate::before`) |
| `situation_room.view_employee_targeted` | Counts per employee. Also needs `service_feedback.view_employee_target`. | None |
| `situation_room.view_detail` | Subject and message on the employee drill-down | None |
| `situation_room.export` | Queue and download institution exports | None |
| `situation_room.wall_mode` | The wall display | None |
| `situation_room.manage_actions` | Record and update actions | None |

The permissions are registered by migration `2026_10_14_130000` and assigned
to no role. Assigning them is NEEDS_DECISION D1. Group labels and EN/AM
descriptions live in `database/seeders/data/permissions.php`.

## Server-side checks

- Every controller action checks its permission before reading anything.
- An institution in the URL or query string must be in the user's scope
  (`SituationRoomScope::for`), or the request is a 403. Changing the id is not
  enough. A malformed id is a 404.
- Action create and update check the institution again (`canAccess`). The
  owner is checked by `SituationRoomActionService::assertOwner`:
  - A person must have that institution in their scope.
  - A unit must belong to it.
- The employee drill-down filters submissions by the same scope. It returns
  404 when the leader has none about that employee and the employee is outside
  their scope.
- Exports are rebuilt by the queued job **as the requester**, so their grants
  apply. A requester who has lost `situation_room.export` gets a failed export.

## What never leaves the server here

| Data | Where it can be seen instead |
|---|---|
| Submitter name, contact, identity mode | The submission's own page, with `service_feedback.view_submitter_identity` |
| Attachments | The submission's own page, with `service_feedback.view_sensitive_attachments` |
| Subject and message | Employee drill-down with `situation_room.view_detail` (audited), or the submission's own page |
| Employee photos | Not used anywhere in the Situation Room |

The Wall payload carries institution names, category names and counts only.
A test asserts that it contains no message text, no visitor name and no
employee name.

## Cache isolation

The cache key is `situation-room:v1:` + sha1 of:

- the scope signature:
  - calculation version
  - published hierarchy version
  - institution in view
  - sorted authorized organization ids, or "all"
  - visible target types
  - the viewer's employee id (own submissions are excluded)
- the normalised filters
- the section name

The supporting section also adds the viewer's module grants. Two users share a
cached value only when every one of these matches. A test checks that a bureau
head and a woreda head never receive each other's figures.

## Audit

| Event | When | Recorded |
|---|---|---|
| `situation_room.detail_viewed` | Employee drill-down rendered with submission text | Employee id, submission ids shown, filters. Never the text. |
| `situation_room.action_created` | Action recorded | Title, status, priority, owner, due date, source |
| `situation_room.action_assigned` | Owner changed | Old and new owner reference |
| `situation_room.action_status_changed` | Status changed | Old and new status |
| `situation_room.export_requested` | Export built | Report, format, row count, filters |
| `service_feedback.settings_updated` | Rules changed in Public Feedback settings | Old and new values (existing event) |

Action history (`situation_room_action_events`) is append-only: the model
refuses updates and deletes.

## Exports

- Aggregate institution rows only: code, institution, and counts per type and
  state.
- They go through the existing `PublicFeedbackSubmissionsExport` writer:
  - CSV cells pass through `csv_safe_row()`. A name such as `=HYPERLINK(...)`
    is written as `'=HYPERLINK(...)` (tested).
  - XLSX strings are bound as text.
- The file is stored on the private `local` disk and served with
  `Cache-Control: private, no-store` and `nosniff`.
- Only the requester can download it. Policy:
  `PublicFeedbackReportExportPolicy::download`, where the `report` column is
  not `submissions`.
- Situation Room exports do not appear in the Submission Reports list, and
  submission exports do not appear here.

## Rate limits

| Route | Limit |
|---|---|
| Refresh | 30 per minute |
| Institution search | 120 per minute |
| Owner search | 120 per minute |
| Export request | 10 per minute |
