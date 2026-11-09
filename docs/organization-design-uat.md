# Organization Design & Workforce Planning — UAT record

Amharic: የተቋም አወቃቀርና የሰው ኃይል ዕቅድ — የተጠቃሚ ተቀባይነት ሙከራ

Executed 2026-10-11 on the development database (synthetic data; no
production data). Automated scenarios are Pest feature tests that run on
SQLite and PostgreSQL; browser scenarios were walked through in the
Amharic interface at desktop and phone width, light and dark theme.
Where a scenario is backed by a test, the test file is the evidence and
re-runs it on every change.

Legend: **PASS** — behaved as expected · **FAIL** — did not · **N/A** —
not applicable / outside scope.

## 1. Golden path (brief §50)

Preconditions: an active organization with a head office and an HR
directorate; a designer account (every draft permission of the domain, no
approve/publish/review/implement) and a separate approver account (the
opposite); reference data: one legal instrument type, competency level
PROFICIENT, education level BACHELOR, responsibility type RESPONSIBLE.
Evidence: `tests/Feature/OrganizationDesign/GoldenPathTest.php` (84 assertions).

| # | Scenario | Role | Steps | Expected | Actual | Result |
|---|---|---|---|---|---|---|
| 1 | Open a design study | Designer | POST studies.store | Study in DRAFT with reference ODS-YYYY-NNNN | As expected | PASS |
| 2 | Draft the mandate, add three functions | Designer | mandates.store; functions.store ×3 | Draft mandate, functions under it | As expected | PASS |
| 3 | Designer tries to publish own mandate | Designer | mandates.publish | 403 (no approval permission) | 403 | PASS |
| 4 | Approver publishes the mandate | Approver | mandates.publish | PUBLISHED, approver recorded, drafter ≠ approver | As expected | PASS |
| 5 | Two services under the functions, published | Designer → Approver | services.store (function_ids), services.publish | Published services with owner unit | As expected | PASS |
| 6 | Process → activity → two tasks → requirements; publish | Designer → Approver | processes.store, activities.store, tasks.store, requirements.sync, processes.publish | Published process; task carries occupation, competency and qualification requirements | As expected | PASS |
| 7 | Master work standard on the task | Designer → Approver | tasks.standards.store (effective today), standards.approve | Approved standard v1 in force | As expected | PASS |
| 8 | Responsibilities of the HR directorate | Designer | responsibilities.sync (function, service, process) | Three responsibility rows | As expected | PASS |
| 9 | Propose a Records Team in the study; approve | Designer → Approver | proposals.store, responsibilities.sync (proposal), studies.submit, studies.approve | Designer cannot approve (403); approver approves; gate passes | As expected | PASS |
| 10 | Methodology with test parameters (no policy seeded) | Designer → Approver | methodologies.store (FIXED_MINIMUM, minimum_positions = 2), methodologies.publish | Published methodology v1 | As expected | PASS |
| 11 | Manpower plan, one line with its workload source, calculated | Designer | manpower-plans.store, lines.store (workload_source_type = approved_forecast), manpower-plans.calculate | calculated FTE 2.0000, recommended 2, approved headcount empty | As expected | PASS |
| 12 | Submit and approve the plan | Designer → Approver | submit; approve (designer 403) | APPROVED; one position requirement, status approved, no position yet | As expected | PASS |
| 13 | Implement the plan | Approver | manpower-plans.implement | One position in the HR directorate with the planned occupation and an approved establishment of 2 slots; requirement IMPLEMENTED | As expected | PASS |
| 14 | Position design: links, derived requirements, profile published | Designer → Approver | position-work-links.sync, position-requirements.derive, position-profiles.store, position-profiles.publish | Published profile v1 | As expected | PASS |
| 15 | Job description drafted, submitted, approved, published, printed | Designer → Approver | job-descriptions.store/submit/approve/publish; GET pdf?locale=am | PUBLISHED; duties list the task; competencies derived; Amharic PDF 200 | As expected | PASS |
| 16 | Structure validation and organogram | Designer | validateStructure; GET structure.index; GET organizations.organogram | No ERROR finding; both pages 200 | As expected | PASS |
| 17 | Gap report | — | OrganizationDesignGapService | ok = true | As expected | PASS |
| 18 | Reverse trace from the new position | — | TraceabilityService::trace('position') | task → activity → process → service → function → mandate MND-GP | As expected | PASS |
| 19 | Forward trace from the mandate; traceability report | — | trace('mandate'); report 'traceability' | functions and services downstream; T-1 row names mandate, function, service, process, the position and its JD | As expected | PASS |
| 20 | No employee was assigned | — | count employee_assignments for the new position | 0 | 0 | PASS |

## 2. Domain rules, versioning and history

| # | Scenario | Evidence | Result |
|---|---|---|---|
| 21 | Published mandate / service / process / standard / profile / JD are immutable; a new version supersedes with effective_to = new start − 1 day | ProductionGateTest 88–89; ServiceCatalogTest; ProcessArchitectureTest; JobDescriptionTest | PASS |
| 22 | Version 2 of a profile, JD and standard leaves version 1, its sections and its figures untouched; the JD in force on an old date is still v1 | ProductionGateTest 89 | PASS |
| 23 | Double publish / double approve / duplicate lineage version are refused by state and by the unique constraint | ProductionGateTest 90 | PASS |
| 24 | Calculated FTE ≠ recommended headcount ≠ approved headcount ≠ current employees, each kept and reported | WorkforcePlanningTest; ReportCatalogTest (fte_vs_headcount) | PASS |
| 25 | A plan line without a workload source cannot be submitted; an invalid source type is refused | ProductionGateTest 84 | PASS |
| 26 | Manpower approval produces position requirements, not employees; implementation creates positions and approved establishments only | WorkforcePlanningTest; GoldenPathTest | PASS |
| 27 | Structural vs functional relationships stay separate (organogram from structural, functional overlay read-only) | StructureValidationTest; Structure page | PASS |
| 28 | Responsibility exclusivity and covering types are configurable; duplicate conflicting mappings are refused | UnitDesignTest | PASS |
| 29 | Legacy records are never auto-linked; only a single same-code candidate is applied, on a person's click | MappingWorkspaceTest 75–76 | PASS |
| 30 | Daily Work keeps measuring against a legacy task's own standards after mapping | MappingWorkspaceTest 76 | PASS |

## 3. Security and scope

| # | Scenario | Evidence | Result |
|---|---|---|---|
| 31 | IDOR sweep over every `organization-design.*` route with a fully permitted user of another organization (129 routes) | HardeningTest 80 | PASS |
| 32 | Direct scope sees only its organization; subtree scope follows the published hierarchy; city role sees all | ScopeAndDutiesTest 93 | PASS |
| 33 | Holding every permission still does not let one person prepare and approve | ScopeAndDutiesTest 94; every phase test | PASS |
| 34 | Status, approval and effective-date tampering through write routes is refused; hidden calculation fields are not fillable | ProductionGateTest 88 | PASS |
| 35 | Classification import needs `classifications.manage`; dry run writes nothing; re-import is idempotent | ServiceCatalogTest | PASS |
| 36 | Exports are built on the queue, stored privately, downloadable only by the requester or an exporter in scope; CSV formula injection neutralised | ReportsTest 73–74 | PASS |
| 37 | Scoped lookups: bounded to 30 rows, refused outside the scope and without a design permission | ProductionGateTest 83 | PASS |

## 4. Performance guards

| # | Scenario | Evidence | Result |
|---|---|---|---|
| 38 | Query-count ceilings on readiness, coverage, traceability, gaps, mapping and the position list (120 positions, 40 tasks) | HardeningTest 81 | PASS |
| 39 | Hot-path indexes exist on both drivers; EXPLAIN shows index scans on the dev database | HardeningTest 82; plan doc phase 11 | PASS |
| 40 | No page preloads whole catalogs of tasks, activities, positions or occupations | ProductionGateTest 83 (position design); plan and mapping pages use the lookup endpoint | PASS |

## 5. Browser walkthrough (Amharic, super admin, dev server with built assets)

| # | Screen | Checks | Result |
|---|---|---|---|
| 41 | Position design list and page | empty state, draft profile form with date picker, work-link picker (server search), derive, publish by other person notice | PASS (2026-10-11, phase 8 walkthrough) |
| 42 | Job description page | ten sections in order, derived/custom badges, custom section edit, submit → approve → publish, validation card, PDF links | PASS |
| 43 | Reports page | report switch through the filter panel (no reload-on-select), labelled columns in Amharic, CSV export queued and downloaded, new catalog reports (vacancy) | PASS |
| 44 | Mapping workspace | suggestions with same-code / same-name badges, NEEDS_DATA_REVIEW flag, apply-unambiguous (only the exact-code task mapped), legacy summary | PASS |
| 45 | Sidebar group | all 15 entries, gated by permission | PASS |
| 46 | Phone width (390 px) | reports and mapping pages: single column, tables scroll inside their card, no horizontal page scroll | PASS |
| 47 | Dark theme (system preference) | mapping page renders with the dark tokens; contrast kept | PASS |
| 48 | Loading / validation states | server-side validation messages shown under fields (foreign object, duplicate link, missing date) | PASS |

## 6. Not covered here

- Browser automation (Playwright end-to-end suite) for the domain is not part of the repository; the walkthrough above was manual.
- A 3,000,000-employee rehearsal needs the load-test database (docs/testing.md).
- Restructure impact for hierarchy versions of organizations stays with the existing organizational change module; unit-level impact is shown per proposal on the study page.
