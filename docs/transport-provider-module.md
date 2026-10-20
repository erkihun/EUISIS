# Transport Management

Transport Management records who boards which provider's vehicles, on which
route and trip, against a pass the city issued. It is live by default
(`config/features.php`, `FEATURE_TRANSPORT=false` switches it off without
touching data).

## Verified usage (October 2026)

The module now records verified usage end to end — trips with morning/evening
direction, stops, schedules, scanner devices, a single QR/NFC boarding engine,
manifests, contracts, invoices, statements and reconciliation. The detailed
documentation lives in `docs/transport/` (start with `architecture.md`,
`business-rules.md` and `production-readiness.md`).

## Who does what

| Who | Where | What |
| --- | --- | --- |
| City staff with `transport-*` permissions | `/transport/*` (sidebar › Transport) | Register providers (optionally with a portal account), define routes and who may ride them, keep vehicles and drivers, issue passes, scan at a desk terminal, read boardings and reports, set the scan rules. |
| Provider users (owner, manager, operator) | `/provider/portal/transport/*` | Plan trips, keep their own routes, vehicles and drivers, scan cards at the door (phone camera or handheld scanner), read their own boardings and reports. |

Default roles: **Transport Admin** (every `transport-*` permission, scoped)
and **Transport Operator** (scan, plus read-only boardings and passes). Provider owners and managers hold
every provider-user key; an operator holds only the keys granted to them
(`provider.transport.scan`, `.routes.manage`, `.vehicles.manage`,
`.drivers.manage`, `.trips.manage`, `.reports.view`). Every portal page is
gated by its key (`provider.permission` middleware) and the portal menu shows
only what the user may open.

## Data

- Provider identity in `providers`, service enablement in `provider_services`
  (`service_types.code = transport`), the transport profile in
  `transport_provider_profiles`. Provider users live in `provider_users`, never
  in `users`.
- `transport_routes`, `transport_vehicles`, `transport_drivers`,
  `transport_trips`, `transport_passes`, `transport_transactions` (one row per
  scan, accepted or refused).
- `transport_routes` was first created by the domain foundation with a NOT NULL
  `transport_plan_id` and `name`; `2026_10_14_400000_repair_transport_routes_legacy_columns`
  makes both nullable and adds the per-provider route-code unique index
  (additive; no data is removed).

## Who may ride

A route serves:

- **Whole city** (`citywide`, or no organization);
- **Selected organization only** (`self`);
- **Selected organization and everything under it** (`subtree`, along the
  published hierarchy's closure table).

Only city staff decide this; the portal cannot set or widen it. A pass names an
employee, a provider and optionally one route, with a validity window. One
employee holds at most one active pass per provider and route over any period,
and a pass can only name a route that serves the employee.

## A boarding scan

`TransportQrScanService`, for both the portal and the admin terminal:

1. A repeated `scan_nonce` replays the provider's own earlier answer (never a
   second row); a nonce another provider used is refused without revealing
   anything. Concurrent duplicates are caught at the unique index.
2. The **server clock** dates the scan; a device's `scanned_at` is stored in
   `metadata.client_scanned_at` only.
3. A trip must be the provider's, on the chosen route (or it supplies the
   route), boarding (`scheduled`/`boarding`), dated today and not full. An
   accepted boarding increments `boarded_count` under a row lock; the same
   person cannot board the same trip twice.
4. The card must resolve to an active employee with an active card
   (`EmployeeServiceEligibilityService`), the employee must have a current
   placement, the route must be running and serve the employee.
5. A pass is needed unless the settings say otherwise:
   - **Accept boarding without a pass as pay-as-you-go**: accepted with
     `result_code = pay_as_you_go` (billable);
   - **Require an active pass to board** off: accepted with `pass_not_required`;
   - otherwise refused with `active_transport_pass_missing`.
6. Only a SHA-256 of the scanned value is stored, never the value.

Every result code has a message in English and Amharic (`lang/*/transport.php`
`reasons`, `resources/js/i18n/*/transport.ts` `reasons`).

## Scope and privacy

Passes, boardings and reports name employees, so staff see only employees in
their organization scope (`OrganizationScopeService::employeeIdsInScope`),
and pass forms search employees as you type (`lookup.employees`, scoped)
instead of listing them. Organization pickers search within scope too
(`transport.organizations.lookup`). Providers, routes, vehicles and drivers are
the city's shared catalogue. Exports stream in chunks and are formula-safe
(`csv_safe_row`).

## Reports

`TransportReportService` serves the admin report (scoped to the viewer) and
the portal report (scoped to the provider): totals, accepted/refused, distinct
riders, pay-as-you-go boardings, and breakdowns by provider, route, refusal
reason and day. The default window is the last 30 days. **Boardings** lists
every scan with the same filters and a CSV export.

## Verification

- `tests/Feature/Transport` — the scan rules, portal tenant isolation and
  permissions, admin authorization, scope and validation, reports and exports
  (green on SQLite and PostgreSQL).
- `tests/Feature/Release/DisabledModuleGateTest.php` — the flag.
- End to end in a browser (390 px and 1440 px, English and Amharic): register
  a provider with a portal account, scope a route, add a vehicle and driver,
  issue a pass, replace the one-time password in the portal, plan and edit a
  trip, scan (accepted, then refused as already boarded), read boardings and
  reports on both sides.
