# Employee Transfer Integrations

## Current synchronous effects

On a committed canonical transfer, the system:

- closes applicable employee performance agreements through `EmployeeAgreementService`;
- recalculates entitlements when the configured transfer setting requires it;
- creates a card reprint request when configured and evaluates the current-card snapshot for assignment-field changes;
- releases source occupancy and creates destination occupancy;
- writes assignment and transfer-completed audit records.

All of these execute inside the database transaction except observer/notification work scheduled by the existing services. A failure in a required synchronous operation aborts the assignment change.

## Notifications and external systems

No new notification, payroll, finance, timekeeping, or identity-provider message was invented. Existing card and employee-portal notification services remain the only connected effects.

**NEEDS_DECISION:** define event ownership, delivery channel, retry/dead-letter handling, recipient rules, idempotency keys, payload schema, and acknowledgement/reconciliation obligations before connecting payroll, finance, timekeeping, or external identity systems. Until then, operators must reconcile those systems from the completed transfer audit/history record.
