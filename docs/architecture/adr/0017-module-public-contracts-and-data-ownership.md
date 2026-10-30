# ADR 0017 — Module public contracts and data ownership

- Status: accepted
- Date: 2026-10-30
- Deciders: product owner (asked for a real modular monolith, not only folders); technical decisions delegated
- Extends: [ADR 0016](0016-module-boundaries.md) (layout and layer rules). Related: [ADR 0001](0001-master-identity-and-organization-backbone.md), [ADR 0002](0002-core-write-authority-assignment-ending-and-sign-in.md), [ADR 0015](0015-standalone-apps-out-of-the-euisis-repository.md)

## Context

ADR 0016 gave each module four layers and stopped other code from reaching its `Http` and
`Infrastructure`. It still let other code use a module's `Domain` models and `Application` services,
so the module had no public API distinct from its internals. Moving Transport showed what that
allowed: the core `Provider` model held Eloquent relations into Transport (a cycle), My Portal
queried Transport models, and telemetry and the data-quality report called Transport services and
read its tables.

The Core already has a public API: `App\Backbone\Contracts` (directories, hierarchy, credentials,
eligibility), `App\Backbone\Data` (DTOs), `App\Backbone\Commands` (the only writes service modules
make to Core records) and `App\Backbone\Events`. Service modules should be shaped the same way.

## Decision

1. **Two more layers.** A module is
   `Modules/<Name>/{Contracts, Domain, Application, Infrastructure, Http, Providers}`:
   - `Contracts`: its public API. Interfaces, plus immutable data (`Contracts\Data`) and published
     integration events (`Contracts\Events`). No Eloquent model, builder, response, form request or
     internal class appears in a signature. One exception, for records other modules hold in
     Eloquent relations: a module may publish read models (`Contracts\ReadModels`), final classes
     extending `App\Support\Database\ReadOnlyModel` on its own tables. They serialize and store
     morph names as the owner's models do, and every write through the model or its query builder
     throws, so data ownership (3) still holds; only the owner's own models and commands write. `Illuminate\Http\Request` may be passed as request
     context (audit trail, client IP), as the Core's `ServiceEligibility` already does.
   - `Providers`: one service provider that binds the contracts to their implementations and
     registers everything else the framework must know (policies, morph map, event listeners,
     middleware aliases, rate limiters, console commands, relations the module attaches to Core
     models). It is listed in `bootstrap/providers.php`. No other code wires the module's
     internals; route files name its controllers.
2. **Dependency rule.** Code outside a module (`app/` and other modules) uses only its `Contracts`.
   When a module is moved whose models other code holds in Eloquent relations (Providers), the
   uses that cannot be replaced in the same change are frozen by class in
   `tests/Feature/Architecture/module-internal-use-baseline.php`: the test ignores exactly those and
   fails when one no longer needs it, so the list only shrinks. New code gets no exception.
   `Domain`, `Application`, `Infrastructure`, `Http` and `Providers` are private. The Core keeps its
   existing public API in `App\Backbone` (contracts, data, commands, events); a module may use those
   and the shared platform (`App\Models\User`, `App\Support`, audit, settings) until the Core itself
   is moved.
3. **Data ownership.** Every Eloquent model, and so every table, has exactly one owning module,
   recorded in `Modules/ownership.php`. Only the owner creates, updates or deletes its records.
   Other modules read through the owner's query contracts and write through its commands. Shared
   foreign keys and one database transaction across modules remain allowed: this is one database.
   Read-only reporting that aggregates another module's tables in SQL is allowed only where listed
   (with its reason) in `ModulePublicApiTest`, and that test refuses writes in those files.
4. **Communication.** Synchronous contracts for queries, decisions and commands. Published events
   (`Contracts\Events`, or the Core's `App\Backbone\Events`) for reactions to completed changes,
   dispatched in-process with Laravel's event dispatcher. No bus, outbox or asynchronous processing
   is added where the workflow does not need it.
5. **Compatibility.** Stored class names never change (ADR 0016): the module's provider registers
   its models in the morph map under their old names, and `Modules/legacy-aliases.php` keeps old
   class names loadable for queued jobs and stored notifications. No source file may use an old
   name; only the bridge files may contain them.
6. **Enforcement.** `tests/Feature/Architecture`:
   - `ModuleBoundariesTest`: layer rules inside a module (ADR 0016).
   - `ModulePublicApiTest`: outside code uses only `Contracts`; contracts are interfaces and final
     data with no internals; every contract is bound; no cycles between modules; no pre-move class
     names; a module's tables are named only inside it and in the declared read-only readers.
   - `ModuleOwnershipTest`: every model has exactly one owner, and a migrated module's models live
     in that module.
   - The existing Core ratchets stay: `tests/Unit/ServiceModuleCoreDependencyTest.php` (direct Core
     model imports may only go down) and `tests/Unit/BackboneBoundaryTest.php` (no runtime writes to
     Core records outside Core commands).

## Consequences

- A migrated module can change anything behind its contracts without touching other code; the
  tests fail on a dependency that bypasses them.
- Modules that have not moved yet still sit in `app/` and are not covered by the module rules,
  only by the Core ratchets. `Modules/MIGRATION.md` lists them and the order; the project is a
  modular monolith only when that list is done.
- A contract costs an interface, a DTO and a binding. Contracts are added for what consumers
  actually call, not speculatively.
- Deploying a move: drain the queue, deploy, `composer dump-autoload` (part of the normal deploy),
  `php artisan optimize:clear`. No database migration.

## Implementation record

On 2026-10-30, Entitlements/service delivery moved to `Modules/Entitlements`. Its provider owns
policy registration, morph compatibility, service-provider code rules and the Backbone
`ProviderCodeLookup` adapter. Cafeteria and Providers create service types, service points and
service transactions through `ServiceDeliveryRegistry`; remaining direct readers are frozen in the
shrinking internal-use baseline. The entitlement admin page remains in `app/` because it composes
Entitlements with Cafeteria data at the application composition boundary.

Also on 2026-10-30, Providers published `ProviderRecord` and `ProviderUserRecord` read models.
Transport's and Cafeteria's 21 model relations, Cafeteria's payee pages, settlements, policy
resolution and reports moved to them, and Cafeteria attaches its provider relations to the read
model. The Providers internal-use baseline went from 37 classes to 4, then to 2 when Transport's
provider admin page moved to the read model and to `ProviderRegistry::revise`/`archive` (it had
updated and deleted provider records with the Providers module's own model), and to 0 with
`ProviderPortalAccount` (the signed-in portal user's own account, for the profile page and the
portal export policy). Providers is the first module with no frozen readers. Read models were chosen over
id-only relations because most uses are display fields and eager loads; the behaviour other modules
need (`hasService`, `trashed`) is on the read model or in `ProviderDirectory`.
