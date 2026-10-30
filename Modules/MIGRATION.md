# Modular monolith migration checklist

Where the move to `Modules/<Name>/{Contracts,Domain,Application,Infrastructure,Http,Providers}`
stands ([ADR 0016](../docs/architecture/adr/0016-module-boundaries.md),
[ADR 0017](../docs/architecture/adr/0017-module-public-contracts-and-data-ownership.md)). Update it
in the same change as each step. **Verdict today: incomplete** — Transport, Cafeteria, Providers and Entitlements have moved; Providers and Entitlements still have frozen inbound uses of their internals; the other owners below are still in `app/`.

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
| Transport | `Modules/Transport` (2026-10-30) | `EmployeeTransportActivity`, `TransportOperationalMetrics`, `TransportDataQuality`; `Data\TripStatus` published values. Inbound: nothing outside uses its internals (enforced). | Outbound: (1) **Providers** — 2 classes still use the internal `Provider` model, frozen in the Providers baseline: the admin page that edits a provider's transport profile, and the `transportProfile` relation its provider attaches. Its 15 models' `provider()`/`submittedBy()`/`scannedBy()` relations use the published `ProviderRecord`/`ProviderUserRecord` read models. Services take provider ids and record the portal actor as a `PortalActor`; pickers and checks use `ProviderDirectory`. Provider records are now created through `Providers\Contracts\ProviderRegistry` (`TransportProviderService`). Reads `providers`/`provider_services` in SQL in `TransportDataQualityService` and `TransportReportService` (declared readers). (2) **Core** — 15 files import Core models (ratchet); `TransportSettlementService` reads `employees` and `organization_closure_paths` in SQL (should use `OrganizationHierarchy`). (3) **Daily work** — `TransportEmployeeNotifier` uses `App\Services\DailyActivity\DailyActivityNotifier`. Shared platform use (users, audit, settings, calendar, organization scope) is allowed. |
| Cafeteria | `Modules/Cafeteria` (2026-10-30) | `EmployeeCafeteriaActivity` (My Portal), `CafeteriaTerminalScan` (NFC terminals), `CafeteriaDirectory` (location pickers), `CafeteriaStaffAssignments` (staff portal access); implements Providers' `PortalLocationSource`; `Data\TransactionStatus` published values. Inbound: nothing outside uses its internals (enforced). 175 classes moved plus the `provider.assigned` middleware; the three public-holiday actions moved out to `app/Actions/PublicHolidays` (platform, not cafeteria). | Outbound: (1) **Providers** — 2 classes still use Providers internals (frozen in the Providers baseline): the export policy that receives the provider-guard user from the Gate, and the portal profile page (which edits the user's own account). Model relations, payee pages (listing, route binding), settlements, policy resolution and reports use the published `ProviderRecord` read model, and its `cafeteriaProvider`/`cafeterias`/`cafeteriaNetworks` relations are attached to that read model; its portal pages, scan and exports use `ProviderPortalSession` and `PortalActor`; provider records are created and updated through `ProviderRegistry` (`CafeteriaProviderRegistry`). (2) **Entitlements** — `ServiceProvider`, `ServiceProviderUser`, `ServiceType`, `ServiceTransaction` (in `app/`). (3) **Core** — 39 files import Core models (ratchet). Declared read-only readers of its tables: Situation Room (`CafeteriaAnalytics`, `SupportingIndicators`), the duplicate-data audit, the legacy provider-user migration. The redirect to the cafeteria platform stays in the web group in `bootstrap/app.php` (middleware order). |
| Providers (provider records, types and services; portal users; portal sign-in and context) | `Modules/Providers` (2026-10-30) | `ProviderRegistry` (owner command: register a provider with its service and first portal account, enable a service, update details), `ProviderPortalSession` (the signed-in portal user as a `PortalActor`, their permissions, their provider as an id and its services, the portal layout props), `ProviderDirectory` (pickers of providers offering a service, service checks, a provider's code, names and status), `PortalLocationSource` (extension point that Cafeteria implements, so Providers does not depend on Cafeteria); read models `Contracts\ReadModels\ProviderRecord` and `ProviderUserRecord` for other modules' Eloquent relations (they extend `App\Support\Database\ReadOnlyModel`, so every write through them throws; they serialize and store morph names as the owner's models do). 22 classes moved. Ownership corrections: `ServiceProvider`/`ServiceProviderUser` (service points for entitlements and the partner API) belong to Entitlements and stay in `app/`; `ServiceTerminal` belongs to ID cards. | **Inbound, frozen:** 4 classes (Transport 2, Cafeteria 2; none in `app/`; was 37): the provider-guard export policy, the portal profile page, Transport's provider admin page and the `transportProfile` relation its provider attaches; listed in `tests/Feature/Architecture/module-internal-use-baseline.php`, which may only shrink. No other module creates provider records (enforced). Every portal page except Cafeteria's profile uses `ProviderPortalSession`; services take provider ids and a `PortalActor`; admin pickers and form-request checks use `ProviderDirectory`. Next: attach `transportProfile` to `ProviderRecord` and give the Transport provider admin page the provider type and organization it reads (read-model relations or `ProviderSummary` fields); let the export policy resolve the actor through `ProviderPortalSession` instead of the Gate's `ProviderUser`; move the portal profile page's account edits behind a Providers command. |
| Entitlements / service delivery (service types, entitlements, service transactions, service providers and their users) | `Modules/Entitlements` (2026-10-30) | `ServiceDeliveryRegistry` owns service-type creation, service-provider registration and cross-module transaction recording; implements Backbone `ProviderCodeLookup`. 45 classes moved; cross-module page composition remains in `app/Http/Controllers/Web/EntitlementController`. | **Inbound, frozen:** 31 application/module classes still read its internal models; listed in `module-internal-use-baseline.php` and required to shrink. Cafeteria and Providers create Entitlements records only through `ServiceDeliveryRegistry`. Six read-only SQL readers are declared in `ModulePublicApiTest`. Next: publish query DTOs for service types, service points and employee entitlements, then replace direct model relations and admin/reporting reads. |
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
