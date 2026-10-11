# Organization Design & Workforce Planning — design and task plan

**Status:** DRAFT for review. No code has been written. The brief this plan answers was received truncated at its section 129 ("TESTS — OCCUPATION / COMPETENCY"); sections 130 onward are not reflected here.

**Goal:** let an institution be designed from its legal mandate through functions, services, processes, activities, tasks, work standards, occupations, competencies, unit design, responsibility mapping, structure, manpower estimation, position requirements, position profiles and job descriptions — with end-to-end traceability — on top of what EUISIS already has, without duplicating it.

---

## 1. What the repository already has (section 0 of the brief)

The brief's chain, mapped to the code that exists today. **Reuse** means the plan extends that model; **New** means nothing equivalent exists.

| Brief concept | Existing | Decision |
|---|---|---|
| Organization, Organization Type | `Organization`, `OrganizationType`; hierarchy by `hierarchy_versions` + `organization_edges` + closure paths (`docs/organization-model.md`, `docs/hierarchy-version-audit.md`) | Reuse |
| Organization Unit, Unit Type | `OrganizationUnit` (`parent_unit_id` flat tree, **not** versioned), `OrganizationUnitType` | Reuse; versioning of units is the main structural gap (§5) |
| Structural vs functional relationship | `organization_edges` (structural, between organizations); `OrganizationUnitRelationship` (functional: `functional_reporting`, `technical_supervision`, …) | Reuse; keep separate as the brief requires |
| Structure version workflow | `HierarchyVersionStatus`: draft → published → archived; `PublishHierarchyVersionAction` already detects cycles and duplicates | Reuse; add `under_review`/`validated`/`approved` only if the owner wants the longer lifecycle (D-5) |
| Organizational change (restructure) | `OrganizationalChangeRequest` with items, reviews, history, impact analyzer, `ApplyApprovedOrganizationalChangeService` | Reuse as the **restructure/impact** workflow (§114–115) |
| Position | `Position` (`occupation_id`, `grade_level`, `job_family` text, `effective_from/to`, metadata) | Reuse |
| Position capacity | `PositionEstablishment` (`approved_slots`, status, approval) + `PositionCapacityService` (one slot when no establishment) | Reuse — this is the "approved headcount" record (§54–59) |
| Employee assignment | `EmployeeAssignment` (carries `hierarchy_version_id`) | Reuse |
| Job grade / level | `GradeLevel` (name only); `Position.grade_level` is free text | Reuse; linkage by FK is a small follow-up (D-9) |
| Occupation, ISCO | `Occupation` with `isco_code` and the four ISCO group codes, `skill_level` | Reuse; ISCO is **denormalised onto occupations**, there is no ISCO catalog table (§27) |
| ISIC | `IsicActivity` (section/division/group/class, parent, level) | Reuse; no `revision` column (§97) |
| CPC | none | New, catalog only, **OFFICIAL_DATASET_REQUIRED** |
| Service catalog | `ServiceType` (platform service modules: cafeteria, transport…), `PublicService` (public website content), `PositionService` (a position's main service) | **None is the institutional service catalog.** New `services` table; `PositionService` stays as the position-level responsibility (§67–68) |
| Process / Activity / Task / Standard | `PositionService` → `PositionServiceSubService` → `PositionServiceTask` → `PositionServiceTaskStandard` (versioned, approved/retired, snapshotted into `daily_activity_items`; `docs/daily-work-register.md`) | **Reuse the standard and task machinery.** The existing chain is position-scoped; the brief's Process/Activity/Task is organization-scoped master design. Plan: new org-level `processes`/`activities`/`tasks`, and `position_service_tasks` gain a nullable `task_id` link to the master task, so Daily Work keeps working unchanged (§18–25) |
| Work standard | `PositionServiceTaskStandard` (`TaskStandardService`: versions, effective dates, immutability, snapshots) | Reuse the service; attach standards to master activities/tasks too |
| Daily work | `DailyActivityLog`/`Item` with task and standard snapshots, server-side scoring | Reuse, no change to execution (§25) |
| Competency | `CompetencyFramework`, `Competency` (code, names, framework), `PositionCompetency` (weight only), `EmployeeCompetencyAssessment` (EPMS, 1–10 rating) | Reuse; add `competency_type`, levels, and `required_level`/`is_mandatory` on requirements (§30–33, 69) |
| Job description | none (`Position.description_en/am` only) | New |
| Mandate, powers, functions | none | New |
| Workforce planning / manpower | none | New |
| Code generation | `CodeGeneratorService` + `CodeRuleEntityType` enum (35 entity types) | Reuse; add entity types for function, service, process, activity, task, mandate, manpower plan, job description |
| Scope | `OrganizationScopeService` (`applyOrganizationScope`, `canAccess`, `isUnrestricted`) | Reuse everywhere |
| Workflow / status history | no generic engine. Each domain has its own: `GrievanceStatusHistory`, `OrganizationalChangeRequestHistory`, `TransferApproval`, `DisciplinaryStatusHistory` | Follow the domain-table convention: one `organization_design_status_history` table for the new domain (§94) |
| Audit | `AuditLog` (as used by EPMS via `EpmsAudit`) | Reuse |
| Documents | per-domain private attachments (`EmployeeDocument`, `PerformanceEvidence`, change-request attachments) | New `organization_design_documents` (private disk) following the same pattern |
| Decimal maths | `App\Services\Performance\Calculation\Dec` (brick/math, HALF_UP, 10 dp computing, 4 dp stored) | Reuse for manpower calculation (§53) |
| Strategic plan | `StrategicGoal`, `StrategicGoalAllocation`, `PerformancePlan` lineage | Keep separate; link only from position profile/job description (§68, 83) |
| Organogram | `OrganizationController::organogram` from `GetOrganizationFullTreeAction` (organizations → units → positions), PDF export | Reuse; add version selector and structural/functional toggle (§42–43) |
| Exports | CSV streaming with formula guard (EPMS pattern), DomPDF | Reuse |
| Navigation | `AppSidebar.tsx` (currently has **uncommitted user edits** — do not touch until merged) | New group added only after the sidebar WIP lands |

**Conclusion:** roughly 40 % of the brief is covered by existing models. The genuinely new domain is **Mandate → Functions → Services → Processes/Activities/Tasks (org level) → Unit design & responsibility → Manpower planning → Position requirements/profiles → Job descriptions**, plus a traceability/gap layer over everything.

---

## 2. Decisions the brief leaves open (NEEDS_DECISION)

Each must be answered by the business owner before the affected phase starts. None is guessed in the plan.

| # | Decision | Affects | Plan's default if undecided |
|---|---|---|---|
| D-1 | **Unit versioning.** Organization units are a flat tree outside hierarchy versions. The brief wants unit design inside a draft structure version with impact analysis before publishing. Options: (a) version units inside `hierarchy_versions` (large change to units, assignments, scope queries); (b) keep units unversioned and run restructures through the existing `OrganizationalChangeRequest` workflow, with the design study producing change-request items. | §35–44, 79, 112–115 | **(b)** — reuses the approved/implemented change-request pipeline and its impact analyzer; the design study links to a hierarchy version for organization-level changes only |
| D-2 | **Legal instrument types**: configurable catalog or enum? | §4 | Catalog table seeded with the seven listed types |
| D-3 | **Service classification scheme**: is there an approved Local Government Service Classification list? | §12 | Empty configurable catalog; **OFFICIAL_DATASET_REQUIRED** |
| D-4 | **CPC dataset**: is the official CPC (v2.1) file available for import? | §14 | Catalog + importer shipped empty; **OFFICIAL_DATASET_REQUIRED** |
| D-5 | **Structure lifecycle**: keep draft → published → archived, or extend to draft → under_review → validated → approved → published → superseded? | §41 | Keep existing; add `under_review` and `approved` only if a reviewer role separate from the publisher is required |
| D-6 | **Manpower methodology parameters**: productive time %, leave allowance, utilisation, span of control, automation factor, minimum staffing. | §46–51 | No defaults seeded; a methodology cannot be activated until each parameter it uses is set and approved |
| D-7 | **Rounding rule** FTE → headcount. | §54–55 | Stored per methodology (`ceil`/`floor`/`half_up`/`manual`); calculation stores `calculated_fte` and leaves `recommended_headcount` null until a rule exists |
| D-8 | **Who approves what**: separate designer / reviewer / approver roles per entity, or reuse `work_standards.approve`-style city-level approvers? | §72–73, 91 | New permissions per entity (§7 of this plan); roles assigned by the RBAC owner |
| D-9 | **Grade/level FK**: convert `Position.grade_level` (text) to `grade_level_id`? | §57, 61 | Out of scope for this domain; recorded as a follow-up |
| D-10 | **Job families**: introduce Job Family → Sub-family → Job Profile? | §62 | **No.** `Position.job_family` text stays; a job-profile layer is not justified by current public-service requirements |
| D-11 | **Existing position services vs master tasks**: should existing `position_service_tasks` be mapped to master tasks retroactively? | §67, 116–117 | No retroactive mapping; they show as `UNMAPPED_EXISTING_RECORD` in the mapping workspace |
| D-12 | **ISCO catalog**: keep ISCO denormalised on `occupations`, or add an `isco_groups` reference table with revision? | §27, 97 | Add the reference table (ISCO-08 is small, ~600 rows), **official dataset required**; keep the denormalised codes on occupations as the recorded revision |
| D-13 | **Competency levels**: numeric 1–10 (EPMS assessments) vs named levels (Foundational…Expert)? | §32 | Named levels in a `competency_levels` catalog mapped to numeric ranks, so EPMS ratings remain comparable |

---

## 3. Target data model (new tables)

All tables: UUID PK, `organization_id` where scoped, `created_by`/`updated_by`, timestamps, soft deletes only on master records that can be archived. Codes come from `CodeGeneratorService`. PostgreSQL CHECK constraints follow the EPMS migration's pattern (dates ordered, decimals ≥ 0).

### 3.1 Mandate and functions
- `legal_instrument_types` — code, names, is_active (D-2).
- `institution_mandates` — organization_id, code, title/description (am/en), legal_instrument_type_id, legal_instrument_number, legal_reference, article_reference, issuing_authority, effective_from/to, version_no, `lineage_key`, status, approved_by/at. Unique (`lineage_key`, `version_no`). A published mandate is immutable; a change creates `version_no + 1` and sets the previous `effective_to`.
- `organization_powers` — organization_id, mandate_id, code, title/description, legal_reference, sequence, effective dates, status.
- `organization_functions` — as powers plus `parent_function_id`, `power_id` nullable, version_no. Cycle check in `FunctionService` (the `OrganizationTreeService` DFS pattern); tree read with one query ordered by a materialised `path` column.

### 3.2 Services and classification
- `service_classifications` — one table for both schemes: scheme (`LOCAL`, `CPC`), revision, code, parent_id, level, names, is_active; unique (scheme, revision, code) (D-3, D-4, **OFFICIAL_DATASET_REQUIRED** — ships empty). *Implemented in phase 2; the separate `cpc_classifications` table in the first draft was folded in here so one importer and one steward permission cover both.*
- `isic_activities.revision` — added (default `Rev.4`) so ISIC rows carry their revision like the other reference data.
- `classification_import_batches` — every CSV import run (LOCAL / CPC / ISIC), dry or real: counts, status, error report, fingerprint. Dry run writes no reference rows; a real run is idempotent on (scheme, revision, code).
- `services` — organization_id, code, names, descriptions, service_type_id (nullable, reuse `ServiceType` only where the service is delivered through a platform module), service_classification_id (active LOCAL), cpc_classification_id (CPC), beneficiary_type, delivery_channel, legal_basis, owner_organization_unit_id, status, version_no, lineage_key, effective dates. Versioned exactly like mandates (maker ≠ checker on publish).
- `function_service` — function_id, service_id, unique pair (§11); functions must belong to the service's organization.
- `service_isic_activity` — optional domain-level ISIC link (§16), never on tasks.

### 3.3 Processes, activities, tasks, standards
- `processes` — organization_id, service_id, code, names, process_type, owner_unit_id, status, version_no, effective dates.
- `activities` — process_id, parent_activity_id, code, names, sequence, status, version_no.
- `tasks` — activity_id, code, names, sequence, required_output, status, version_no.
- `work_items_io` — polymorphic (process/activity/task) inputs, outputs, beneficiaries, dependencies as typed rows (§22).
- **Standards:** generalise `position_service_task_standards` → add nullable `activity_id`/`task_id` columns and a CHECK that exactly one of (`task_id` [position-service task], `activity_id`, `master_task_id`) is set, so `TaskStandardService` serves both. Alternative if the owner prefers isolation: a new `work_standards` table with the same columns; the plan recommends the extension because snapshots, versioning and approval are already tested.
- `position_service_tasks.master_task_id` nullable FK → `tasks` (the bridge to Daily Work, §25, §67).
- Internal codes `HR-WFP-001-01` via new `CodeRuleEntityType::{Process, Activity, Task}` with parent-code tokens (§17).

### 3.4 Occupation and competency requirements
- `isco_groups` — code, level (major/sub-major/minor/unit), parent_code, titles, revision (D-12).
- `professions` — occupation_id, code, names (profession/specialization, §28); `specializations` under them if needed.
- `work_occupation_requirements` — activity_id or task_id, occupation_id, profession_id nullable, specialization_id nullable.
- `competencies` + `competency_type` (CORE/TECHNICAL/…; §30), `competency_levels` (D-13).
- `work_competency_requirements` — activity/task, competency_id, minimum_level_id, is_mandatory.
- `work_qualification_requirements` — education_level, field_of_study, certification, license, experience_years (§34), typed columns not free text.

### 3.5 Unit design and responsibility
- `organization_design_studies` — organization_id, reference_number, title, study_type (INITIAL_STRUCTURE, RESTRUCTURE, …), reason, status, started_at, target_effective_date, hierarchy_version_id nullable, organizational_change_request_id nullable (D-1), prepared/reviewed/approved_by (§112–113).
- `unit_design_proposals` — study_id, proposed_unit_type_id, proposed names, design_rationale, parent_proposed_unit_id, existing_unit_id nullable (when the proposal is a change to an existing unit), status (§36).
- `responsibility_types` — RESPONSIBLE/ACCOUNTABLE/CONSULTED/SUPPORTING, configurable (§38).
- `unit_responsibilities` — subject (organization_unit_id **or** unit_design_proposal_id), object (function/service/process/activity polymorphic), responsibility_type_id; unique per (subject, object, type) (§37).

### 3.6 Workforce planning
- `workforce_planning_methodologies` — code, names, `calculation_type` (whitelisted: `WORKLOAD_STANDARD_TIME`, `RATIO`, `FIXED_MINIMUM`, `SPAN_OF_CONTROL`), `parameters` JSON validated against a per-type schema, rounding_rule, version_no, status, effective dates (§47–48). No expressions, ever.
- `manpower_plans` — reference_number, organization_id, hierarchy_version_id, study_id nullable, planning_period_start/end, methodology_id, status, version_no, prepared/reviewed/approved_by.
- `manpower_plan_lines` — plan_id, organization_unit_id (or proposal), activity_id/task_id, occupation_id, `inputs` JSON snapshot (validated), `standard_version_id`, `methodology_version_no`, `calculated_fte` DECIMAL(12,4), `recommended_headcount` int nullable, `approved_headcount` int nullable, adjustment_reason, adjusted_by, approved_by, `calculation_trace` JSON (§52, 56).
- `position_requirements` — plan_id, organization_unit_id, occupation_id, profession_id, grade_level, position_type, required_fte, approved_headcount, status; on approval `PositionDesignService` creates/updates `positions` + `position_establishments` (never assignments; §57–58, 100).

### 3.7 Position profile and job description
- `position_profiles` — position_id, version_no, lineage_key, status, purpose, reports_to_position_id, working_conditions, effective dates (§61).
- `position_competency_requirements` — extend `position_competencies` with `required_level_id`, `minimum_level_id`, `is_mandatory`, effective dates (§69).
- `position_qualification_requirements` — typed rows (§70).
- `position_work_links` — position_id → service/process/activity/task (stable responsibilities; `PositionService` remains the EPMS-facing record, §67–68).
- `job_descriptions` — position_id, position_profile_id, version_no, lineage_key, status (draft/under_review/approved/published/superseded), effective dates, approved/published_by; sections A–W as typed columns or child rows (`job_description_sections` with `section_key`, `source` = `derived`|`custom`, `content_am/en`), where derived sections are regenerated from masters and custom ones are edited (§64–66).
- `employee_assignments.job_description_id` nullable — recorded on assignment so history resolves the JD in force (§66).

### 3.8 Cross-cutting
- `organization_design_status_history` — entity_type, entity_id, from/to status, actor, reason, at (§94).
- `organization_design_documents` — entity, document_type (catalog), private path, uploader (§95).
- `classification_import_batches` — dataset (ISCO/ISIC/CPC/services/activities/standards), dry-run flag, row counts, error report path, idempotency key (§96).

---

## 4. Services (new; thin controllers)

`MandateService`, `FunctionService`, `ServiceCatalogService`, `ProcessArchitectureService`, `WorkStandardService` (wraps `TaskStandardService`), `OccupationRequirementService`, `CompetencyRequirementService`, `OrganizationDesignService` (studies, proposals, responsibilities), `StructureValidationService`, `WorkforcePlanningService`, `ManpowerCalculationService` (strategies under `Calculation/`, using `Dec`), `PositionDesignService`, `JobDescriptionService`, `TraceabilityService`, `OrganizationDesignValidationService` (ERROR/WARNING/INFO, §119), `OrganizationDesignGapService` (§77, 118), plus query services for reports (§121).

Validation gates (§79–81) live in `OrganizationDesignValidationService` and are called by the approve/publish actions; React never decides.

---

## 5. Structure, organogram and restructure (D-1 dependent)

With default (b): a design study collects unit proposals and responsibilities; "Generate change request" turns proposals into `OrganizationalChangeRequest` items; the existing review → approve → implement pipeline applies them to `organization_units`; `ChangeRequestImpactAnalyzer` supplies the §114 impact view. Organization-level structure keeps using hierarchy versions. The organogram gains a version selector (published versions) and a functional-relationship overlay from `organization_unit_relationships`; its validation (§44) reuses `PublishHierarchyVersionAction` checks plus unit-level checks added to `StructureValidationService`.

If the owner chooses (a), add a phase 0 that versions units; everything downstream is unchanged but the estimate roughly doubles for phases 5–6.

---

## 6. Permissions (project convention: `kebab-group.camelAction`)

`institution-mandates.{viewAny,view,create,update,approve}`, `organization-functions.{viewAny,manage}`, `service-catalog.{viewAny,manage,approve}`, `processes.{viewAny,manage}`, `work-standards.{viewAny,manage,approve}` (exists: `work_standards.manage/approve` — align naming, D-8), `organization-design.{viewAny,manage,review,approve}`, `hierarchy-versions.*` (exists), `workforce-planning.{viewAny,create,review,approve}`, `position-profiles.{viewAny,manage,approve}`, `job-descriptions.{viewAny,manage,approve,publish}`, `classifications.manage` (city-level), `organization-design-reports.{view,export}`. Added to `permissions.php`, `PermissionCatalog`, `DefaultRoleMatrix` and the RBAC catalog test.

---

## 7. UI

New sidebar group **Organization Design & Workforce Planning / የተቋም አወቃቀርና የሰው ኃይል ዕቅድ** (added after the sidebar WIP is committed). Pages use the list kit (`FilterPanel`, `ListPagination`, `TableCard`, `docs/ui-components.md`). The design study is a staged workspace with the twelve tabs of §111; each tab is its own page/route, nothing is one giant form. Pickers for employees, positions, services and activities are server-searched (`/lookups/*` pattern from EPMS), never preloaded.

---

## 8. Phases and tasks

Each phase ends with migrations, models, services, policies, routes, pages, translations (en/am), tests, and a doc under `docs/`. Order respects dependencies; phases 2–4 can run in parallel after phase 1.

| Phase | Scope | New tables | Tests (brief numbering) | Size |
|---|---|---|---|---|
| **0. Foundations** | status history, documents, import batches, permissions, code-rule entity types, validation service skeleton, sidebar group, dashboard shell | 3 | catalog/permission tests | S |
| **1. Mandate & functions** — *done* | §3–8 | 4 | 1–5 | M |
| **2. Services & classification** — *done* | §9–16; ISIC revision; one LOCAL/CPC catalog table + CSV importer (dry run, idempotent, per revision); `LegalInstrumentTypeSeeder` | 4 (+1 column) | 6–15 | M |
| **3. Process architecture & standards** | §17–25; master process/activity/task; standard generalisation; Daily Work bridge | 4 (+2 columns) | 16–25 | L |
| **4. Occupations & competencies** | §26–34; ISCO reference, professions, requirement tables, competency types/levels | 7 | 26–… (truncated in brief) | M |
| **5. Unit design & responsibility** | §35–39, 112–113 | 4 | structure tests | M |
| **6. Structure, organogram, impact** | §40–44, 79, 114–115 (D-1) | 0–1 | validation gate tests | M (L if D-1 = a) |
| **7. Workforce planning** | §45–57, 80; methodologies, plans, lines, calculation strategies, position requirements | 4 | manpower gate tests | L |
| **8. Position profile & job description** | §58–71, 81; profiles, requirements, JD sections, versioning, assignment link | 6 | JD gate tests | L |
| **9. Traceability, gaps, reports, analytics** | §76–78, 88, 98–99, 104–105; queued exports | 0 | report/export tests | M |
| **10. Mapping workspace & data quality** | §116–118; UNMAPPED_EXISTING_RECORD flags, mapping UI | 0 | migration-safety tests | S |
| **11. Hardening** | §89–93, 101–103, 122–123: scope audit, IDOR tests, indexes from EXPLAIN, concurrency locks, 3M-scale checks | — | security + scale tests | M |

S ≈ 1 day, M ≈ 2–4 days, L ≈ 5–8 days of focused work. Total: on the order of 8–10 weeks; D-1 = (a) adds ~2 weeks.

---

## 9. Non-negotiables carried from the brief

- No automatic employee termination, transfer, demotion, salary or grade change from planning (§100).
- No executable formulas; whitelisted strategies only (§48).
- No invented policy values, classification codes or historical traceability (§14–15, 27, 51, 117).
- Published mandates, standards, structures, plans and job descriptions are immutable; amend by version (§74).
- Every query scoped through `OrganizationScopeService`; city-level master data behind `classifications.manage` (§89–90).
- DECIMAL via `Dec`; frontend numbers are previews (§53).

---

## 10. Open questions for the owner before phase 1

1. Answers to D-1 through D-13 above (D-1, D-6, D-7 and D-8 block the most work).
2. The rest of the brief (sections 130+), which likely contains the remaining test list and delivery requirements.
3. Whether official ISCO-08, ISIC Rev.4 and CPC datasets can be provided for import, or whether phases 2 and 4 ship with empty catalogs marked OFFICIAL_DATASET_REQUIRED.
4. Whether the existing `work_standards.*` permissions should be renamed to the kebab convention now (it touches roles already in production seeds).
