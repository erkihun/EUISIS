# Daily Activity security

## Three questions, three controls

| Question | Control |
|---|---|
| What may the user do? | Permissions (below) |
| Where? | `OrganizationScopeService`, for oversight and reports |
| Whose records? | Ownership for employees; effective reviewer assignments for reviewers |

`DailyActivityLogPolicy` evaluates these against the **stored record**, never against IDs in the request.

| Path to a log | Requires |
|---|---|
| Owner | The log's employee is the signed-in user's employee record, plus `view_own` |
| Reviewer | `view_team` and an effective reviewer assignment covering the log's snapshot organization or unit |
| Oversight | `view_scoped` and the log's snapshot organization within the user's organization scope |

Acting is narrower than seeing. Only the owner edits or submits, only an assigned reviewer approves or returns, and oversight users can read and report but not review. Review requests are authorized before validation, so an unauthorized user gets 403 and learns nothing about the form.

## Permissions

`daily_activities.view_own`, `create`, `update_draft`, `submit`, `resubmit` (employee); `view_team`, `review`, `approve`, `return_for_correction`, `reopen` (reviewer); `view_scoped`, `view_reports`, `export` (oversight); `manage_reviewers`, `daily_activity_settings.view` (administration). Reviewer assignments can only be created inside organizations the administrator's own scope covers.

The two administration areas are separate pages:
- **Activity Settings** (`/daily-activities/settings`) holds the city-wide rules, under `daily_activity_settings.view` and `update`.
- **Reviewer Assignments** (`/daily-activities/reviewers`) holds organization-scoped operational data, under `daily_activities.manage_reviewers`. Ending an assignment keeps it on record.

A reviewer manager without settings rights who opens the Settings URL is redirected to Reviewer Assignments.

## IDOR and mass assignment

- **Employee identity:** employee routes take no employee ID; the employee is resolved from the session.
- **Server-set fields:** organization, unit, position, assignment, status, reviewer and submission fields are set by the server and dropped from requests.
- **Item IDs:** honored only when they already belong to the log being saved.
- **Linked records:** position services and EPMS agreement items are re-validated against the employee, position, organization and date.
- **Reviewer lists:** built from the user's coverage; filters can narrow it but never widen it.

## Evidence

- **Storage:** on the private `local` disk, under a random file name. There are no public URLs.
- **Upload checks:** the `mimes` rule checks the detected file type and the `extensions` rule the client-supplied name; size is limited by `max_attachment_size_kb`.
- **Allowed types:** pdf, jpg, jpeg, png, webp, doc, docx, xls, xlsx, csv, txt.
- **Download:** every download checks the same `view` policy as the log and is always served as an attachment with `nosniff`, so a file never renders inline on the application's origin.
- **Retention:** evidence can be added or removed only while the log is editable, so evidence on a submitted or approved record is never silently replaced.
- **Malware scanning:** not available in the current infrastructure. Add an antivirus scan at upload when the hosting platform provides one.

## Privacy

- **Not public:** daily activity is not exposed on public pages or public APIs, and every route requires authentication.
- **Audit:** entries record titles and status changes, not full narratives; the full text lives in workflow history.
- **Exports:** carry registration and review data. Narratives appear only on the record itself.
- **Formula injection:** in CSV exports, text cells beginning with `=`, `+`, `-`, `@`, tab or carriage return are prefixed with an apostrophe. In xlsx exports, every string cell is written as typed text, so it never runs as a formula.

## Audit events

Created, updated, submitted, resubmitted, approved, returned, reopened, evidence uploaded and deleted, settings changed, reviewer assignment changed, and export performed (with report type, format, row count and period).
