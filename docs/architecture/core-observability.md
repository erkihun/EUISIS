# Core observability

What exists to watch Core, and what is still missing for a separate Core
application. No new monitoring stack was added; this maps what is in place.

## In place

| Signal | Where |
| --- | --- |
| Liveness / readiness | `/up`, `/health/live`, `/health/ready` |
| Request correlation | `X-Request-ID` on every response (`RequestCorrelationId`); stored on audit rows; Audit logs page filters by request ID |
| Request counts, status classes, latency buckets by route group | `RecordRequestTelemetry` → Intelligence › System health (no URLs or identifiers stored) |
| Audit of every Golden Path change | `audit_logs` (actor, person or system principal, organization, request ID); Core → Audit Center; sealed daily, `audit:verify` detects tampering |
| Partner API use | `api_request_logs`, Core → Integration Center (applications, last use, token expiry) |
| Outbox backlog and webhook health | Integration Center: events waiting, failed deliveries, degraded subscriptions |
| Referential integrity | `php artisan backbone:audit-integrity`, Core dashboard data-quality figure, Audit Center integrity view |
| Duplicate-master evidence | `php artisan backbone:duplicate-masters` |
| Backups | Backup page and reports ([../backup-recovery-architecture.md](../backup-recovery-architecture.md)) |
| Alerts | Intelligence alerts ([../intelligence-alerts.md](../intelligence-alerts.md)) |

## Core-specific watch list

- sign-in failures and lockouts (Audit Center);
- 403 / 423 rates on Core routes (System health status classes);
- ID-card verification failures and suspicious verification bursts (Audit Center › Card verifications);
- change requests waiting for approval and their age (Core dashboard);
- outbox events waiting and oldest age; webhook failures;
- integrity findings count (should stay at zero).

## Missing before a separate Core application

- Central log shipping and retention for two applications (no log stack is configured in this repository; it is a hosting decision).
- Cross-application tracing beyond request IDs.
- Alert routing to an on-call rota (owners are named in [../go-live/core-acceptance-matrix.md](../go-live/core-acceptance-matrix.md) once signed).
- Measured service-level objectives: none are claimed until measured on the production hardware.
