# Field Work Security

- Ownership is always derived from the authenticated user's linked employee.
- Policies protect own, configured-supervisor, and organization-scoped access.
- Supervisory approval is resolved server-side from explicit effective authority; self-approval is refused.
- Reviewer assignments only cover descendant units when their explicit `include_sub_units` flag is enabled. Approval queues apply this authority in SQL and paginate the result rather than loading all pending requests.
- Request and participant identity/context fields are guarded and only written with server-derived values.
- Submission stores the resolved supervisor user/employee identifiers and display snapshots so later account, reporting-line, or employee changes cannot rewrite historical context.
- Transactions and database unique constraints protect participant duplication, request reference uniqueness, and workflow races.
- Audit records are written for create, submit, approve, return, reject, and completion.

Attachments are intentionally not implemented. If added, storage must be private and downloads must reuse the request policy to prevent IDOR.
