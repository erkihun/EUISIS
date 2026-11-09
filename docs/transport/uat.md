# Transport — UAT and Load Test

## Roles
City Transport Admin, Transport Dispatcher (provider manager), Bus Operator (provider operator with scan), Finance Reviewer, Finance Approver (a different person), Organization Transport Officer (scoped), Employee.

## Morning UAT (inbound)
1. Admin: provider, route with stops, schedule (Mon–Fri 06:30), vehicles with capacity, drivers, devices; eligibility grant for the pilot organization; route assignments with boarding stops for 20 employees.
2. Night: `transport:plan-trips` creates tomorrow's trips; dispatcher assigns vehicles.
3. Operator opens the terminal, selects the trip, **Start boarding**; scans 20 cards (camera and handheld), one twice (refused as duplicate, sound differs), one revoked card (refused), one visitor card (unknown), one employee of another organization (policy).
4. Dispatcher departs and completes the trip; manifest shows 20 boarded, 3 refused; Overview shows the figures; employee sees the boarding in My Portal.

## Evening UAT (outbound)
Same route, evening trip; vehicle breaks down → replace vehicle mid-trip; later boardings sit on the second bus; one trip cancelled with reason → assigned employees notified; capacity 50 with 52 riders under `block`, then `warn`.

## Reconciliation UAT
Provider submits an invoice for the week claiming more than verified; finance computes the statement under the contract; reconciliation shows the difference; provider raises a dispute with evidence; approver rejects the dispute and approves; payment recorded; statement PDF in Amharic.

## Automated coverage
`tests/Feature/Transport/*` — 141 tests, 1 PostgreSQL-only; `TransportBusinessScenariosTest` (A–J), `TransportReconciliationFixtureTest` (100 employees, a week, duplicates, voids, non-assigned riders, scoped report), `TransportSecurityTest`, `TransportPortalFinanceTest`, `TransportIntegrityTest`, device API, offline replay, trip lifecycle, eligibility, settlement.

## Load test (never production)
```
php artisan transport:seed-loadtest storage/app/loadtest/transport-loadtest.json --employees=600 --routes=6
k6 run -e BASE_URL=https://staging.example -e DATA=storage/app/loadtest/transport-loadtest.json -e PROFILE=morning loadtest/k6/transport-boarding.js
php artisan transport:seed-loadtest --purge
```
Profiles: `smoke` 1/s 30s, `morning` 5 boardings/s for 20 min (12 buses clearing their queues), `peak` 15/s, `stress` 40/s. Thresholds: verify p95 < 800 ms, p99 < 1.5 s, error rate < 1 %; 5 % of requests are resent with the same idempotency key and must answer identically. Synthetic riders, cards, devices and trips only (prefix `LOAD-`).

Measured in this environment: the automated suite only (no HTTP load generator available in the sandbox) — `NEEDS_DEPLOYMENT_CONFIGURATION`: run the morning profile against staging on PostgreSQL before pilot.
