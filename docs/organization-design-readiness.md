# Organization Design & Workforce Planning — production readiness gate

Amharic: የተቋም አወቃቀርና የሰው ኃይል ዕቅድ — የምርት ዝግጁነት በር

Assessment date: 2026-10-11. Scope: the domain as built in phases 1–11
plus the production-gate work (commits `5485fa5`, `3888cf5`, `7cc1d56`
and the closing commit). Transport Management stays out of scope and was
not touched.

**Verdict: CONDITIONAL READY** — see §29–30.

## 1. Actual architecture found

Laravel 12 + Inertia/React, one domain under `routes/organization-design.php`
(129 routes, prefix `organization-design.`), services in
`app/Services/OrganizationDesign/`, policies on every record type backed
by `OrganizationScopeService`, status history and audit through
`OrganizationDesignAudit`, bilingual strings in `lang/*/organization-design.php`
and `resources/js/i18n/*/organizationDesign.ts`. Map of every piece:
[organization-design.md](organization-design.md); decisions and rulings:
[organization-design-workforce-planning-plan.md](organization-design-workforce-planning-plan.md).

## 2. Implemented components

Mandates, powers, functions (tree by path); services with LOCAL/CPC
classification and ISIC activity links; processes → activities → tasks with
typed I/O rows; master work standards (generalised `position_service_task_standards`);
ISCO groups, occupations, professions, specializations; competency types,
levels, education ladder; work occupation / competency / qualification
requirements; design studies with unit proposals → one organizational
change request each; responsibility types and matrix; structure validation
and the design-side structure page; methodologies (four whitelisted
strategies), manpower plans, lines with `Dec` maths and a trace, workload
source, roll-up to position requirements, implementation as positions and
approved establishments; position profiles, work links, derived or written
requirements; job descriptions with derived and custom sections, bilingual
PDF; traceability; gap report; data quality; mapping workspace; seventeen
scoped reports with queued CSV/XLSX exports; scoped lookups; sidebar group;
default roles; demo chain.

## 3. Missing components

- Official ISCO-08, ISIC Rev.4 and CPC datasets (NEEDS_DATA, §26).
- Restructure impact for a hierarchy version of *organizations* is the
  existing organizational-change module's; unit-level impact is per
  proposal on the study page (D-1: units are not versioned).
- A controlled mass-reassignment workflow after a restructure
  (NEEDS_DECISION, §28).
- Browser end-to-end automation for the domain (walkthrough was manual).

## 4. Duplications removed

None found that needed removal: the domain reuses `Position`,
`PositionEstablishment`, `EmployeeAssignment`, `CodeGeneratorService`,
`TaskStandardService`, `OrganizationalChangeRequest`, the hierarchy
version/organogram, `Dec`, the list kit and the document fonts. The
former per-page catalogs (tasks, activities, positions, occupations shipped
whole in page props) were replaced by one scoped lookup endpoint.

## 5. Domain-boundary fixes

Verified separate: mandate ≠ function ≠ service ≠ process ≠ activity ≠
task (own tables and keys); occupation ≠ profession ≠ competency; unit ≠
position ≠ employee; employee assignment ≠ position; calculated FTE ≠
recommended ≠ approved headcount ≠ current employees (four columns, never
merged); service classification ≠ ISIC ≠ ISCO ≠ internal activity code.
No mixing was found; the only addition was explicit provenance on plan
lines (§19).

## 6. Classification status

Services → LOCAL/CPC via `service_classifications` (scheme + revision);
economic activity → ISIC via `isic_activities` (+ revision); occupations →
ISCO-08 via `isco_groups`; institutional work → EUISIS process / activity /
task codes. Enforced by the models and the importer; nothing invented.

## 7. Official dataset availability

| Dataset | Status |
|---|---|
| ISCO-08 | NEEDS_DATA (importer ready, dry run + idempotent) |
| ISIC Rev.4 | NEEDS_DATA |
| CPC / city service classification | NEEDS_DATA |
| Competency dictionary | starting catalog only (types, levels); competencies per framework |
| Approved work standards | entered per task; none pre-loaded |
| Approved manpower methodologies | none seeded (D-6/D-7) |

## 8–10. Mandate, service and process traceability

`TraceabilityService::trace()` walks any object up to its mandate and
down to the job descriptions in force; the traceability report lists one
row per task with the whole chain. Proven forward and backward in
`GoldenPathTest` (§18–19 of the UAT record) and `ReportsTest`.

## 11. Occupation / competency mapping

Tasks and activities carry occupation, competency and qualification
requirements; position requirements are derived from the linked work
(highest level wins, mandatory if any) or written; derived rows never
overwrite written rows. Occupations link to ISCO groups through the mapping
workspace (city-level permission).

## 12. Unit design status

Studies group mandate review, services, processes, unit proposals,
responsibilities, manpower plan (`study_id`) and position design. Proposals
become one change request each and adopt the implemented unit. Approval
gate: structural errors block; warnings do not.

## 13. Responsibility matrix status

Configurable types (exclusive, covering); matrix and gaps per
organization; proposals can hold responsibilities before the unit exists.

## 14. Structure / organogram status

Units are unversioned (D-1); structure changes flow through the existing
organizational-change module; the organogram derives from the live
structure and the published hierarchy versions. `validateStructure()`
grades cycles, foreign/missing parents, duplicate codes, inactive parents,
span of control (no thresholds invented).

## 15. Manpower calculation status

Backend-only, DECIMAL via `Dec`, whitelisted strategies, schema-checked
parameters, trace with methodology version, inputs and standard used;
rounding by the methodology's rule or manual; override needs a reason;
workload source required before submission.

## 16. Position integration

Implementation uses `CreatePositionAction` (codes from the code rules) and
`StorePositionEstablishmentAction`; approved headcount becomes approved
slots; vacancies = slots − current employees (vacancy report). No second
position table.

## 17. Job description integration

Structured sections (purpose, reporting, duties, occupation,
qualifications, competencies, standards, working conditions, authorities,
relationships); derived sections regenerated from the masters while a
draft; draft → under review → approved → published → superseded; stamped
on current assignments; bilingual PDF with Gregorian / Ethiopian dates.

## 18. Legacy mapping status

See [organization-design-legacy-mapping.md](organization-design-legacy-mapping.md).
Development database: 8 units (2 UNMAPPED_LEGACY, 6 INVALID_REFERENCE —
grievance demo units without a unit type), 3 positions UNMAPPED_LEGACY,
0 position services. Nothing was auto-linked.

## 19. Data-quality findings

Gap codes: mandate/function/service/process/task chain breaks, task
without standard or requirements, object without responsible unit,
service without owner unit or with a dead owner, position without
occupation / work links / profile / job description, approved requirement
without position, unit without positions. Data quality: unmapped counts,
NEEDS_DATA_REVIEW per ambiguous legacy task, links to inactive or foreign
masters, work links to missing objects.

## 20. Security findings

IDOR sweep over all 129 routes passes; status/approval/date tampering
refused; published records immutable; exports private and formula-safe;
import behind `classifications.manage`; lookups scoped. Open:
segregation of duties beyond maker ≠ checker (one person may review and
publish) — NEEDS_DECISION.

## 21. Scope findings

Every query scoped through `OrganizationScopeService`; subtree scopes
follow the published hierarchy; city-level master data behind its own
permissions; Transport Management untouched.

## 22. Migration safety

Additive migrations only (new tables, nullable columns, indexes); nothing
destructive; the assignment column's foreign key is skipped on SQLite
because Laravel's table rebuild there drops a partial unique index.
`php artisan migrate --pretend`: nothing pending on the dev database.

## 23. Performance findings

Query ceilings guarded by test; fifteen hot-path indexes added after a
foreign-key audit; trees read by path in bounded queries; exports queued;
no whole-catalog props. Open: no 3M-row rehearsal.

## 24. Golden-path test result

`GoldenPathTest` — PASS on SQLite and PostgreSQL (84 assertions).

## 25. UAT result

[organization-design-uat.md](organization-design-uat.md): 48 scenarios,
all PASS; two items outside scope noted.

## 26. NEEDS_DATA

ISCO-08, ISIC Rev.4, CPC / city service classification files; the
competency dictionary; approved work standards; approved methodologies.

## 27. NEEDS_DATA_REVIEW

Six grievance demo units without a unit type (INVALID_REFERENCE) on the
dev database; on production, every INVALID_REFERENCE row of the legacy
report; every NEEDS_DATA_REVIEW legacy task (several candidate master tasks).

## 28. NEEDS_DECISION

Manpower policy values (productive time, allowances, minimum staffing,
span-of-control thresholds, rounding); education ladder alignment; full
segregation of duties (prepare / review / approve / publish as four
people); mass reassignment after restructure; renaming `work_standards.*`
to the kebab convention; brief sections 130+.

## 29. Remaining P0/P1 blockers

- P0: none in code. The domain cannot be *used* for official work until
  the official classification datasets are imported (NEEDS_DATA) and the
  manpower policy values are configured (NEEDS_DECISION) — both are
  owner inputs, not defects.
- P1: 3M-row rehearsal on the load-test database before go-live.

## 30. Final verdict

**CONDITIONAL READY** — the code gate (tests on both drivers, IDOR sweep,
tampering, history, golden path, UAT, audits, build) is clean; go-live is
conditional on the owner inputs in §26 and §28 and the scale rehearsal in
§29.

Validation run summary (2026-10-11): `php artisan migrate --pretend` —
nothing to migrate; `route:list` — 129 domain routes; `vendor/bin/pint`
applied to the domain's files; `composer audit --locked` — no advisories;
`npm audit` — 0 vulnerabilities; `tsc --noEmit` — clean; `vite build` — ok;
Pest: organization-design suite (97 tests) on SQLite and PostgreSQL; full
feature suite — see the final hand-over message for the run of record.
