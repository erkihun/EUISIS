# ADR 0038 — Institutional Reporting & Compliance as an enforced module

- Status: accepted (decided while building, as the owner delegated; recorded here for review)
- Date: 2026-11-20
- Module: `Modules/Reporting` — see its [README](../../../Modules/Reporting/README.md)

## Context

Institutions owe narrative reports on schedules, and leadership needs to see who reported, who did not
and who was late. Nothing in the system kept an expected report for an institution that never opened a
screen, so a missing report was invisible.

The request asked for a real modular monolith module, built to stricter rules than the existing
`Modules/*` convention (ADR 0016, 0017): a pure domain, no Core models inside the module, its own
migrations, routes, configuration and translations, and a frontend kept together under
`resources/js/modules/reporting/`.

`docs/ROADMAP.md` holds a scope freeze until the pilot ends. This module was built on the owner's
explicit request, as the Letters module was (ADR 0037).

## Decisions

1. **Use the existing module convention, tightened.** `Modules/Reporting` with the six layers;
   `Contracts` is the public API and `Http` the presentation layer, as in every other module. No
   parallel `App\Modules` tree.
2. **Models live in `Infrastructure/Persistence/Models`.** `Domain` is pure PHP and is checked to use
   nothing of Laravel, Carbon or the module's other layers. `ModuleOwnershipTest` now also finds models
   there. Use cases use the module's models directly; no repository per table.
3. **A module may carry `Database`, `Routes`, `Config` and `Lang`.** `ModuleBoundariesTest` allows these
   four resource directories for any module. Reporting's provider loads them; `bootstrap/providers.php`
   only lists the provider.
4. **Five narrow Core facades were added** in `app/Backbone`: `AccessScopeReader`, `OrganizationLookup`,
   `WorkingDays`, `AuditTrail`, `UserNotifier`. The existing contracts take a `User` model, which would
   have put a Core model inside the module. Reporting reaches the Core only through adapters in
   `Infrastructure/Integration` and `Infrastructure/Notifications`.
5. **No foreign key leaves the module.** Organizations and users are references; names and hierarchy
   are snapshots. Chosen over documented cross-module keys because it removes any possibility of a
   cascade and makes the table-ownership check simple. The cost is stated in the README.
6. **An obligation row per required entity at publication** is the foundation for missing reports,
   created idempotently under unique keys.
7. **Timeliness is computed, never stored.** `first_submitted_at` is written once; the deadline in force
   and applicability are dated records; one domain rule classifies every obligation for every screen
   and export.
8. **A transactional outbox** (`reporting_outbox`) and a durable artifact row carry downstream work
   across a crash. Delivery is at least once; consumers are idempotent. The platform's integration
   outbox in `app/Services/Integration` was not reused: it belongs to the Core's change feed, and
   writing Reporting's events into it would have been a write to another owner's table.
9. **PHPWord 1.4** generates Word files (new dependency, plus `phpoffice/math`). No other package
   changed. Narrative HTML is written by a small own walker rather than PHPWord's HTML reader, which
   needs well-formed XML and would resolve images.
10. **Module errors are answered in the module's base controller** (`ReportingController::callAction`),
    not in the global exception handler, whose catch-all runs first and would turn them into error pages.
11. **Console commands are `institutional-reporting:*`.** The `reporting:` prefix already belongs to the
    reporting-lines feature (ADR 0025).
12. **Frontend pages resolve from `resources/js/modules/<name>/pages/`** when the server names a page
    `modules/<name>/<Page>`. The existing `Pages/` resolver is unchanged.

13. **Every moment is stored in UTC through the module's base model.** The application's runtime
    timezone comes from a system setting (Africa/Addis_Ababa in production) while tests run in UTC;
    Eloquent reads a stored value in the runtime zone. `ReportingModel` converts on write and reads as
    UTC, and `BusinessTimezoneTest` runs the workflow under the production timezone. Found in the browser,
    where a 17:30 deadline showed as 14:30.
14. **The module's own layer rules read source text, not Pest's `arch()`.** With every module's rules
    in one run, `arch()` exhausted the 2 GB memory limit. The shared `ModuleBoundariesTest` still covers
    Reporting through `arch()`; the module-specific rules (pure Domain, Application behind ports,
    framework-free Contracts) scan `use` lines and fully qualified names, with a planted-violation test.

### Work units report inside their own institution (2026-11-20)

The owner asked that the work units of an institution report as units, within the institution's
boundary. A work unit is therefore a third kind of reporting subject (`unit:<id>`), next to an
institution and a named person. A unit may be named on a schedule only when the receiving institution
is the unit's own; its reports are received, reviewed and consolidated there and are invisible to
anyone whose scope lies outside the institution. The module reads units through the Core's
`UnitDirectory` contract behind its own `OrganizationDirectory` port and keeps the unit id as a plain
reference with a name snapshot, as it does for institutions. A unit-level access scope was not added:
the Core has none, and adding one is a Core decision.

### Each institution names its report follow-up unit (2026-11-25)

The owner pointed out that every institution's structure has a work unit that follows up reports, and
asked for it to work like the Grievance module's own unit (ADR 0029). An institution therefore
designates one existing work unit; its staff (people placed in it or beneath it) reach the
institution's reports in this module and receive the notices about the reports it receives. The
designation adds reach and directs notices; it grants no permission and removes nobody's access.
Unlike the grievance units, no members are named one by one and no signatures are kept: placement in
the unit through HR is the membership. Two read methods were added to the Core's `AccessScopeReader`
contract for this (`unitUuidsOf`, `usersPlacedIn`).

## Not decided here

These need the owner, or people the owner names; nothing in the code invents them:

- Which institutions receive which reports, the report types themselves and their deadlines.
- Whether a malware scanner is mandatory in production, and which one.
- Retention periods for reports and their files.
- Whether any report needs a legally recognised electronic signature. The module records names and
  times and says plainly that this is not a signature.

## Consequences

- Reporting can be read, tested and reasoned about without the rest of the application, and the checks
  that keep it so run in CI with the other architecture tests.
- The Core grew five contracts. They are general (scope by user id, organizations in batches, working
  days, audit, notification) and other modules can drop their `User`-model coupling by using them.
- `ModuleOwnershipTest` was failing before this change for two position-import models added earlier
  without an owner; they are now listed with the employee-import models.
