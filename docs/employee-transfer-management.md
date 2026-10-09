# Employee Transfer Management

## Implemented canonical path

The announcement/application path is the canonical implemented transfer path:

`submitted -> under_review -> selected -> release/receiving/final approval (as configured) -> approved -> transferred`

`approved` is deliberately not `transferred`. Final selection approval creates one linked canonical `employee_transfers` movement record but does not alter an assignment. An authorized operator invokes implementation on or after the effective date. The implementation transaction locks the application, employee, source assignment, destination position establishment, and relevant occupancy records. It closes the source assignment on the preceding day, creates a successor assignment, moves the employee's current-assignment pointer, and writes capacity and immutable history snapshots.

Employee identity is not written by the transfer engine. The employee UUID and employee number remain unchanged.

## Data and integrity rules

- A destination position must be active, effective on the transfer date, and belong to the receiving organization.
- An approved destination establishment with available capacity is required at implementation time.
- An approved application whose source assignment changed after approval is blocked for review; it cannot overwrite the newer assignment.
- Retrying a successfully committed implementation is idempotent.
- A failed implementation rolls back assignment, occupancy, agreement, audit, and transfer-history writes together. A concise failure reason is retained on the application for operational follow-up.
- `employee_transfers.current_assignment_id` remains the source assignment; `destination_assignment_id` is the successor. Both snapshots are retained for traceability.

## Direct administrative transfer

`POST /employees/{employee}/transfers` still creates only a legacy `employee_transfers` draft. It now locks the employee while creating the draft, validates destination scope in the controller, accepts a future effective date and destination position, and rejects positions outside the destination organization.

**NEEDS_DECISION / production blocker:** the legacy direct draft workflow has no approval-and-implementation route and therefore is not yet converged with the canonical application engine. Do not use it to make an operational transfer. Either retire the endpoint in favour of an internal announcement/application, or define its required approval chain and map it to the same implementation service.

## Explicit policy decisions still required

- Official transfer-number format and the governing code-rule scope; no transfer code-rule entity exists today.
- Whether future-dated capacity is reserved at approval or only asserted at implementation. The current safe choice is assertion at implementation; capacity can change before the effective date.
- Whether same-organization unit moves must use this workflow and how their destination unit is selected.
- Required documents, retention, and whether an attachment-verification failure blocks selection.
- Notification recipients, templates, escalation SLA, and which external payroll/finance integrations are authoritative.
