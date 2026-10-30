# Modular monolith migration checklist

Where the move to `Modules/<Name>/{Contracts,Domain,Application,Infrastructure,Http,Providers}`
stands ([ADR 0016](../docs/architecture/adr/0016-module-boundaries.md),
[ADR 0017](../docs/architecture/adr/0017-module-public-contracts-and-data-ownership.md)). Update it
in the same change as each step. **Verdict today: incomplete** — Transport, Cafeteria and Providers have moved; Providers still has a frozen list of inbound uses of its internals; the other owners below are still in `app/`.

Ownership of every model and table: [`Modules/ownership.php`](ownership.php) (enforced by
`tests/Feature/Architecture/ModuleOwnershipTest.php`).

## Per module, done when

1. Files moved into the layers; namespaces and references updated (no blind repository-wide replace).
2. Models in the morph map under their old names; every moved class in `legacy-class-map.php`.
3. `Providers/<Name>ServiceProvider` registered in `bootstrap/providers.php`, holding all wiring.
4. Every outside caller goes through `Contracts` (query contracts return DTOs; writes are owner commands).
5. Outside SQL on its tables removed, or listed as a read-only reader in `ModulePublicApiTest`.
6. `ownership.php` `home` set to `Modules/<Name>`; architecture tests, module tests and the full suite green.

## Status

| Owner | Home | Public contracts | Remaining work |
| --- | --- | --- | --- |
| Transport | `Modules/Transport` (2026-10-30) | `EmployeeTransportActivity`, `TransportOperationalMetrics`, `TransportDataQuality`; `Data\TripStatus` published values. Inbound: nothing outside uses its internals (enforced). | Outbound: (1) **Providers** — 54 classes still use `Provider`/`ProviderUser` models (relations, type hints) or the portal context; frozen in the Providers baseline. Provider records are now created through `Providers\Contracts\ProviderRegistry` (`TransportProviderService`). Reads `providers`/`provider_services` in SQL in `TransportDataQualityService` and `TransportReportService` (declared readers). (2) **Core** — 15 files import Core models (ratchet); `TransportSettlementService` reads `employees` and `organization_closure_paths` in SQL (should use `OrganizationHierarchy`). (3) **Daily work** — `TransportEmployeeNotifier` uses `App\Services\DailyActivity\DailyActivityNotifier`. Shared platform use (users, audit, settings, calendar, organization scope) is allowed. |
| Cafeteria | `Modules/Cafeteria` (2026-10-30) | `EmployeeCafeteriaActivity` (My Portal), `CafeteriaTerminalScan` (NFC terminals), `CafeteriaDirectory` (location pickers), `CafeteriaStaffAssignments` (staff portal access); implements Providers' `PortalLocationSource`; `Data\TransactionStatus` published values. Inbound: nothing outside uses its internals (enforced). 175 classes moved plus the `provider.assigned` middleware; the three public-holiday actions moved out to `app/Actions/PublicHolidays` (platform, not cafeteria). | Outbound: (1) **Providers** — 23 classes still use `Provider`/`ProviderUser` models (frozen in the Providers baseline); its portal pages use `ProviderPortalSession`; provider records are created and updated through `ProviderRegistry` (`CafeteriaProviderRegistry`). (2) **Entitlements** — `ServiceProvider`, `ServiceProviderUser`, `ServiceType`, `ServiceTransaction` (in `app/`). (3) **Core** — 39 files import Core models (ratchet). Declared read-only readers of its tables: Situation Room (`CafeteriaAnalytics`, `SupportingIndicators`), the duplicate-data audit, the legacy provider-user migration. The redirect to the cafeteria platform stays in the web group in `bootstrap/app.php` (middleware order). |
| Providers (provider records, types and services; portal users; portal sign-in and context) | `Modules/Providers` (2026-10-30) | `ProviderRegistry` (owner command: register a provider with its service and first portal account, enable a service, update details), `ProviderPortalSession` (the signed-in portal user's provider as an id, its services, the portal layout props), `PortalLocationSource` (extension point that Cafeteria implements, so Providers does not depend on Cafeteria). 22 classes moved. Ownership corrections: `ServiceProvider`/`ServiceProviderUser` (service points for entitlements and the partner API) belong to Entitlements and stay in `app/`; `ServiceTerminal` belongs to ID cards. | **Inbound, frozen:** 77 classes (Transport 54, Cafeteria 23; none in `app/`) still use `Provider`/`ProviderUser` in relations and type hints, or the internal portal context; listed in `tests/Feature/Architecture/module-internal-use-baseline.php`, which may only shrink. No other module creates provider records (enforced). Cafeteria's portal pages and Transport's dashboard, exceptions, reports and transactions pages use `ProviderPortalSession`. Next: Transport services that take a `Provider` only for its id (scan, trips, invoices, settlement, eligibility, devices) take the id instead; portal pages that act as the signed-in `ProviderUser` (scan, devices, trips, invoices, settlement) get the user's id and permission checks from the session; then decide between a provider read model and id-only relations for the models' `provider()` relations. |
| Entitlements / service delivery (service types, entitlements, service transactions, service providers and their users) | app | partly via `App\Backbone` (`ServiceEligibility`, `ProviderCodeLookup`) | owns `service_providers` (reassigned from Providers 2026-10-30) |
| Field work | app | — | |
| Daily work | app | — | |
| Communication | app | — | consumes Core events already |
| Performance (EPMS) | app | — | |
| Assessment | app | — | |
| Grievance | app | — | |
| Discipline / appeal | app | — | |
| Feedback (public and service) | app | implements Core `EmployeeFeedbackLinks` | |
| Public site | app | — | |
| Organization design | app | uses Core `StampJobDescriptionOnCurrentAssignments` | |
| Transfer | app | writes through Core `TransferAssignment` | |
| Recruitment / vacancy | app | writes through Core `TransferAssignment` | |
| Situation Room | app | — | reporting reader of other modules' tables |
| Intelligence / assistant | app | — | |
| Legal (court-ms letters, tribunal placeholder, statutory deadlines) | app | — | |
| Integration (partner API, webhooks, change feed) | app | — | |
| ID cards (credentials, NFC terminals and verification) | app | `App\Backbone` credential contracts | Core; owns `ServiceTerminal` since 2026-10-30 (was listed under Providers) |
| Core: employees, organizations, positions | app | `App\Backbone` contracts, data, commands, events | last; everything depends on it |
| Platform (users, roles, scopes, audit, settings, backups, codes) | app | — | shared platform, stays in `app/` |

## Notes

- 2026-10-30: the "outside code uses only Contracts" rule was a no-op from the Cafeteria commit
  (5712d69a) until the Providers commit: Pest's `not->toUse` passes silently when `expect()` gets a
  list of namespaces. It now runs once per namespace. Re-running it found no violation in the
  Cafeteria or Transport moves.

## Deploying a step

Drain the queue (`php artisan queue:work --stop-when-empty`), deploy, `composer dump-autoload`
(part of the normal deploy), `php artisan optimize:clear`. No database migration.
