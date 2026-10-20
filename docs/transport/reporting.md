# Transport — Reporting and Analytics

All figures derive from verified boardings and completed trips. Nothing is a claim, an invoice or an approval; there is no "savings" figure.

| Report | Service | Figures |
| --- | --- | --- |
| Boardings | `report()` | accepted / refused / verified / voided scans, riders, employee-days, inbound/outbound, manual, nfc, pay-as-you-go, trips; per day, by provider, route, vehicle; top refusal reasons |
| Daily usage | `dailyUsage()` | per service day: assigned employees, unique / morning / evening riders, boardings, completed trips, vehicles used |
| Route utilization | `routeUtilization()` | assigned vs riders, boardings by direction, scheduled / completed / cancelled trips, seats offered, utilization %, no-show % |
| Vehicle utilization | `vehicleUtilization()` | trips, passengers, seats offered, utilization %, days operated, distance |
| Provider delivery | `providerPerformance()` | planned vs completed vs cancelled, completion %, verified boardings, riders, utilization, exceptions |
| Manifest | `manifest()` | per trip, two tiers |

Filters: date window (≤ 1 year), provider, route, vehicle, direction, method; staff see employees in their scope, providers their own data. Exports: CSV/XLSX for every table, boardings CSV, manifest and statement PDFs — EN/AM with Ethiopian dates (`CalendarService`).

Elsewhere: Transport Overview (today's KPIs, month usage, provider delivery, data quality), Situation Room › Transport (`TransportAnalytics`: KPIs, trend, by organization, outcome, providers, refusals, trips), Intelligence › Data Quality (`quality_transport` table), system health (`transport` component).
