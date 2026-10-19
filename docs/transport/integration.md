# Transport — Integration Points

## Inbound
* **Device API** `/api/v1/transport` (bearer): `GET device`, `POST device/heartbeat`, `GET trips/today`, `GET trips/{trip}`, `POST trips/{trip}/start|depart|complete`, `POST boardings/verify`, `POST boardings/sync` (offline), `POST trips/{trip}/positions` (gps). Versioned under `v1`; payloads in `app/Http/Requests/Api/Transport`.
* **Provider portal** and **admin** pages (Inertia).

## Outbound / internal
* Audit events `AuditEventType::Transport*` (24).
* Employee notifications `TransportNotification` kinds: `route_assigned`, `route_changed`, `assignment_suspended`, `trip_cancelled`, `schedule_changed`, `service_alert` (database + the employee's channels via `DailyActivityNotifier::userFor`).
* Jobs: `ReevaluateTransportEligibility`, `NotifyTransportTripCancelled` (after commit).
* Scheduler: `transport:plan-trips --days=2` daily 00:20.
* Telemetry: `transport.*` metrics and the `transport` health component.
* Situation Room category `transport`; Intelligence data-quality panel `quality_transport`.

## Not provided (by design)
The platform's transactional outbox and webhooks (`app/Services/Integration/Events`, `EventCatalog`) publish master-data events (organizations, units, positions, employees) to partners; transport events are not in the catalog — whether providers may subscribe to trip or statement events is `NEEDS_POLICY_DECISION_TRANSPORT_EVENTS`. Transport integration today is the audit log, notifications and the device API. An accounting export of approved statements is `NEEDS_POLICY_DECISION_TRANSPORT_ACCOUNTING_EXPORT` (the statement PDF/CSV is the current hand-off).
