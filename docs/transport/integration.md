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
No event bus / outbox exists in the platform; integration is through the audit log, notifications and the API. An accounting export of approved statements is `NEEDS_POLICY_DECISION_TRANSPORT_ACCOUNTING_EXPORT` (the statement PDF/CSV is the current hand-off).
