# Field Work Workflow

`draft → pending_supervisor_approval → approved → completed` is the normal path. A supervisor can instead return a request for correction or reject it. Returned requests are editable and can be submitted again; approved and completed records are not editable.

The project has no supervisor relationship on positions or units. The resolver therefore uses effective, explicit Daily Activity reviewer assignments, ordered from employee-specific through unit and organization coverage. It never authorizes an arbitrary user just because they hold a Manager role. If no configured supervisor is found, submission stops with `SUPERVISOR_NOT_RESOLVED` rather than auto-approving.

Review and completion re-lock the request row and re-evaluate authority, preventing stale double decisions. The pending queue is restricted to the resolving supervisor.

## Needs decision

- Native position/unit supervisor or functional reporting-line precedence.
- Retroactive/emergency requests, post-start cancellation, amendments, and team member replacement.
- Team creation authority and per-participant approval model.
