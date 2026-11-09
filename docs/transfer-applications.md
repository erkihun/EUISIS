# Transfer Applications

Employees apply only through their authenticated employee profile. Submission requires an open published announcement, an active current assignment, duplicate protection, and deterministic eligibility evaluation on the server.

The application stores an eligibility/context snapshot including employee number, source assignment, organization, unit, position, grade, and applied rules. It does not modify employee identity, employee number, assignment, or capacity.

The current policy is one active application per employee per announcement. Required document types are enforced and may be fulfilled with a private snapshot of a same-type employee document. There is no grace period after close. Withdrawal requires a reason through verification only and cannot reverse selection, approval, or a created transfer. Persisted drafts remain a deliberate future policy decision because retention and resubmission rules have not been approved.
# Transfer applications

An employee application is a submission-time record, not an assignment change. The application stores the authenticated employee, current assignment, source snapshot, destination snapshot, selected announcement position, deterministic eligibility snapshot, and submission time. Browser input cannot set those authoritative fields or the workflow status.

For multi-position announcements, exactly one advertised `transfer_announcement_positions` row is selected. The existing database constraint keeps the current policy of one active application per employee per announcement. The selected row is carried into canonical transfer creation and implementation.

Applications remain private to their applicant and authorized HR users. Employee lists are paginated and scoped to the signed-in employee.
