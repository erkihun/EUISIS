# Recycle Bin Architecture

The Recycle Bin uses Laravel soft deletes for supported configuration and business records. Delete means "move to Recycle Bin"; it does not permanently remove records.

## Supported Records

| Type | Model | Delete Behavior | Restore Behavior | Code Reuse |
|---|---|---|---|---|
| Organization Units | `OrganizationUnit` | Soft delete, mark archived | Restore and mark active | Codes remain reserved |
| Organization Unit Types | `OrganizationUnitType` | Soft delete, mark inactive | Restore and mark active | Codes remain reserved |
| Positions | `Position` | Soft delete, mark inactive | Restore and mark active | Codes remain reserved |
| Service Types | `ServiceType` | Soft delete, mark inactive | Restore and mark active | Codes remain reserved |
| Entitlement Rules | `EntitlementRule` | Soft delete, mark inactive | Restore and mark active | Names remain reserved by validation rules |
| Code Rules | `CodeRule` | Soft delete, mark inactive | Restore if no active scope conflict exists | Scope uniqueness remains protected |

## Excluded Records

Audit logs, employees, employee assignments, ID cards, card requests, card verifications, service transactions, approvals, published hierarchy versions, and government history tables are not deleted through Recycle Bin. They are historical records and must use lifecycle status, cancellation, reversal, or read-only workflows.

## Permissions

Recycle Bin access requires `recycle-bin.view`. Restore requires `recycle-bin.restore` plus the module restore permission, such as `service-types.restore`.

Supported module delete permissions are seeded alongside legacy archive permissions:

- `positions.delete`, `positions.restore`, `positions.viewDeleted`
- `service-types.delete`, `service-types.restore`, `service-types.viewDeleted`
- `entitlement-rules.delete`, `entitlement-rules.restore`, `entitlement-rules.viewDeleted`
- `code-rules.delete`, `code-rules.restore`, `code-rules.viewDeleted`
- `organization-units.delete`, `organization-units.restore`, `organization-units.viewDeleted`
- `organization-unit-types.delete`, `organization-unit-types.restore`, `organization-unit-types.viewDeleted`

## Audit

Every delete writes `record_deleted`. Every restore writes `record_restored`. Legacy module-specific archive events are retained where existing tests and dashboards still use them.

## UI

User-facing destructive actions for supported records are labeled Delete. Permanent delete is disabled in the UI and not routed by default.
