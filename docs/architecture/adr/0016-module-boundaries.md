# ADR 0016 — Module boundaries: `Modules/<Name>/{Domain,Application,Infrastructure,Http}`

- Status: accepted
- Date: 2026-10-30
- Deciders: product owner (asked for a clear boundary per module); technical decisions delegated
- Related: [ADR 0001](0001-master-identity-and-organization-backbone.md) (backbone), [ADR 0002](0002-core-write-authority-assignment-ending-and-sign-in.md), [ADR 0015](0015-standalone-apps-out-of-the-euisis-repository.md) (EUISIS is one modular application)

## Context

EUISIS is one application with one database (ADR 0015). Its 2,391 PHP files are grouped by technical
layer (`app/Models` holds 377 models side by side; `app/Services/Transport`, `app/Http/Controllers/Transport`,
`app/Enums/Transport…` are scattered pieces of one module). The backbone contracts (`App\Backbone`) already
keep service modules off the core tables, but nothing shows, or enforces, where a module begins and ends.

The owner asked for every module to have a clear boundary:

```
Modules/
    Transport/
        Domain/          models, enums, value objects, domain events
        Application/     actions and services (the module's use cases), policies, DTOs
        Infrastructure/  jobs, console commands, external clients, exports, importers
        Http/            controllers, form requests, resources, middleware
```

Moving a class changes its name, and the live database stores class names: `model_has_roles.model_type`
(`App\Models\User`), `audit_logs.auditable_type`, `personal_access_tokens.tokenable_type`,
`notifications.type`, and the serialized payloads of queued and failed jobs. Moving everything at once would
strip every user's roles, orphan audit history and invalidate partner tokens.

## Decision

1. **Layout.** Each module lives in `Modules/<Name>/` (namespace `Modules\<Name>\…`) with the four layers
   above. Migrations stay in `database/migrations` (one ordered history), Inertia pages stay in
   `resources/js/Pages` (the frontend has its own boundaries), and route files stay in `routes/` with
   unchanged route names and URLs.
2. **Dependency rules**, enforced for every module by `tests/Feature/Architecture/ModuleBoundariesTest.php`:
   - `Domain` depends on nothing else in its module (not `Application`, `Infrastructure` or `Http`) and
     never on `Illuminate\Http`.
   - `Application` may use its own `Domain` and dispatch its own `Infrastructure` jobs and exports; it never
     uses `Http`. `Infrastructure` never uses `Http`.
   - Code outside a module (`app/` and other modules) may use its `Application` services and its `Domain`
     models and enums (relations, read models, analytics), never its `Http` or `Infrastructure`. Only the
     composition root (service providers, `bootstrap/app.php`, route files) wires those in. Prefer the
     backbone contracts (`App\Backbone\Contracts`) for anything about people and structure.
   - Code still in `app/` is the shared kernel and the not-yet-moved modules; new module code goes in `Modules/`.
   - Development-only tooling (load-test seeders, demo data) stays in `app/`, where the production build
     leaves it out by path.
3. **Stored class names never change.** A moved model is registered in the morph map under its old class
   name (`'App\Models\TransportRoute' => Modules\Transport\Domain\TransportRoute::class`), so the database
   keeps the same values and existing rows still resolve. Every other moved class (jobs, notifications,
   enums in serialized payloads) gets a legacy alias in `Modules/legacy-aliases.php`, so queued jobs and
   stored notifications written before the move still deserialize.
4. **One module per change.** Transport first (self-contained: ~105 files, 19 models, few inbound
   dependencies), then Cafeteria, Field Work, Discipline and the others; Employees and Organizations
   (the core every module depends on) last. Each move keeps the full suite green and adds the module to
   the architecture tests.

## Consequences

- A module's code is in one folder, and the architecture tests fail on a dependency that crosses a boundary
  the wrong way.
- During the migration two layouts coexist; this ADR and `Modules/README.md` say which modules have moved.
- Deploying a move: drain the queue first (the aliases cover jobs that were already serialized, but a clean
  queue is simpler), then deploy and run `composer dump-autoload` as usual. No database migration.
