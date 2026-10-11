# Organization Design & Workforce Planning

Amharic: የተቋም አወቃቀርና የሰው ኃይል ዕቅድ

The design chain of an institution, recorded end to end: **legal mandate →
functions → services → processes, activities and tasks (with work
standards) → occupations and competencies → unit design and responsibility
→ structure → manpower planning → position profiles → job descriptions**,
with traceability, a gap report, scoped reports and a mapping workspace for
records that pre-date the design. The plan and every ruling taken while
building it live in
[organization-design-workforce-planning-plan.md](organization-design-workforce-planning-plan.md).

## Where things are

| Piece | Where |
|---|---|
| Routes (prefix `organization-design`, names `organization-design.*`) | `routes/organization-design.php` (staff middleware: `auth`, `verified`, `mfa`, `force.password`, `admin.access`) |
| Controllers | `app/Http/Controllers/OrganizationDesign/` |
| Services | `app/Services/OrganizationDesign/` (calculation strategies under `Calculation/`, shared finding builder under `Concerns/`) |
| Policies | `app/Policies/*Policy.php` registered in `AppServiceProvider`; every one checks `OrganizationScopeService` |
| Permissions | `database/seeders/data/organization-design-permissions.php`; category *Organization Design & Workforce Planning* in `PermissionCatalog` |
| Default roles | `Organization Designer`, `Organization Design Approver` (scoped), `Design Catalog Manager` (global) in `DefaultRoleMatrix` |
| Pages | `resources/js/Pages/OrganizationDesign/`, shared pieces in `resources/js/Components/organizationDesign/` |
| Strings | `lang/{en,am}/organization-design.php` (server messages, validation findings, report columns) and `resources/js/i18n/{en,am}/organizationDesign.ts` |
| Sidebar | Group `organizationDesign` in `AppSidebar.tsx`, one entry per page, each gated by the permission its page checks |
| Tests | `tests/Feature/OrganizationDesign/` (brief tests 1–82 by phase; `HardeningTest` sweeps every route for IDOR) |

## Rules that hold everywhere

- **Versioned, immutable once published.** Mandates, services, processes,
  methodologies, position profiles and job descriptions carry a
  `lineage_key` and `version_no`. A new version is drafted from the
  published one and supersedes it on publication (`effective_to` = new
  `effective_from` − 1 day). Published rows never change.
- **Maker ≠ checker.** The person who drafted or prepared a record cannot
  publish or approve it. The default roles keep the two seats apart; the
  services refuse regardless of role.
- **Gates decide server-side.** Approval and publication run a validation
  report (`{ok, summary, findings[]}` with `error` / `warning` / `info`
  findings and stable codes). Errors block; warnings and information are
  shown. React never decides.
- **Scope on every query.** Organization-level records are read and
  written through `OrganizationScopeService`; city-level master data
  (classifications, ISCO groups, professions, competency catalog,
  responsibility types, methodologies) sits behind its own permissions.
- **No invented policy.** Classification codes, ISCO/ISIC/CPC datasets,
  manpower parameters and rounding rules are never seeded; catalogs ship
  empty and flagged `OFFICIAL_DATASET_REQUIRED` until an official file is
  imported (`organization-design/classifications`, CSV, dry run first).
- **No executable formulas.** Manpower estimation uses whitelisted
  strategies (`WORKLOAD_STANDARD_TIME`, `RATIO`, `FIXED_MINIMUM`,
  `SPAN_OF_CONTROL`) with schema-checked parameters; maths run through
  `Dec`; every line keeps a calculation trace.
- **Planning never touches people.** Implementing an approved manpower plan
  creates positions and approved establishments only; no assignment,
  transfer, termination or grade change ever follows from the design.
- **Existing records are never rewritten.** Legacy position tasks keep their
  own standards (Daily Work keeps measuring against them); the mapping
  workspace lists them as `UNMAPPED_EXISTING_RECORD`, suggests master
  tasks, and applies a suggestion only when a person asks and only when it
  is unambiguous. Anything else is `NEEDS_DATA_REVIEW`.

## The pages, in workflow order

1. **Legal Mandates** and **Functions** — the mandate (legal instrument,
   article) and the powers and functions it justifies.
2. **Service Catalog** and **Classifications** — services under functions,
   local/CPC classification and ISIC activities.
3. **Processes & Standards** — process → activity → task with typed
   inputs/outputs, master work standards, and per-task occupation,
   competency and qualification requirements.
4. **Occupations & Professions**, **Competency Catalog** — city-level
   reference data.
5. **Design Studies** and **Responsibility Matrix** — unit proposals that
   become one organizational change request each; who is responsible for
   which function, service or process; coverage gaps.
6. **Structure Validation** — the live unit tree graded, with published
   hierarchy versions.
7. **Planning Methodologies** and **Manpower Plans** — strategies and
   parameters; plan lines calculated with a trace; roll-up to position
   requirements; implementation as positions and establishments.
8. **Position Design & Job Descriptions** — profile versions, work links,
   derived or written requirements, job descriptions with derived and
   custom sections; publishing stamps the current assignments.
9. **Design Reports** — coverage, traceability, gaps, position readiness,
   workforce plans; CSV/XLSX exports built on the queue and stored privately.
10. **Mapping Workspace** — the bridge from existing EPMS records to the
    design, with the data-quality report.

## Operating notes

- Exports run on the queue (`ExportOrganizationDesignReport`). With
  `QUEUE_CONNECTION=database` a worker must be running
  (`php artisan queue:work`) or the export stays *Queued*.
- The hot-path indexes live in
  `2026_10_12_100800_add_organization_design_hot_path_indexes`; they are
  additive and idempotent.
- Adding a route under `organization-design.*` that takes an
  organization-scoped record requires a foreign fixture in
  `HardeningTest::hdFixtures()`; the IDOR sweep fails otherwise, on
  purpose.
- Still open for the owner: official ISCO-08 / ISIC Rev.4 / CPC datasets;
  brief sections 130+; a scale rehearsal on the load-test database; whether
  to rename the pre-existing `work_standards.*` permissions to the kebab
  convention (left as is — it touches roles already in production seeds).
