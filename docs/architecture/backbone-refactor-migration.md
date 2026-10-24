# Master backbone refactor: analysis and migration plan

Date: 23 October 2026, main at `626efae2`. This is Pass A (analysis only) of the
backbone separation work plus the staged plan that Pass B follows. It builds on
the [master backbone audit](addis-one-master-backbone-audit.md) of 19 October,
its [remediation record](master-backbone-remediation-implementation.md) and the
[event integration readiness report](event-integration-readiness.md); it does
not repeat their evidence.

Related: [master-backbone.md](master-backbone.md) ·
[data-ownership.md](data-ownership.md) ·
[module-integration-matrix.md](module-integration-matrix.md) ·
[integration-api.md](integration-api.md) ·
[ADR 0001](adr/0001-master-identity-and-organization-backbone.md)

## 1. What exists today

The application is one Laravel 12 modular monolith (one deployment, one
database). Modules are folders by convention (`app/Actions/<Module>`,
`app/Services/<Module>`, `app/Http/Controllers/...`), not packages. There is no
module registry and, before Stage 1, nothing enforced dependency direction.

The Golden Path is implemented and authoritative:

| Link | Table | Machine id | Business id | Integrity already enforced |
| --- | --- | --- | --- | --- |
| Organization | `organizations` | UUIDv7 `id` | `code` | soft delete, deletion guard, versioned edges and closure paths |
| Organizational unit | `organization_units` | UUIDv7 `id` | `code` | soft delete, unit types are configurable |
| Position | `positions` | UUIDv7 `id` | `job_position_code` | archive guard refuses while a current assignment occupies it |
| Employee | `employees` | UUIDv7 `id` | `employee_number` | no delete route; status lifecycle |
| Assignment | `employee_assignments` | UUIDv7 `id` | none | **one current assignment per employee at DB level** (`employee_assignments_one_current_per_employee`) |
| ID card | `id_cards` | UUIDv7 `id`, `public_card_uuid` | `card_number` | **one live current card per employee at DB level**; print snapshots |

Already present and reused, not rebuilt:

- Integration API under `/api/v1` (45 routes): organizations, units, positions,
  employees, assignment, entitlements, service eligibility, card verification,
  NFC, transport, change feed, webhooks.
- Machine principals: `ExternalApplication` with Sanctum tokens, exact scopes,
  organization binding (`city` / `scoped`), provider binding, request log.
- Transactional outbox `integration_events` written in the domain transaction by
  `MasterDataEventProjector` for Organization, OrganizationUnit, Position,
  Employee, EmployeeAssignment and IdCard; ordered keyset change feed
  `GET /api/v1/changes`; signed webhooks with retry, dead state and replay.
- Durable idempotency (`api_idempotency_keys`, atomic claim).
- Canonical scope service: `OrganizationScopeService` (permission = what,
  scope = where).

## 2. Dependency map

Measured with a static scan of `use App\Models\...` imports and write calls in
`app/` and `routes/`. "Uses backbone" counts files that import at least one
backbone model; nearly all of that is reading.

| Module | Files | Files using backbone models | Backbone writes found |
| --- | --- | --- | --- |
| Organization design | 161 | 51 | 1 (job description stamped on current assignments) |
| Cafeteria | 162 | 24 | 0 |
| Public feedback | 82 | 20 | 0 |
| Transfer | 61 | 16 | 2 (assignment close/open, current pointer) |
| EPMS / performance | 113 | 16 | 0 |
| Grievance | 132 | 15 | 0 |
| Transport | 162 | 14 | 0 in runtime code (load-test seeder only) |
| Situation Room | 51 | 14 | 0 |
| Communication | 159 | 13 | 0 |
| Assessment | 73 | 13 | 0 |
| Daily work | 46 | 12 | 0 |
| Provider portals | 60 | 9 | 0 |
| Field work | 39 | 8 | 0 |
| Entitlements / service transactions | 25 | 6 | 0 |
| Recruitment / vacancy | 27 | 5 | 3 (assignment close/open, position occupancy) |
| Intelligence / assistant | 152 | 3 | 0 |
| Discipline | 30 | 3 | 0 |

### 2.1 Service module → backbone writes (target: only through backbone commands)

| Writer | What it writes | Classification |
| --- | --- | --- |
| `Transfers/CompleteTransferAction` | closes the current assignment, opens the new one, moves `employees.current_assignment_id` | duplicate of the vacancy path below; should call one backbone command |
| `Vacancy/CompleteVacancyTransferAction` | the same three writes | duplicate; should call the same backbone command |
| `Vacancy/PositionCapacityService` | `position_occupancies` rows | position occupancy is backbone data held in the Vacancy namespace; also used by backbone registration |
| `OrganizationDesign/JobDescriptionService` | stamps `job_description_id` on current assignments of a position | position profile data; acceptable as a backbone-owned job description service, to be moved under the backbone namespace |
| `OrganizationStructure/ApplyApprovedOrganizationalChangeService` | units and positions | this **is** the backbone's controlled structural-change command (approved change requests); not a violation |

### 2.2 Backbone → service module dependencies (not allowed by the target)

| Backbone file | Depends on | Target |
| --- | --- | --- |
| `Observers/TransportEligibilityObserver` (on assignment and employee save) | transport job `ReevaluateTransportEligibility` | backbone domain event; transport listens |
| `Observers/EmployeeAssignmentCommunicationObserver` | communication job `SyncEmployeeCommunicationMemberships` | backbone domain event; communication listens |
| `Actions/Employees/RegisterEmployeeAction`, `Services/IdCards/IdCardRenderDataFactory` | public feedback `EmployeeFeedbackTokenService` | feedback token provided through a contract the feedback module implements |
| `Actions/Employees/RegisterEmployeeAction`, `Services/Employees/EmployeeCsvImportService` | `Services/Vacancy/PositionCapacityService` | move capacity under the backbone (it is position data) |
| `Services/CodeGeneration/CodeGeneratorService`, `CodeRuleTargetRegistry` | grievance, discipline, feedback, transfer, provider models | service modules register their own code targets |
| `Services/Organizations/OrganizationDeletionGuard` | transfer and provider models | dependents register blocking checks with the guard |
| `Models/JobDescription`, `Models/PositionProfile` | `OrganizationDesignStatus` enum | shared status enum; low risk |

Stage 1 freezes this list in an architecture test so it can only shrink.

## 3. Duplicate or shadow master data

Queried from the fully migrated schema (MariaDB), not from source parsing.

| Table / columns | Class | Action |
| --- | --- | --- |
| `*_snapshot` columns: `employee_transfers`, `transfer_applications`, `assessment_records.employee_snapshot`, `employee_service_feedback.*_snapshot`, `public_feedback_assignments.handler_name_snapshot`, `grievance_initial_issues.responsible_officer_name_snapshot`, `field_work_requests.context_snapshot`, `transport_transactions.eligibility_snapshot`, `cafeteria_transactions.policy_snapshot`, `id_cards.display_snapshot` | A. historical snapshot | keep; they are evidence |
| `grievance_letter_recipients.organization_name, position_title` | A. snapshot (letter addressing) | keep |
| `position_requirements.position_title_am/en` | A/B. requirement record copy | keep; review in Stage 5 |
| `transport_drivers.full_name, phone_number`; provider and provider-user contact columns | D. provider-local metadata (drivers and provider staff are not employees) | keep |
| `users.national_id`, `users.national_id_hash` | C. duplicate of the employee identifier on the account | **Stage 7 candidate**: an account linked to an employee should read the employee's; needs a login/recovery review first |
| `institution_offices` | C. legacy organization master, already deprecated (routes redirect to organization units) | Stage 7: retire after confirming no rows are referenced |

No service module keeps its own employee, organization, position or assignment
master. Cafeteria and transport reference employees by key and keep only their
transactions and snapshots.

## 4. Dangerous schema assumptions found

1. `positions.organization_id` is nullable and has **no foreign key** (the unit
   link has one). A position can name an organization that does not exist.
   Adding the key needs an orphan audit on live data first (Stage 2).
2. One-current-assignment and one-live-card rules use partial unique indexes on
   PostgreSQL and generated columns on MySQL. Live MySQL rejected a STORED
   generated column once; new ones must be VIRTUAL (see the production branch
   notes).
3. Transfers copy the source `hierarchy_version_id` to the new assignment
   (audit F10, still open): historical reports may interpret a transferred
   assignment against the old structure version.
4. `Transport\Boarding\BoardingLock` takes a PostgreSQL advisory lock and does
   nothing on MySQL, which the live server runs; concurrent boardings for one
   employee rely on later checks only.
5. Card replacement lineage: `ReplaceCardAction` writes `card_replacements` with
   `new_card_id` set to the **old** card as a placeholder, and no code ever sets
   the real replacement. The previous → replacement link the credential rules
   require is therefore missing for every replacement.

## 5. API, event, authorization and audit gaps

| Area | State | Gap |
| --- | --- | --- |
| Backbone read API | organizations, units, positions, employees, employee assignment, eligibility, card verify | no `/assignments` collection, no `/cards/{card}` status read, no `/verifications` resource; existing equivalents are kept and documented |
| Principal types | human, application, provider user, public checker are separate | none new |
| Events | created/updated/status/moved/transferred/issued/revoked/reprinted | no explicit `position.occupied/vacated`, `id_card.activated/suspended/expired` (covered by `status_changed`) |
| Internal contracts | none: modules import backbone models directly | Stage 1 adds contracts and adapters |
| Dependency direction | not enforced | Stage 1 adds a ratchet test |
| Audit | `WriteAuditLogAction` with redaction, request IP and agent for most backbone writes | user/role events lack IP and agent (QA S10) |

## 6. Staged plan

| Stage | Content | Behavior change | Rollback |
| --- | --- | --- | --- |
| 1 | Contracts and DTOs for identity, assignment context, hierarchy, card status and eligibility, with adapters over the existing services; one backbone transfer command used by both transfer paths; card replacement lineage fix; architecture ratchet test; documentation | Only the lineage fix (adds a link that was missing) | revert the commits; no schema change |
| 2 | Backbone domain events (Laravel events) replace the two backbone observers that call transport and communication jobs; position capacity moves under the backbone namespace; orphan audit for `positions.organization_id` then an additive foreign key | none intended | revert; the key is added only after the audit finds no orphans |
| 3 | Migrate read paths module by module to the contracts, starting with cafeteria and transport (scan/eligibility) | none intended | per-module revert |
| 4 | API additions: `/assignments`, card status read, explicit verification resource, OpenAPI update | additive | remove routes |
| 5 | Code-rule targets and deletion-guard dependents registered by the owning modules | none intended | revert |
| 6 | Explicit occupancy and card-status events | additive event types | disable types in the catalog |
| 7 | Retire verified duplicate masters (`institution_offices`, `users.national_id`) | data model change | only after migration evidence |

Compatibility: every Stage 1 to 6 change keeps the existing models, tables,
routes and response shapes. Modules move to contracts one at a time; the old
direct reads keep working until each module is migrated.

## 7. Stage 1 scope (exact)

1. `App\Backbone\Contracts`: `EmployeeDirectory`, `AssignmentContextReader`,
   `OrganizationHierarchy`, `CardCredentialStatus`, `ServiceEligibility`;
   immutable DTOs in `App\Backbone\Data` that keep employee UUID, employee
   number, card UUID, card number and user id as separate typed fields.
2. Adapters in `App\Backbone\Adapters` that delegate to the existing services
   (`CredentialVerificationService`, `EmployeeServiceEligibilityService`,
   `OrganizationScopeService`, hierarchy services); bound in a service provider.
   No consumer is migrated in Stage 1.
3. `App\Backbone\Commands\TransferAssignment`: close current, open new, move the
   pointer, in the caller's transaction. `CompleteTransferAction` and
   `CompleteVacancyTransferAction` call it; their own occupancy, agreement and
   event steps are unchanged.
4. Card replacement lineage: the replacement card, when issued, is written to
   the pending `card_replacements.new_card_id`; a read-only artisan command
   reports historical rows still pointing at the old card.
5. Architecture test: backbone code may not import service-module classes, and
   service modules may not write backbone models, except a frozen allowlist of
   today's cases.
6. Tests: identity separation in DTOs, transfer keeps employee UUID and card,
   one current assignment after transfer, replacement lineage, adapter parity
   with the existing API.
7. Documentation: this file, master-backbone, data ownership, module integration
   matrix, integration API and the ADR.

Not in Stage 1: physical extraction, new tables, route changes, removal of any
legacy field, authentication changes, UUID or QR changes.

## 8. Stage 1 results

Delivered as listed in section 7. Measured lookup latency of the contracts on
the local 3-million-employee PostgreSQL database (`aaemployeedb_scale3m`,
3,000,004 employees, 3,750,000 cards; 300 random keys each; one developer
workstation, warm cache; not a production measurement):

| Lookup | p50 | p95 | p99 |
| --- | --- | --- | --- |
| Employee by UUID | 0.31 ms | 0.43 ms | 0.52 ms |
| Employee by number | 0.32 ms | 0.41 ms | 0.59 ms |
| Current assignment | 0.39 ms | 0.55 ms | 0.80 ms |
| Card credential by card UUID | 0.36 ms | 0.57 ms | 0.73 ms |
| Organization within ancestor | not measured: the scale database has no hierarchy versions or closure paths | | |

Partner eligibility and API round trips were not re-measured in Stage 1;
status `DESIGN_READY_NOT_MEASURED` for those paths.

## 9. Stages 2 to 7 results

| Stage | Delivered | Tests |
| --- | --- | --- |
| 2 | `AssignmentChanged` and `EmployeeChanged` backbone events replace the transport and communication observers; `PositionCapacityService` moved to `app/Services/Positions`; `positions.organization_id` foreign key added only when no orphan exists (`backbone:audit-integrity` reports orphans) | `BackboneStageTwoTest` |
| 3 | `CredentialLookup` and `ServiceEligibility::checkCard`; cafeteria, transport, service transactions, card verification and the partner API gate scans through them; transport manual lookup is scoped | `BackboneStageThreeTest`, transport security tests |
| 4 | `/api/v1/assignments`, `/employees/{employee}/assignments`, `/cards/{card_uuid}`, `/verifications`; audit rows carry the request correlation id; OpenAPI 1.3.0 | `BackboneStageFourApiTest` |
| 5 | Code-rule targets and collision checks, organization-deletion dependents, feedback links and provider codes are registered by the owning modules (`ServiceModuleServiceProvider`); `VerifyCardForServiceAction` moved to `Actions/ServiceTransactions`; grievance numbers are now checked for collisions (they never were) | `BackboneStageFiveTest` |
| 6 | Integration events `position.occupied`, `position.vacated`, `id_card.activated`, `id_card.suspended`, `id_card.expired`; any type can be switched off with `INTEGRATION_DISABLED_EVENT_TYPES` | `BackboneStageSixTest` |
| 7 | `backbone:duplicate-masters`: read-only evidence on whether `institution_offices` and `users.national_id` can be retired. Nothing is retired yet | `BackboneStageSevenTest` |

The ratchet (`tests/Unit/BackboneBoundaryTest.php`) now allows two backbone
imports of service-module classes, both the shared design-record status enum
on position profiles and job descriptions, down from eighteen. Its other two
rules allow only the development load-test seeder.

### Retiring a duplicate master (Stage 7, not yet done)

Retirement changes the data model, so it waits for evidence from live data and
a reviewed change of its own:

1. Run `php artisan backbone:duplicate-masters --json` on the live database and
   attach the output to the change record.
2. **institution_offices:** when no live office lacks its organization unit, a
   migration copies the table to `institution_offices_archive`, drops the
   foreign key from `organization_units.institution_office_id` and stops
   reading the table. The column and the archive stay until a later release
   confirms nothing reads them. Until then, run
   `institution-offices:migrate-to-organization-units` (dry run first).
3. **users.national_id:** when every account that holds an identifier is
   linked to an employee with the same identifier, sign-in and account
   recovery are reviewed for any use of the account copy; reads then move to
   the linked employee, and the column is emptied in a later release, never in
   the same one. Accounts that differ or are unlinked are resolved with HR
   first.
4. No step deletes business history, and no step runs on its own: each is a
   migration that goes through review like any other.
