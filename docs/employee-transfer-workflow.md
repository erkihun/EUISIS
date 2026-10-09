# Employee Transfer Workflow

## State model

| State | Meaning | Assignment change |
| --- | --- | --- |
| submitted / under_review | Candidate application and screening | No |
| selected | Candidate and effective date captured | No |
| release_pending / receiving_pending / final_approval_pending | Configured consent gates | No |
| approved | Every configured consent passed | No |
| transferred | Authorized implementation committed | Yes |
| implementation_failed | Operationally visible failure metadata; approval remains reviewable | No |
| rejected / withdrawn / cancelled | Terminal business outcome | No |

At selection, a user may supply an effective date; an omitted value is recorded as today for compatibility. The UI prevents past dates and the implementation action rejects dates still in the future.

## Approval separation

The final approval action only updates the approval record, application status, and `approved_at`. It does not create an assignment. This prevents an approval click from bypassing effective-date, current-assignment, position, and capacity validation.

## Implementation checks

The `transfers.complete` permission plus access to the application is required. In one database transaction the system verifies that the employee still points to the approved source assignment, the source is active, the source period precedes the effective date, the destination position belongs to the receiving organization, and an approved establishment has capacity. It then writes the successor assignment and transfer history.

## Rejection, withdrawal, cancellation, and override

Existing application rejection and applicant withdrawal remain available before implementation. A rejected/withdrawn/cancelled application cannot be implemented. Exceptional eligibility overrides are represented by the existing transfer rule-override records; the legal basis, approver role, and expiry policy remain **NEEDS_DECISION**.

There is no automatic retry. Operators must correct the capacity/assignment issue and invoke implementation again; the latest non-sensitive failure reason is retained.
