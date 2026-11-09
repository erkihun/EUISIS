# Reception architecture audit

## Repository findings

- The application is a Laravel 13 modular monolith. Migrated business modules live under `Modules/<Name>/{Contracts,Domain,Application,Http,Providers}`; shared platform and Core records remain under `app/`.
- No Reception, visitor or general appointment owner existed. The Support module is technical help desk, Grievance owns formal complaints, and Entitlements owns employee service authorization; none is extended into Reception.
- `Organization`, `OrganizationUnit`, published hierarchy data and `OrganizationScopeService` are authoritative for institution structure and authorization.
- Existing `User`, Spatie RBAC, default roles and `UserOrganizationScope` remain authoritative. Reception creates no accounts.
- `Service` is the approved institutional service catalog. Reception reads published effective records and stores only foreign keys to them. `PublicService` is public-website content and is not duplicated.
- Employees and ID cards remain Core-owned. Reception stores only an optional employee foreign key after authorized verification; it does not copy employee identity or QR credentials.
- The shared audit action, code rules, working-day/public-holiday calendar, notifications foundation, localized date components, Inertia shell and export conventions are reused.
- No package was added. Existing CSV streaming, print-capable browser views, Laravel transactions and database locks are sufficient.

## Implemented boundary

`Modules/Reception` owns desks, counters, desk assignments, minimal visitor identities, visits, queue sequencing/tickets, appointments and referral history. It references Core/platform identifiers and the canonical service catalog. Backend permission plus organization scope checks are mandatory for every mutation and scoped query.

## Deliberate exclusions

Reception does not adjudicate grievances, create support incidents, authorize cafeteria/transport consumption, expand access through functional reporting, or create identity/card records. These remain links or referrals to their owning modules.
