# Transport — Eligibility and Route Assignment

## Two different things

| | Eligibility grant (`transport_eligibilities`) | Route assignment (`transport_passes`) |
| --- | --- | --- |
| Says | this employee / every employee of this organization (self or subtree) may ride at the city's expense | this employee rides **this provider's route**, boarding at **this stop**, home at **that stop**, between these dates |
| Granted by | staff with `transport-eligibility.*`, with an authorization reference | staff with `transport-passes.*`, within scope, on a route open to the employee's organization |
| Boards alone? | only on a route serving the organization and only if `organization_grants_board_without_pass` is on | yes, on its route (alternative routes per policy) |

Lifecycle: `active → suspended (reason, at) → active | ended`. Suspensions by the re-evaluator carry `route_no_longer_serves_organization` or `employee_no_longer_active`.

## The decision (`TransportEligibilityService::check`)

Given employee, card, provider, route and day it returns `eligible`, `reason`, `basis` (`pass`, `employee_grant`, `organization_grant`, `pay_as_you_go`, `pass_not_required`), `alternative_route` and a `snapshot` (card status, employee status, assigned route, grant id) that the boarding stores. Refusal reasons are the `transport.reasons.*` codes shown to the operator in EN/AM.

## Re-evaluation

`TransportEligibilityObserver` (on `EmployeeAssignment` and `Employee` saves) queues `ReevaluateTransportEligibility`; `TransportEligibilityReevaluator::reevaluate()` suspends assignments whose route no longer serves the employee's current organization or whose employee is no longer active, records an `eligibility_withdrawn` exception and notifies the employee (`assignment_suspended`). Identity (employee id, card UUID) never changes.

## Settings that shape it

`require_pass_for_scan`, `organization_grants_board_without_pass`, `allow_pay_as_you_go`, `alternative_route_policy`. Pay-as-you-go boardings are evidence but excluded from settlement by basis.

## Pages

Staff: Transport › Eligibility (grants), Transport › Passes (assignments with stops). Employee: My Portal › Services › transport card shows the route, stops, today's trips and recent boardings.
