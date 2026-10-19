# Transport — Domain Model

| Concept | Table / model | Meaning | Is not |
| --- | --- | --- | --- |
| Provider | `providers` + `transport_provider_profiles` | a company carrying employees under contract | a user; a vehicle owner record only |
| Contract | `transport_contracts` (versioned) | the approved settlement model and rates for a provider (optionally one organization) | a tariff the system invents |
| Route | `transport_routes` | a named service between origin and destination, serving self / subtree / whole city | a trip |
| Stop | `transport_route_stops` | an ordered point on a route (pickup / drop-off, optional coordinates) | — |
| Schedule | `transport_schedules` | a recurring departure per route and direction on service days | a trip |
| Service calendar day | `transport_service_calendar_days` | a declared exception: suspended service or an operating holiday | — |
| Trip | `transport_trips` | one run of one route in one direction on one date, on a vehicle | a route, a schedule |
| Direction | `transport_trips.direction` (`inbound` morning, `outbound` evening) | morning and evening are distinct trips | — |
| Vehicle | `transport_vehicles` (approved capacity) | the bus; capacity bounds boarding | a trip |
| Driver | `transport_drivers` | the person assigned to a trip | an employee of the city |
| Device | `transport_devices` | a registered scanner with a revocable hashed credential, optionally bound to a vehicle | a user |
| Eligibility | `transport_eligibilities` | who may ride at the city's expense: an employee, or every employee of an organization (self / subtree) | a route assignment |
| Route assignment | `transport_passes` (+ boarding / evening stop) | an employee's assigned route and stops for a validity window | proof of riding |
| Boarding transaction | `transport_transactions` | one scan: accepted or refused, with trip, route, vehicle, direction, stop, device, method, eligibility snapshot | attendance, a charge |
| Exception | `transport_exceptions` | a recorded fact needing a look (overcapacity, duplicate, wrong route, substitution, manual boarding…) | an accusation |
| Invoice | `transport_invoices` | the provider's **claim** for a period | a verified figure |
| Settlement (statement) | `transport_settlements` | counts from verified evidence × contract snapshot; claimed, calculated, adjusted and approved amounts kept apart | the invoice |
| Adjustment / dispute | `transport_settlement_adjustments` | a signed correction (organization) or dispute (provider) on a statement, decided by a second person | an edit of evidence |

## Identity rules

* Employee and card identities come from Addis One; a transfer changes assignments, never `employee_id` or `public_card_uuid`.
* Every boarding is tied to Trip + Route + Vehicle + Employee (+ Direction, Device, Stop when known).
* Assigned ≠ eligible ≠ actual rider: reports and the statement count each separately.

## Lifecycles

See `state-diagrams.md`. Trips: `planned → assigned → boarding → in_progress → completed | cancelled | failed`. Invoices: `draft → submitted → under_review → approved | returned | disputed | withdrawn`. Statements: `draft → under_review → approved → paid | rejected | cancelled`. Eligibility / assignment: `active → suspended → active | ended`.

## Traceability chain

Employee → Eligibility (grant or pass) → Route assignment (pass + stops) → Trip (route, direction, vehicle, device) → Boarding (evidence with snapshot) → Reconciliation (claim vs verified) → Settlement (statement, adjustments, approval). Each hop is a foreign key or a snapshot on the next row; `docs/transport/data-dictionary.md` lists the columns.
