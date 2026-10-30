# Service Delivery Monitoring — security

Companion to [service-monitoring.md](service-monitoring.md). Every rule here has a test in
`tests/Feature/ServiceMonitoring`.

## Scope rules

All checks live in `Modules/ServiceMonitoring/Application/Services/ServiceMonitoringAccess.php` and
are called by the module's policies; controllers never decide access on their own. Each check pairs
the permission with `OrganizationScopeService`, always against the **record**: the submission's
organization snapshot, the participant's organization, the action item's owner organization. Ids
sent by the browser are never trusted for scope.

| Action | Needs |
| --- | --- |
| See a report | `view` and the organization in scope, or an assigned support officer, or city-wide reach |
| Edit a report | `submit`, organization in scope, the current revision in draft or returned, period not locked |
| Submit | edit rights and `attest` (checked again in the action) |
| Review a woreda report | `review` and the woreda in scope (the sub-city's reviewers) |
| Review a sub-city or center report | `review` and city-wide reach |
| Review one's own submission | never |
| Certify a sub-city | `consolidate` and the sub-city in scope |
| Lock, reopen, schedules, registry, settings | the permission and city-wide reach |
| Verify digital services | `technology.verify` and city-wide reach |
| Clarifications | the institution in scope opens and reads; assigned support officers (or city-wide `support.respond`) answer |
| Visits | assigned support officers (or city-wide `visits.manage`) manage; the institution reads |

City-wide reach = an unrestricted role (Super Admin, System Admin, City Admin, Public Service Bureau
Admin, or a global role without scope records) or an active city-wide scope record. Scoped roles
fail closed, as everywhere in the platform: a scoped account without scope records reaches only its
own placement.

Lists are filtered in SQL (`scopeParticipants`, `scopeSubmissions`) before anything is counted.
Sibling woredas and other sub-cities never see each other's reports (`AuthorizationTest`). Child rows
(Section I and III rows) are bound through their submission (`scopeBindings`): a row of another
submission answers 404.

## Named data (Section I)

- `full_name`, `reason_detail` and `action_detail` are encrypted at rest (`EncryptedOrPlaintext`);
  snapshots keep the ciphertext, and only a named report decrypts.
- Visible only with `accountability.view_named` within scope; everyone else sees the subject type,
  role, reason category and final action. A reporter can enter rows without seeing them.
- Every page view that shows names writes `service_monitoring.named_data_viewed`.
- Exports include names only when asked and the requester holds `accountability.export_named`;
  otherwise the role label replaces the name.
- Both grants are withheld from City Admin and Public Service Bureau Admin.
- Audit entries for Section I hold categories and ids, never names or details.
- The module exposes no API.

## Uploads

Stamped copies and action-item evidence (`AttachmentStore`):

- type decided by **content sniffing** (`finfo`), allow-list PDF, JPEG, PNG; at most 10 MB;
- random storage name on the private `local` disk; the client's file name is kept only as metadata;
- sha256 recorded and audited;
- served only by `AttachmentController` after the attachment policy (whoever may see the submission
  or the action item), with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.

## Immutability

- Status, attestation and lifecycle columns are never filled from request input; Form Requests
  validate the business fields only and the actions set the rest.
- `SubmissionGuard` refuses every write in a locked period and on any submission that is not the
  editable current revision. Accepted and locked data cannot be changed through any endpoint
  (`SubmissionWorkflowTest`, `ConsolidationTest`).
- `SubmissionHistory` and `ConsolidationSnapshot` throw on update and delete; snapshots carry a
  sha256 of their payload and a failing checksum stops the report.
- Corrections never edit an accepted revision: they create revision n+1.

## Other controls

- Throttling on every write, upload, export and reminder-triggering action.
- No raw SQL with interpolated input: aggregates use the query builder with bindings.
- Plain text everywhere (no rich text); Inertia's CSRF and escaping defaults apply.
- XLSX cells are written as text, so a value starting with `=` never becomes a formula.
