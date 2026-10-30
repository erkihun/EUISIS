# Modular monolith migration checklist

Where the move to `Modules/<Name>/{Contracts,Domain,Application,Infrastructure,Http,Providers}`
stands ([ADR 0016](../docs/architecture/adr/0016-module-boundaries.md),
[ADR 0017](../docs/architecture/adr/0017-module-public-contracts-and-data-ownership.md)). Update it
in the same change as each step. **Verdict today: incomplete** — Transport, Cafeteria, Providers and Entitlements have moved with no frozen inbound uses left; the other owners below are still in `app/`.

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
| Transport | `Modules/Transport` (2026-10-30) | `EmployeeTransportActivity`, `TransportOperationalMetrics`, `TransportDataQuality`; `Data\TripStatus` published values. Inbound: nothing outside uses its internals (enforced). | Outbound: (1) **Providers** — only through its contracts (nothing frozen since 2026-10-30). Its 15 models' `provider()`/`submittedBy()`/`scannedBy()` relations, the provider admin page and the `transportProfile` relation use the published `ProviderRecord`/`ProviderUserRecord` read models. Services take provider ids and record the portal actor as a `PortalActor`; pickers and checks use `ProviderDirectory`. `TransportProviderService` registers, revises (only the fields the form sent) and archives providers through `Providers\Contracts\ProviderRegistry`; it refuses to archive a provider that runs another active service (`ProviderDirectory::activeServiceCodes`). Reads `providers`/`provider_services` in SQL in `TransportDataQualityService` and `TransportReportService` (declared readers). (2) **Core** — 15 files import Core models (ratchet); `TransportSettlementService` reads `employees` and `organization_closure_paths` in SQL (should use `OrganizationHierarchy`). (3) **Daily work** — `TransportEmployeeNotifier` uses `App\Services\DailyActivity\DailyActivityNotifier`. Shared platform use (users, audit, settings, calendar, organization scope) is allowed. |
| Cafeteria | `Modules/Cafeteria` (2026-10-30) | `EmployeeCafeteriaActivity` (My Portal), `CafeteriaTerminalScan` (NFC terminals), `CafeteriaDirectory` (location pickers), `CafeteriaStaffAssignments` (staff portal access); implements Providers' `PortalLocationSource`; `Data\TransactionStatus` published values. Inbound: nothing outside uses its internals (enforced). 175 classes moved plus the `provider.assigned` middleware; the three public-holiday actions moved out to `app/Actions/PublicHolidays` (platform, not cafeteria). | Outbound: (1) **Providers** — only through its contracts (nothing frozen since 2026-10-30): the portal profile page and the export policy use `ProviderPortalAccount` (the signed-in user's own account: profile and password changes, lookup by id for the Gate). Model relations, payee pages (listing, route binding), settlements, policy resolution and reports use the published `ProviderRecord` read model, and its `cafeteriaProvider`/`cafeterias`/`cafeteriaNetworks` relations are attached to that read model; its portal pages, scan and exports use `ProviderPortalSession` and `PortalActor`; provider records are created and updated through `ProviderRegistry` (`CafeteriaProviderRegistry`). (2) **Entitlements** — only through its contracts and read models (`ServicePointRecord`, `ServicePointUserRecord`, `ServiceTypeRecord`). (3) **Core** — 39 files import Core models (ratchet). Declared read-only readers of its tables: Situation Room (`CafeteriaAnalytics`, `SupportingIndicators`), the duplicate-data audit, the legacy provider-user migration. The redirect to the cafeteria platform stays in the web group in `bootstrap/app.php` (middleware order). |
| Providers (provider records, types and services; portal users; portal sign-in and context) | `Modules/Providers` (2026-10-30) | `ProviderRegistry` (owner command: register a provider with its service and first portal account, enable a service, update details, revise named fields, archive), `ProviderPortalSession` (the signed-in portal user as a `PortalActor`, their permissions, their provider as an id and its services, the portal layout props), `ProviderDirectory` (pickers of providers offering a service, service checks, a provider's code, names and status, the services it runs), `PortalLocationSource` (extension point that Cafeteria implements, so Providers does not depend on Cafeteria), `ProviderPortalAccount` (the signed-in portal user's own account: read, profile and password change, lookup by id); read models `Contracts\ReadModels\ProviderRecord` and `ProviderUserRecord` for other modules' Eloquent relations (they extend `App\Support\Database\ReadOnlyModel`, so every write through them throws; they serialize and store morph names as the owner's models do). 22 classes moved. Ownership corrections: `ServiceProvider`/`ServiceProviderUser` (service points for entitlements and the partner API) belong to Entitlements and stay in `app/`; `ServiceTerminal` belongs to ID cards. | **Inbound: none** (the frozen list went 115 → 37 → 4 → 0 on 2026-10-30). No other module creates or changes provider records or portal accounts outside `ProviderRegistry` and `ProviderPortalAccount` (enforced). Every portal page uses `ProviderPortalSession`; services take provider ids and a `PortalActor`; admin pickers and form-request checks use `ProviderDirectory`. Done. |
| Entitlements / service delivery (service types, entitlements, service transactions, service providers and their users) | `Modules/Entitlements` (2026-10-30) | `ServiceDeliveryRegistry` (service-type creation, service-point registration, transaction recording, entitlement grants and recalculation, service-type ids); `CardServiceAuthorization` (token verification and terminal-scan authorization for the APIs); read models `ServiceTypeRecord`, `ServicePointRecord`, `ServicePointUserRecord`, `EntitlementRecord`, `ServiceTransactionRecord`; published values `Data\EntitlementStatus`, `Data\ServiceDeliveryChannel`; implements Backbone `ProviderCodeLookup`. 46 classes moved (the settlement report action joined them). The entitlement admin page stays in `app/` as composition (it lists cafeteria locations through `CafeteriaDirectory`; inside the module it would make a cycle) and writes through `ServiceDeliveryRegistry::grantEntitlement`. Its soft-deleted types and code-rule targets are registered from its provider (`RecycleBinRegistry::registerType`, `CodeRuleTargetRegistry`). | **Inbound: none** (the frozen list went 31 → 0 on 2026-10-30). Core and platform models that held its records by bare old names (`Employee::entitlements`, `Position::serviceTypes`, `Service::serviceType`, `ServiceTerminal::provider`, `ExternalApplication::serviceProvider`) now hold the read models; the old-name check catches unqualified names in the old namespace. Six read-only SQL readers stay declared in `ModulePublicApiTest`. Done. |
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
