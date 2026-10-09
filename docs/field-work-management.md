# Field Work Management

Field Work records temporary, approved official work away from an employee's ordinary workplace. It is not an employee transfer, leave, training, remote work, travel/mission, or a biometric attendance event.

`field_work_requests` stores the requester assignment and organization/unit/position name snapshots taken at creation. A later transfer cannot rewrite the record. `field_work_participants` is relational and uniquely constrains one employee per request. Destination data supports registered organizations, external organizations, field sites, and other locations; external destinations never create Organization master data.

The employee identity is derived from the authenticated account. Browser payloads cannot choose the employee, assignment, snapshot organization, reference number, status, or approver. Reference numbers are transactionally sequenced per Gregorian year (`FW-YYYY-NNNNNN`).

GPS check-in and check-out are implemented as separate immutable, event-based
records; they never start continuous location tracking. See
`field-work-gps.md` and `field-work-check-in-out.md` for the applicable policy
and privacy boundary. File attachments/evidence are not implemented and must
remain private and policy-authorized if added.

## Current delivery boundary

The current delivery includes employee draft/submission, configured supervisor
approval, immutable check-in/check-out events, participant sessions, completion,
and a read-only attendance reconciliation seam. It does not claim to provide
team request authoring, a Field Work type administration UI, dashboards,
calendars, reports/exports, or scheduled overdue notifications. Those surfaces
must not be represented as live navigation until their routes, scope checks, and
tests exist.
