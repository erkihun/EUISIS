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
     internal class appears in a signature. `Illuminate\Http\Request` may be passed as request
     context (audit trail, client IP), as the Core's `ServiceEligibility` already does.
   - `Providers`: one service provider that binds the contracts to their implementations and
     registers everything else the framework must know (policies, morph map, event listeners,
     middleware aliases, rate limiters, console commands, relations the module attaches to Core
     models). It is listed in `bootstrap/providers.php`. No other code wires the module's
     internals; route files name its controllers.
2. **Dependency rule.** Code outside a module (`app/` and other modules) uses only its `Contracts`.
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
