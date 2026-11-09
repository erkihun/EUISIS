# Employee Transfer Security

## Access controls

Transfer application routes are protected by `auth`, verified-email, MFA, forced-password-change, and admin middleware. Policies further require explicit transfer permissions and organization scope:

- application view: applicant self-service or `transfers.applications.view`/`transfers.viewAny` with releasing or receiving scope;
- release approval: `transfers.release.approve` plus releasing-organization scope;
- receiving approval: `transfers.receiving.approve` plus receiving-organization scope;
- final approval and implementation: their own permissions plus application visibility;
- direct transfer draft: employee source scope through policy plus destination organization scope in the controller.

## Data protections

- Transfer implementation never updates employee identity or employee number.
- Audit events record identifiers, statuses, and assignment snapshots, not credentials or document contents.
- Application documents are stored as application records and must continue to use the existing authorized document-delivery path; do not expose storage paths in clients.
- The transaction uses row locks and a unique implementation-assignment link to protect against double implementation and capacity races.

## Operational controls

Only implementation can create the successor assignment. Approval is intentionally non-mutating for employment assignment data. Failed implementation reasons are limited to 1,000 characters and must remain operational, not contain sensitive document or credential data.

**NEEDS_DECISION:** require formal segregation-of-duties policy (whether a final approver may also implement), audit-log retention/legal hold requirements, and the emergency override authorization path.
