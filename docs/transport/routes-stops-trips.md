# Transport — Routes, Stops, Schedules and Trips

## Routes
A route belongs to one provider and serves `self` (one organization), `subtree` (an organization and everything under it) or `citywide`. `TransportRouteAccessService` answers "does this route serve this employee's organization" from the published hierarchy closure. Inactive routes refuse boardings (`route_inactive`).

## Stops
Ordered `transport_route_stops` (sequence, code, EN/AM names, optional coordinates, pickup/drop-off flags, validity). Edited on the route page (admin and portal). Removal is a soft delete so assignments that named the stop keep their history. A scan may record the stop; a stop not on the route is refused (`stop_not_on_route`).

## Schedules and the service calendar
A schedule is a recurring departure for one route and direction on service days (1–7), with a planned departure/arrival, default vehicle/driver, validity, and whether it skips public holidays. `transport:plan-trips --days=N` (nightly 00:20) creates one `planned` trip per schedule per service day — never twice (`tt_one_trip_per_schedule_date` partial unique index) — honouring `PublicHolidayCalendar` and `transport_service_calendar_days` (`operating` on a holiday, `suspended` on a working day; per provider, per route or city-wide).

## Trips
One run of one route in one direction on one date. Fields: vehicle, driver, planned/actual times, capacity (falls back to the vehicle's approved capacity), `boarded_count` (maintained by the engine), `expected_riders` (assigned employees on the route at planning time), `is_extra` (planned outside the schedule by staff, with `approved_by`).

### Lifecycle (`TransportTripStatus`)
```
planned ──assign──▶ assigned ──start──▶ boarding ──depart──▶ in_progress ──complete──▶ completed
   │                   │                   │                    │
   └──cancel──────────┴──cancel───────────┴──cancel/fail──────┴──cancel/fail──▶ cancelled | failed
```
* `start` needs a vehicle; boarding is accepted in `boarding` and `in_progress` only (`isBoardable()`).
* `complete` reconciles `boarded_count` from verified rows and records distance/notes.
* `cancel` / `fail` need a reason, notify assigned employees (`NotifyTransportTripCancelled`), raise an exception and keep any boardings as evidence (excluded from settlement).
* `replace_vehicle` records a `transport_trip_vehicle_changes` row; earlier boardings keep the first vehicle.
* Audit events: `transport_trip.created / dispatched / started / completed / cancelled / vehicle_replaced`.

### Dispatch surfaces
Admin: Transport › Trips › trip page (`TripDispatchPanel`). Portal: Trips › trip page, the boarding terminal's one-tap *Start boarding / Depart / Complete*, and the device API (`POST trips/{trip}/start|depart|complete`).

### Manifest
Who boarded a trip (`TransportReportService::manifest`): accepted, refused and voided rows with time, stop, method and sync state. Staff tier adds the organization and manual reasons; the provider tier shows name and number only. PDF in EN/AM with Ethiopian dates.
