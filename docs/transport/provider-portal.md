# Transport — Provider Portal

`/provider/portal/transport/*`, guard `provider`, every page behind `provider.service:transport` and its `provider.permission:provider.transport.*` key; the menu shows only what the user may open (`ProviderPortalContext` can-flags).

| Page | Key | What |
| --- | --- | --- |
| Overview | dashboard | today's scans, today's trip board, open exceptions, recent boardings |
| Boarding terminal | `scan` (+ `manual_exception`, `dispatch`) | continuous scanning, trip activation, occupancy, manual exception |
| Trips | `trips.manage` (+ `dispatch`, `manifests.view`) | day board, plan a trip, trip page with dispatch panel and manifest (provider tier), manifest PDF |
| Routes / Vehicles / Drivers | `routes.manage` / `vehicles.manage` / `drivers.manage` | own master data, stops on the route page |
| Boardings & Reports | `reports.view` | own scans (CSV), route/vehicle utilization |
| Exceptions | `exceptions.view` | own exceptions, read-only |
| Devices | `devices.view` | own scanners, *Report lost* (immediate revoke) |
| Invoices | `invoices.manage` (feature `transport_settlement`) | draft / edit / submit / withdraw a claim with attachment |
| Statements | `invoices.manage` | statements from `under_review` on, reconciliation, PDF, raise a dispute until final |

Rules: provider A never sees provider B (every query is filtered by the signed-in user's `provider_id`; every record in a request is checked to be the provider's); the provider never sees organization names, national IDs or HR data; the provider cannot change a verified count, a statement or a decision; drafts of statements are the city's and are not shown.
