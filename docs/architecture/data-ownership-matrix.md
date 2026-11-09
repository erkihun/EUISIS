# Data ownership matrix

Companion to the [master backbone audit](addis-one-master-backbone-audit.md). Audit date 19 October 2026, HEAD `cc4a44f6`. "Owner" here means the **system** that holds the authoritative record in the current code. Which **institution or office** owns and stewards each master is a policy decision that the repository does not record: every row is marked `NEEDS_POLICY_DECISION` for steward and change authority.

Evidence references (E1–E23) are listed at the end of the main audit.

## 1. Canonical masters

| Domain | Canonical table / model | Authoritative owner | Write paths (current code) | Archive / delete behavior | Machine identifier | Business identifier |
| --- | --- | --- | --- | --- | --- | --- |
| Organization | `organizations` / `Organization` | Addis One | `Actions/Organizations/*`, approved organizational-change requests, hierarchy publish | Soft delete; status field | UUID `id` (UUIDv7) | `code` (unique) |
| Organization type | `organization_types` / `OrganizationType` | Addis One | `Actions/OrganizationTypes/*` | Archive actions | UUID `id` | code |
| Hierarchy / structure version | `hierarchy_versions`, organization edges, closure paths | Addis One | `PublishHierarchyVersionAction`, `CreateOrganizationEdgeAction` | Versions kept; draft/published states | UUID `id` | version label |
| Organization unit | `organization_units` / `OrganizationUnit` | Addis One | `Actions/OrganizationUnits/*`; legacy institution-office routes now create units | Soft delete; mutable `parent` (not versioned) | UUID `id` | `(organization_id, code)` unique |
| Unit type | `organization_unit_types` | Addis One | `Actions/OrganizationUnitTypes/*` | Archive actions | UUID `id` | code |
| Position | `positions` / `Position` | Addis One | `Actions/Positions/*`, organization-design publish | Soft delete via `ArchivePositionAction` with **no active-assignment guard** (F10) | UUID `id` | `code`, `job_position_code` (unique) |
| Position profile / job description | `position_profiles`, `job_descriptions` | Addis One | `PositionProfileService` | Versioned | UUID `id` | version |
| Grade level, occupation, profession, ISIC activity | reference tables under `Actions/GradeLevels`, `Occupations`, `IsicActivities` | Addis One | Admin actions | Archive actions | UUID `id` | code; provenance of official classifications NEEDS_POLICY_DECISION |
| Employee | `employees` / `Employee` | Addis One | `RegisterEmployeeAction`, `OrganizationStructureImportService`, CSV import, web update, self-service change review, transfer | **No soft delete**; `status` / employment status | UUID `id` (UUIDv7) | `employee_number` (unique) |
| Assignment | `employee_assignments` / `EmployeeAssignment` | Addis One | `RegisterEmployeeAction`, `CompleteTransferAction`, `CompleteVacancyTransferAction` | History kept; `is_current`, `effective_from/to`, `assignment_status`; at most one current (DB partial unique) | UUID `id` | none (not a business object) |
| ID card | `id_cards` / `IdCard` | Addis One | `Actions/IdCards/*` (issue, print, replace, revoke) | History kept; `status`, `qr_status`, `is_current`, `previous_card_id` chain; one live current card (DB partial unique) | UUID `id`; `public_card_uuid` for QR | `card_number` (unique) |

## 2. Satellite and specialized domains

| Domain | Authoritative owner | Readers | Writers | External consumers | Sync method today | Boundary class |
| --- | --- | --- | --- | --- | --- | --- |
| Cafeteria (internal module) | Addis One cafeteria module | Cafeteria staff, provider users, reports | Cafeteria actions | None found | In-process | TRANSACTIONAL SATELLITE |
| Cafeteria (separate app, `external-apps/cafeteria-system`) | Cafeteria application, its own `cafeteria_db` | Cafeteria operators | Cafeteria app | — | Real-time HTTP lookup to `/api/v1/id-cards/verify` + eligibility; stores `employee_number`/`employee_name` snapshots, **no canonical UUID** (F3) | TRANSACTIONAL SATELLITE with CACHE/COPY snapshots |
| Transport | Addis One transport module | Transport staff | Transport actions | None found | In-process; references `Employee` | TRANSACTIONAL SATELLITE |
| Performance (EPMS) | Addis One performance module | Supervisors, HR | Performance actions | None found | In-process; agreements reference employee, assignment, organization, position | SYSTEM OF RECORD for performance; satellite of the core |
| Daily work / field work | Addis One | Supervisors | Employees, supervisors | None | In-process; pins assignment context | TRANSACTIONAL SATELLITE |
| Transfer | Addis One | HR | `CompleteTransferAction` | None | In-process; changes assignment, keeps employee | SYSTEM OF ENGAGEMENT writing the assignment master |
| Recruitment / vacancy | Addis One (internal mobility only) | HR | Vacancy actions | None | In-process; requires existing employee | SYSTEM OF ENGAGEMENT; external recruitment NOT_IMPLEMENTED |
| Public feedback | Addis One public feedback module | Public, staff | Public submissions, staff answers | Public site | In-process; `public_feedback_subject_employees.employee_id` FK | SYSTEM OF RECORD for feedback; references core |
| Grievance / discipline / appeal | Addis One case modules | Committees, HR | Case actions | None | In-process; case parties reference employees | SYSTEM OF RECORD for cases |
| Tribunal / court cases | NOT_IMPLEMENTED (`court_cases` is a disabled placeholder) | — | — | — | — | NOT_APPLICABLE today |
| Situation Room / Intelligence / Assistant | Addis One | Leaders, admins | Own alert/forecast/audit rows only | None | Scoped aggregates over core data | DERIVED DATA |
| Provider users (`ProviderUser`, `ServiceProviderUser`, `CafeteriaProviderUser`) | Addis One auth domain | Provider portals | Admins | — | Separate credentials, **no `employee_id` link** | SYSTEM OF RECORD for provider credentials, not HR |
| User accounts (`users`) | Addis One auth domain | Everyone signed in | Users, admins | — | `users.employee_id` (unique, nullable) plus legacy email auto-matching; own encrypted `national_id`, `phone_number` copies | SYSTEM OF ENGAGEMENT; overlapping profile fields need a field-ownership rule |
| Legacy institution office | Addis One (deprecated) | Legacy screens | Legacy routes now write `OrganizationUnit` | — | `organization_units.institution_office_id` mapping | CACHE/COPY pending retirement |
| Payroll, attendance, training | NOT_IMPLEMENTED | — | — | — | — | NEEDS_POLICY_DECISION before any contract |

## 3. System-of-record classification

| Class | Members |
| --- | --- |
| SYSTEM OF RECORD | Organization, organization type, hierarchy version and edges, organization unit, unit type, position, position profile/JD, reference data, employee, assignment, ID card, provider credentials, user credentials |
| SYSTEM OF ENGAGEMENT | Staff web UI, self-service portal, transfer and vacancy workflows, organization-design proposals, public ID checker, partner v1 API |
| DERIVED DATA | `PositionOccupancy` (rebuildable establishment ledger — must not compete with active assignments), closure paths, Situation Room rollups, dashboard metrics, intelligence forecasts, card print snapshots |
| CACHE / COPY | Separate cafeteria `employee_number`/`employee_name` snapshots, user-account profile copies, legacy institution-office mapping, application cache entries |
| TRANSACTIONAL SATELLITE | Cafeteria and transport transactions, ledgers and settlements, service transactions, daily activity, field work, NFC verifications, card verification rows |

## 4. Recommended write authority (conceptual — governance to be approved)

| Operation | Recommended authority | Current state |
| --- | --- | --- |
| Create / change organization, unit, position, hierarchy | Addis One only, through approved change commands | Addis One only; no partner write endpoint |
| Create employee (hire) | Addis One only; future recruitment system through a governed onboarding command with idempotency and audit | Addis One only; no external onboarding endpoint |
| Change employee identity fields (name, DOB, national ID) | Addis One only, through reviewed change requests | Addis One web update and self-service review |
| Change employment status / separation | Addis One only | Addis One only |
| Change assignment / transfer | Addis One only, through transfer/vacancy commands | Addis One only |
| Issue / revoke card | Addis One only | Addis One only |
| Record service consumption | Owning satellite (cafeteria, transport) | Provider routes (F16) and NFC routes; separate cafeteria app records locally |
| Read master data | Read-only to partners, least-privilege scopes, organization-bounded where approved | Read-only, city-wide per grant (F2) |
| Propagate master changes | Event-fed (outbox + signed webhook) plus cursor reconciliation | NOT_IMPLEMENTED (F8, F9) |

## 5. Policy gaps (not code defects)

- Institutional owner, data steward and change authority for each master.
- Approved partner purposes and field lists (which fields each partner class may receive).
- Whether any partner is approved for city-wide rather than organization-bounded reads.
- Legal classification of fields (the audit proposes PUBLIC / INTERNAL / CONFIDENTIAL / RESTRICTED as engineering defaults only).
- Retention schedules and legal holds for master history, card history and API logs.
- Acceptable propagation delay for status, assignment and card revocation changes.
- Provenance and approval of official classification catalogs (ISCO/ISIC/CPC); none should be exposed without it.
