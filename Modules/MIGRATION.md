# Modular monolith migration checklist

Where the move to `Modules/<Name>/{Contracts,Domain,Application,Infrastructure,Http,Providers}`
stands ([ADR 0016](../docs/architecture/adr/0016-module-boundaries.md),
[ADR 0017](../docs/architecture/adr/0017-module-public-contracts-and-data-ownership.md)). Update it
in the same change as each step. **Verdict today: incomplete** — Transport has moved; the other owners below are still in `app/`.

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
| Cafeteria | app | — | next: 22 models, 24 actions, 32 services; already uses `CredentialLookup`, `ServiceEligibility`, `EmployeeDirectory` |
| Providers (provider records, portals, terminals) | app | — | needed by Transport and Cafeteria: provider lookup contract |
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
| ID cards (credentials) | app | `App\Backbone` credential contracts | Core |
| Core: employees, organizations, positions | app | `App\Backbone` contracts, data, commands, events | last; everything depends on it |
| Platform (users, roles, scopes, audit, settings, backups, codes) | app | — | shared platform, stays in `app/` |

## Deploying a step

Drain the queue (`php artisan queue:work --stop-when-empty`), deploy, `composer dump-autoload`
(part of the normal deploy), `php artisan optimize:clear`. No database migration.
