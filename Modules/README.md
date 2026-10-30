# Modules

Business modules with a clear boundary (ADR 0016):

```
Modules/<Name>/
    Domain/          models, enums, value objects: depend on nothing else in the module
    Application/     services and actions (the use cases), policies, notifications
    Infrastructure/  jobs, console commands, exports, external clients
    Http/            controllers, form requests, resources, middleware
```

Other code may use a module's `Application` and `Domain`, never its `Http` or `Infrastructure`.
`tests/Feature/Architecture/ModuleBoundariesTest.php` checks every module here.

| Module | Moved | Notes |
| --- | --- | --- |
| Transport | 2026-10-30 | 160 classes; the load-test seeder stays in `app/Console/Commands/Transport` (dev tooling) |

Not yet moved (still in `app/`): Cafeteria, Field Work, Discipline, Grievances, Communication, Performance,
Assessments, ID cards, Organization design and the core (Employees, Organizations, Positions), in roughly
that order.

## Moving the next module

1. Move its files with `git mv` into the four layers, change their namespaces and every reference
   (full names and escaped strings in `app/`, `Modules/`, `routes/`, `tests/`, `database/`, `config/`,
   `bootstrap/`), and add `use` lines where a class was referenced unqualified because it shared the
   old namespace.
2. Keep stored names: add each moved model to the morph map in `AppServiceProvider`
   (`'App\Models\Old' => New::class`) and each moved class to `Modules/legacy-class-map.php`.
3. Register what discovery no longer finds: policies (`Gate::policy`) and console commands (by class
   in `bootstrap/app.php`).
4. Check the production build's left-out paths (`scripts/release/build-production-branch.sh`).
5. `composer dump-autoload`, Pint, and the full suite.
