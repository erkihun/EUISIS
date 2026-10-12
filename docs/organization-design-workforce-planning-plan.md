# Organization Design & Workforce Planning — design and task plan

**Status:** DRAFT for review. No code has been written. The brief this plan answers was received truncated at its section 129 ("TESTS — OCCUPATION / COMPETENCY"); sections 130 onward are not reflected here.

**Goal:** let an institution be designed from its legal mandate through functions, services, processes, activities, tasks, work standards, occupations, competencies, unit design, responsibility mapping, structure, manpower estimation, position requirements, position profiles and job descriptions — with end-to-end traceability — on top of what Addis One already has, without duplicating it.

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
- `processes` — organization_id, service_id, code, names, process_type (core/support/management), owner_organization_unit_id, lineage_key, version_no, status, effective dates. *Implemented in phase 3.* The process is the versioned aggregate: its activities, tasks and I/O rows are editable only while it is a draft, and a new version deep-copies the whole tree (activities and tasks therefore carry no version_no of their own).
- `process_activities` — process_id, parent_activity_id, code (unique per process), names, sequence, materialised path/depth, is_active. (Named `process_*` rather than the generic `activities`/`tasks` to leave those table names free.)
- `process_tasks` — activity_id, process_id, code (unique per activity), names, sequence, required_output, is_active.
- `work_item_ios` — polymorphic (process/activity/task) inputs, outputs, beneficiaries, dependencies as typed rows (§22); a dependency may name an activity of the same process.
- **Standards:** `position_service_task_standards` generalised — `task_id` now nullable, plus nullable `master_task_id` and `activity_id`; exactly one is set (PostgreSQL CHECK + model guard on every driver). `TaskStandardService` serves all three subjects unchanged in behaviour; standards on master tasks/activities reuse the existing `work_standards.manage/approve` permissions.
- `position_service_tasks.master_task_id` nullable FK → `process_tasks` (the bridge to Daily Work, §25, §67). Traceability only: Daily Work keeps measuring against the position task's own standards; the mapping UI arrives with phase 10 (the route `organization-design.position-tasks.link` exists now).
- Internal codes `HR-WFP-001-01` via new `CodeRuleEntityType::{Process, Activity, Task}` with parent-code tokens (§17).

### 3.4 Occupation and competency requirements
*Implemented in phase 4.*
- `isco_groups` — revision, code, level (major/sub_major/minor/unit), parent_id, names (D-12). Loaded through the same CSV importer as LOCAL/CPC/ISIC (`scheme=ISCO`), browsable on the Classifications page, never typed in. `occupations.isco_group_id` lets an occupation cite the reference row; the recorded ISCO codes stay on the occupation.
- `professions` (occupation_id, code unique) and `specializations` (profession_id, code unique per profession) — city-level catalogs behind `professions.manage`; `restrictOnDelete` from requirements, so an entry in use is deactivated.
- `competency_types` (CORE/TECHNICAL/LEADERSHIP/BEHAVIOURAL seeded as starting data), `competencies.competency_type_id`, `competency_levels` (code, rank 1–10 unique; five levels seeded mapping to the EPMS 1–5 competency scale, D-13), `education_levels` (ranked ladder; eight generic levels seeded — **NEEDS_DECISION:** align with the civil service qualification ladder). All behind `competency-catalog.manage`, read with `competency-catalog.viewAny`.
- `work_occupation_requirements` — activity_id xor task_id, occupation_id, profession_id, specialization_id (each must belong to the one above and be active), is_primary.
- `work_competency_requirements` — activity_id xor task_id, competency_id (unique per subject), minimum_level_id, is_mandatory.
- `work_qualification_requirements` — activity_id xor task_id, education_level_id, field_of_study, certification, license, experience_years, is_mandatory — typed columns, no free-text requirement.
- Requirements are replaced as sets (`PUT organization-design/requirements/{activity|task}/{id}`) only while the process is a draft, and are deep-copied with a new process version together with the tree.

### 3.5 Unit design and responsibility
*Implemented in phase 5.*
- `organization_design_studies` — organization_id, reference_number (`ODS-YYYY-NNNN`), titles, study_type (initial_structure / restructure / review), reason, scope_notes, status (draft → under_review → approved, or cancelled), started_at, target_effective_date, hierarchy_version_id nullable, prepared/reviewed/approved_by (§112–113). Maker ≠ checker on approval; a reviewer may return it with a comment.
- `unit_design_proposals` — study_id, proposal_type (create / update / move / deactivate), existing_unit_id (required unless create), parent_existing_unit_id **or** parent_proposed_unit_id (cycle-checked), proposed_unit_type_id, names, design_rationale, effective dates, status (proposed → accepted on approval → converted → implemented), `organizational_change_request_id`, `resulting_unit_id`. *Ruling:* the change-request pipeline holds one item per request, so each proposal becomes **one** `OrganizationalChangeRequest` (the plan's single study-level request id moved to the proposal). "Create change requests" is idempotent and skips proposals whose proposed parent is not implemented yet; "Adopt implemented units" reads `resulting_entity_id` from the request item, records the unit on the proposal and moves its responsibilities to the unit.
- `responsibility_types` — code, names, `is_exclusive` (held once per object, RACI's accountable), `covers_object` (counts in the gap report), configurable; RESPONSIBLE / ACCOUNTABLE / CONSULTED / SUPPORTING seeded as starting data (§38).
- `unit_responsibilities` — organization_unit_id **xor** unit_design_proposal_id, object_type (function / service / process / activity) + object_id (same organization), responsibility_type_id, notes; unique per (subject, object, type) (§37). Matrix and gap report per organization at `organization-design/responsibilities`.

### 3.6 Workforce planning
*Implemented in phase 7.*
- `workforce_planning_methodologies` — city-level, versioned by lineage (draft → published → superseded, maker ≠ checker): code, names, `calculation_type` (whitelisted strategies in `app/Services/OrganizationDesign/Calculation/`: `WORKLOAD_STANDARD_TIME`, `RATIO`, `FIXED_MINIMUM`, `SPAN_OF_CONTROL`), `parameters` JSON checked against the strategy's plain schema (numbers in range, no unknown keys, never an expression), `rounding_rule` (ceil / floor / half_up / manual, D-7), effective dates. Publishing refuses until every required parameter is set (D-6); nothing is seeded.
- `manpower_plans` — `MPP-YYYY-NNNN`, organization_id, hierarchy_version_id, study_id, methodology_id, titles, period, status (draft → under_review → approved, or cancelled), prepared/reviewed/approved_by, implemented_at.
- `manpower_plan_lines` — plan_id, organization_unit_id **xor** unit_design_proposal_id, activity_id/task_id, occupation_id, profession_id, methodology_id + `methodology_version_no` (the version in force when calculated), `standard_id` (the approved standard in force on the period's first day, used for `standard_time_minutes` when the line gives none), `inputs`, `calculated_fte` DECIMAL(12,4) via `Dec`, `recommended_headcount` (rule applied; null for manual / no rule), `approved_headcount` + `adjustment_reason` + `adjusted_by` (override needs a reason), `calculation_trace` (formula, parameters, inputs, intermediates as strings). Submit/approve gate: every line calculated and carrying a headcount.
- `position_requirements` — the roll-up at approval by unit (or proposal) × occupation × profession: `required_fte` (sum), `approved_headcount` (sum), status proposed → approved → implemented, position_id, establishment_id. `PositionDesignService::implement` (permission `workforce-planning.implement`) creates each position through `CreatePositionAction` (code `MPP-…-NN`) and an **approved** establishment with `approved_slots` through `StorePositionEstablishmentAction`; idempotent; a requirement on a proposed unit waits for the unit; never an assignment (§57–58, 100). D-9 unchanged: `grade_level` stays text.

### 3.7 Position profile and job description
*Implemented in phase 8.*
- `position_profiles` — position_id, lineage_key, version_no, status (draft → published → superseded, or cancelled; maker ≠ publisher), purpose, reports_to_position_id (same organization, not itself), working_conditions, authorities, relationships (all bilingual), effective dates. One draft per position; a new version is drafted from the published one and supersedes it on publication (§61). `PositionProfileService`.
- `position_work_links` — position_id → service / process / activity / task of the position's organization, `is_primary`, replaced as a set; `PositionService` stays the EPMS-facing record (§67–68).
- `position_competency_requirements` — competency, minimum level, mandatory, `source` = `manual` | `derived`. *Ruling:* a new table keyed by position rather than extending `position_competencies` (which carries assessment semantics); "derive" takes, per competency, the highest level any linked task or activity needs and marks it mandatory if any does; written rows win over derived ones; deriving is idempotent (§69).
- `position_qualification_requirements` — typed rows (education level, field, certification, license, years, mandatory, source), merged on their typed columns with the longest experience when derived (§70).
- `job_descriptions` — position_id, position_profile_id (a published or superseded profile of the same position), lineage_key, version_no, reference `JD-{position}-vN`, status draft → under_review → approved → published → superseded (or cancelled from draft/under_review), effective dates, prepared/reviewed/approved/published_by; one in progress per position. `job_description_sections` in a fixed order — purpose (custom, seeded from the profile), reporting, duties, occupation, qualifications, competencies, standards (derived, `payload` JSON + bilingual text), working_conditions, authorities, relationships (custom). Derived sections are regenerated while the document is a draft; a new version copies the custom sections. Gate (`JobDescriptionService::validate`, §81): ERROR when no duties or the profile is not published; WARNING without purpose, competencies or qualifications (§64–66).
- `employee_assignments.job_description_id` nullable — stamped on the position's current assignments when a JD is published; `JobDescriptionService::inForceFor($position, $date)` resolves the version in force for history (§66). *Note:* the column is added without a foreign key on SQLite because Laravel's table rebuild there drops the `WHERE is_current` clause of the partial unique index (PostgreSQL gets the foreign key).

### 3.8 Cross-cutting
- `organization_design_status_history` — entity_type, entity_id, from/to status, actor, reason, at (§94).
- `organization_design_documents` — entity, document_type (catalog), private path, uploader (§95). *Not built yet* (no phase has needed attachments so far).
- `organization_design_report_exports` — *phase 9 ruling:* the plan budgeted no table for phase 9, but a queued export needs a record of who asked for which report and where the private file landed; one row per export (organization_id nullable = whole scope, report, format csv|xlsx, status queued → ready | failed, disk, path, filename, row_count, error, requested_by, completed_at). The file is built by `ExportOrganizationDesignReport` on the queue **as the requester**, so the organization scope is theirs, stored under `organization-design/exports/` on the private disk, and downloaded only by the requester or an exporter whose scope covers it.

*Phase 9 as built:* `TraceabilityService::trace($type, $id)` walks one object up to its mandate and down to the job descriptions in force (nothing inferred: a link exists only where the design recorded it) and `rows($organizationId)` gives one row per task with the whole chain. `OrganizationDesignGapService::gaps($organizationId)` reuses the validation report shape with warning/info findings only: `mandate_without_functions`, `function_without_services`, `service_without_processes`, `process_without_tasks`, `task_without_standard`, `task_without_requirements` (info), `object_without_responsible_unit`, `position_without_work_links`, `position_without_profile`, `position_without_job_description`, `unit_without_positions` (info). `OrganizationDesignReportService::run($report, $user, $filters)` serves `coverage` (one row per organization), `traceability`, `gaps`, `position_readiness` and `workforce` (plans with totals), always cut to the organizations in scope (first 200; the organization filter narrows). Exports bind strings explicitly (no formula execution in XLSX) and pass CSV rows through `csv_safe_row`. Permissions `organization-design-reports.{view,export}`; page `organization-design/reports`.

*Phase 10 as built (D-11):* `MappingWorkspaceService::unmapped($organizationId)` lists active `position_service_tasks` without `master_task_id`, active positions without work links and active units without a responsibility, each flagged `UNMAPPED_EXISTING_RECORD`; a legacy task gets *suggestions* from the organization's master tasks (same code, else same name) and is flagged `NEEDS_DATA_REVIEW` unless it has exactly one same-code candidate. "Apply unambiguous suggestions" (`processes.update`) maps only those, through `ProcessArchitectureService::linkPositionTask` (audited, idempotent); everything else waits for a person who picks the master task row by row. The legacy task's own standards are never touched — Daily Work keeps measuring against them (§25). `unmappedCatalog()` covers the city-level side: occupations without `isco_group_id` (suggested by `isco_code`, behind `professions.manage`) and competencies without `competency_type_id` (`competency-catalog.manage`). `DataQualityService::check($organizationId)` reports counts of unmapped records (info), `needs_data_review` per ambiguous task (info), `position_task_master_inactive` (warning), `position_task_master_foreign` (error) and `work_link_object_missing` (warning); it fixes nothing. Page `organization-design/mapping` behind `data-mapping.view`.

*Phase 11 as built:* `tests/Feature/OrganizationDesign/HardeningTest.php` sweeps **every** route named `organization-design.*` with a user who holds every permission of the domain but is scoped to another organization: a route that takes an organization-scoped record must answer 403 or 404, a list or store must refuse or serve nothing of the foreign organization, and a route parameter without a registered fixture fails the test (so a future route cannot escape the sweep). City-level catalog parameters are excluded (their permission tests live in their phases). Query-count ceilings guard the position-readiness, coverage, traceability, gap and mapping reads and the position-design list against N+1 regressions. A `pg_indexes` audit of the domain's foreign keys (PostgreSQL does not index them by itself) produced `2026_10_12_100800_add_organization_design_hot_path_indexes` — fifteen organization-scoped indexes, additive and idempotent; EXPLAIN on the dev database shows index scans on `position_service_tasks` (unmapped lookup) and `position_profiles` (published by organization). Draft creation of profiles and job descriptions now locks the position row so "one draft per position" holds under concurrent requests (the versioned publish flows already locked the lineage). Not done, NEEDS_DECISION: a 3M-row scale rehearsal needs the load-test database (see `docs/testing.md`), and `employee_assignments.hierarchy_version_id` has no index but belongs to the existing domain.
- `classification_import_batches` — dataset (ISCO/ISIC/CPC/services/activities/standards), dry-run flag, row counts, error report path, idempotency key (§96).

---

## 4. Services (new; thin controllers)

`MandateService`, `FunctionService`, `ServiceCatalogService`, `ProcessArchitectureService`, `WorkStandardService` (wraps `TaskStandardService`), `OccupationRequirementService`, `CompetencyRequirementService`, `OrganizationDesignService` (studies, proposals, responsibilities), `StructureValidationService`, `WorkforcePlanningService`, `ManpowerCalculationService` (strategies under `Calculation/`, using `Dec`), `PositionDesignService`, `JobDescriptionService`, `TraceabilityService`, `OrganizationDesignValidationService` (ERROR/WARNING/INFO, §119), `OrganizationDesignGapService` (§77, 118), plus query services for reports (§121).

Validation gates (§79–81) live in `OrganizationDesignValidationService` and are called by the approve/publish actions; React never decides.

---

## 5. Structure, organogram and restructure (D-1 dependent)

With default (b): a design study collects unit proposals and responsibilities; "Generate change request" turns proposals into `OrganizationalChangeRequest` items; the existing review → approve → implement pipeline applies them to `organization_units`; `ChangeRequestImpactAnalyzer` supplies the §114 impact view. Organization-level structure keeps using hierarchy versions. The organogram gains a version selector (published versions) and a functional-relationship overlay from `organization_unit_relationships`; its validation (§44) reuses `PublishHierarchyVersionAction` checks plus unit-level checks added to `StructureValidationService`.

If the owner chooses (a), add a phase 0 that versions units; everything downstream is unchanged but the estimate roughly doubles for phases 5–6.

*Phase 6 as built:* `OrganizationDesignValidationService::validateStructure($organizationId)` grades the live unit tree (ERROR: parent loops, duplicate codes, foreign/missing parents, active unit under an inactive parent, inactive unit with active positions; WARNING: unit without type or positions, functions/services/processes nobody covers; INFO: span of control per unit and root count — **no thresholds invented, NEEDS_DECISION for limits**) and `validateStudy($study)` grades proposals (ERROR: create without unit type/name, deactivating a unit with active positions or live sub-units not also deactivated, a move that lands a unit under itself; WARNING: proposal without responsibilities). Study approval refuses on any ERROR (`validation` error key) — the server decides. The study page shows the report and, per proposal, the impact figures from `ChangeRequestImpactAnalyzer::unitImpact`. *Ruling:* instead of changing the existing organogram page, a read-only design-side **Structure** page (`organization-design/structure?organization_id=`) shows the validation report, the unit tree with active functional relationships, and the published hierarchy versions that place the organization, with a link to the organogram; the organogram's own version selector/overlay stays for the UI pass once the sidebar WIP lands.

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
| **3. Process architecture & standards** — *done* | §17–25; master process/activity/task; typed I/O rows; standard generalisation; Daily Work bridge (backend) | 4 (+3 columns) | 16–25 | L |
| **4. Occupations & competencies** — *done* | §26–34; ISCO reference (via the classification importer), professions/specializations, competency types/levels, education ladder, three requirement tables + editor on the process page | 9 (+2 columns) | 26–34 | M |
| **5. Unit design & responsibility** — *done* | §35–39, 112–113; studies, proposals → one change request each, responsibility matrix + gaps | 4 | 35–42 | M |
| **6. Structure, organogram, impact** — *done* | §40–44, 79, 114–115 (D-1 = b); `OrganizationDesignValidationService` (ERROR/WARNING/INFO for the unit tree and for a study), approval gate on studies, impact per proposal from `ChangeRequestImpactAnalyzer`, design-side Structure page (validation, unit tree + functional overlay, published hierarchy versions) | 0 | 43–48 | M |
| **7. Workforce planning** — *done* | §45–57, 80; methodologies (4 strategies, schema-checked parameters, versioned), plans, lines with `Dec` maths + trace, gate, roll-up, implementation as positions + approved establishments | 4 | 49–58 | L |
| **8. Position profile & job description** — *done* | §58–71, 81; profile versions, work links, derived/written requirements, JD sections (derived + custom), lifecycle with gate, assignment stamping | 6 (+1 column) | 59–68 | L |
| **9. Traceability, gaps, reports, analytics** — *done* | §76–78, 88, 98–99, 104–105; traceability up/down one object and per task, gap report, five scoped tabular reports, queued CSV/XLSX exports on the private disk | 1 (ruling: export records) | 69–74 | M |
| **10. Mapping workspace & data quality** — *done* | §116–118; UNMAPPED_EXISTING_RECORD / NEEDS_DATA_REVIEW flags, mapping workspace (position tasks → master tasks with suggestions a person applies, positions without work, units without responsibilities, city-level occupations → ISCO groups and competencies → types), `DataQualityService` | 0 | 75–79 | S |
| **11. Hardening** — *done* | §89–93, 101–103, 122–123: route-wide IDOR sweep, query-count ceilings on the heavy reads, foreign-key index audit → 15 indexes, row locks on draft creation | — (indexes only) | 80–82 | M |

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
