# UI Module Map

Last updated: 2026-05-12

## Core

- `dashboard` -> `/dashboard`
- `organizations` -> `/organizations`
- `organization-types` -> `/organization-types`
- `employees` -> `/employees`
- `employee-transfers` -> `/employee-transfers`
- `positions` -> `/positions`
- `id-cards` -> `/id-cards`
- `card-requests` -> `/card-requests`
- `print-batches` -> `/print-batches`

## Services

- `service-types` -> `/service-types`
- `service-providers` -> `/service-providers`
- `entitlement-rules` -> `/entitlement-rules`
- `entitlements` -> `/entitlements`

## Administration

- `users` -> `/users`
- `roles` -> `/roles`
- `permissions` -> `/permissions`
- `system-settings` -> `/system-settings`

## Court Cases (planned)

- `court-cases` -> `/court-cases` (placeholder only; see `docs/court-cases.md`)

## Monitoring

- `audit-logs` -> `/audit-logs`

## Backlog Modules Without UI

- `api-clients`
- `device-bindings`
- `approval-workflows`
- `approval-steps`
- `approvals`
- `task-assignments`
- `card-verifications`
- `transport-*`
- `coupon-*`
- `cafeteria-transactions`
- `consumer-*`
- `support-tickets`
- `notifications_foundation`

## Service Delivery Monitoring & Support

Feature flag `service_monitoring`; sidebar group "የአገልግሎት አሰጣጥ ክትትልና ድጋፍ" (docs/service-monitoring.md).

- `service-monitoring.dashboard` -> `/service-monitoring`
- `service-monitoring.submissions.*` -> `/service-monitoring/submissions` (entry screen `/{submission}/edit`, read-only `/{submission}`)
- `service-monitoring.review.queue` -> `/service-monitoring/review`
- `service-monitoring.consolidation.sub-city` -> `/service-monitoring/consolidation/sub-city`
- `service-monitoring.consolidation.city` -> `/service-monitoring/consolidation/city`
- `service-monitoring.verification.index` -> `/service-monitoring/verification`
- `service-monitoring.periods.*` -> `/service-monitoring/periods`
- `service-monitoring.participants.index` -> `/service-monitoring/participants`
- `service-monitoring.clarifications.*` -> `/service-monitoring/clarifications`
- `service-monitoring.visits.*` -> `/service-monitoring/visits`
- `service-monitoring.action-items.index` -> `/service-monitoring/action-items`
- `service-monitoring.reports.index` -> `/service-monitoring/reports`
- `service-monitoring.settings.edit` -> `/service-monitoring/settings`
