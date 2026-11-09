# Employee Transfer Management

## Implemented canonical path

The announcement/application path is the canonical implemented transfer path:

`submitted -> under_review -> selected -> release/receiving/final approval (as configured) -> approved -> transferred`

`approved` is deliberately not `transferred`. Final selection approval creates one linked canonical `employee_transfers` movement record but does not alter an assignment. An authorized operator invokes implementation on or after the effective date. The implementation transaction locks the application, employee, source assignment, destination position establishment, and relevant occupancy records. It closes the source assignment on the preceding day, creates a successor assignment, moves the employee's current-assignment pointer, and writes capacity and immutable history snapshots.

Employee identity is not written by the transfer engine. The employee UUID and employee number remain unchanged.

## Screens

| Screen | Who | What it does |
| --- | --- | --- |
| Transfer Dashboard (`/transfers`) | anyone with transfer view access | Counts (each opens its filtered list when the viewer may see it), the viewer's own approval queues, recent implemented transfers. |
| Announcements (`/transfer-announcements`) | announcement permissions | List with search and status filter; row actions in the ⋮ menu. Create and Edit share one form (`Announcements/Form.tsx`): positions with a free approved slot, the application window (the closing day is included), optional eligibility rules with a control per rule type, and the documents applicants attach. Publishing is a separate confirmed action on the announcement page, offered only when the readiness check passes. |
| Applications (`/transfer-applications`) | `transfers.applications.view` in scope | Search by employee or application number, status filter, an announcement filter that can be cleared. The application page shows the progress through each configured approval stage, the transfer facts, the applicant's documents (downloaded through `transfer-applications.documents.download`), the approval chain and the review history. The next step leads the header; every rejection asks for a reason the applicant sees. |
| Transfer Settings (`/transfers/settings`) | `transfers.settings.manage` | One page (no tabs, so no error hides on another tab): switches for the rules and approval chain, settings-level documents, override approver roles chosen from the role list, post-transfer policies. |
| My Transfer Applications (portal) | the applicant | Each application with its progress, the reason when it was not selected, what was sent (cover letter and documents), and Withdraw while still allowed. |
| Apply (portal and public) | the applicant | One upload slot per required document (missing ones are caught before upload), reuse of an employee document where one exists, and the destination position when an announcement offers several. |

**Allow cross-institution transfer** off means an employee may apply only to positions in their own institution. (It was evaluated the other way round before, refusing same-institution moves.)

**NEEDS_DECISION:** *Require the same position* compares the destination position record with the applicant's current one, so it can only pass for a move into the very same position. If the intent is "same job" (title, occupation or grade), the rule needs that definition.

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
