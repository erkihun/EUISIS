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
| Transport | `Modules/Transport` (2026-10-30) | `EmployeeTransportActivity`, `TransportOperationalMetrics`, `TransportDataQuality`; `Data\TripStatus` published values. Inbound: nothing outside uses its internals (enforced). | Outbound, to owners not yet modules (not enforceable until they move): (1) **Providers** — `App\Models\Provider`/`ProviderUser` in relations, requests and services; `TransportProviderService` creates provider, provider-service and provider-user records (a cross-module write: needs a Providers command); SQL on `providers`/`provider_services` in `TransportDataQualityService` and `TransportReportService`. (2) **Core** — 15 files import Core models (ratchet); `TransportSettlementService` reads `employees` and `organization_closure_paths` in SQL (should use `OrganizationHierarchy`). (3) **Daily work** — `TransportEmployeeNotifier` uses `App\Services\DailyActivity\DailyActivityNotifier`. Shared platform use (users, audit, settings, calendar, organization scope) is allowed. |
| Cafeteria | `Modules/Cafeteria` (2026-10-30) | `EmployeeCafeteriaActivity` (My Portal), `CafeteriaPortalLocations` (provider-portal location switcher), `CafeteriaTerminalScan` (NFC terminals), `CafeteriaDirectory` (location pickers), `CafeteriaStaffAssignments` (staff portal access); `Data\TransactionStatus` published values. Inbound: nothing outside uses its internals (enforced). 175 classes moved plus the `provider.assigned` middleware; the three public-holiday actions moved out to `app/Actions/PublicHolidays` (platform, not cafeteria). | Outbound, to owners not yet modules: (1) **Providers** — `App\Models\Provider`, `ProviderUser`, `ServiceProvider`, `ServiceProviderUser`, `ServiceTerminal`, `ProviderService`, `ProviderType` in relations and services, and `App\Services\ProviderPortal\ProviderPortalContext` in portal controllers. (2) **Core** — 39 files import Core models (ratchet). (3) **Entitlements** — `ServiceType`, `ServiceTransaction`. Declared read-only readers of its tables: Situation Room (`CafeteriaAnalytics`, `SupportingIndicators`), the duplicate-data audit, the legacy provider-user migration, the retention and scope catalogues, `config/auth.php`. The redirect to the cafeteria platform stays in the web group in `bootstrap/app.php` (middleware order). |
| Providers (provider records, service providers, portal users, portal sign-in and context) | `Modules/Providers` (2026-10-30) | `PortalLocationSource` (extension point: a service module offers its locations to the portal switcher; Cafeteria implements it, so Providers no longer depends on Cafeteria). Also wires the backbone's `ProviderCodeLookup`. 29 classes moved; `ServiceTerminal` reassigned to ID cards (NFC owns the terminal registry). | **Inbound, frozen:** 115 classes outside the module still use its models or `ProviderPortalContext` (Transport 60, Cafeteria 35, app 20: entitlements, service transactions, partner API, NFC, integration, dashboard). They are listed in `tests/Feature/Architecture/module-internal-use-baseline.php`; the list may only shrink. Next: a provider lookup contract (names, status, services) and a portal-session contract for the portal controllers, then a command for creating provider records (Transport's `TransportProviderService` writes them today). |
| Entitlements / service transactions | app | partly via `App\Backbone` (`ServiceEligibility`) | |
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
