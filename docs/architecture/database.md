# Database Architecture

## Principles

- Use UUIDv7 primary keys for domain tables.
- Preserve historical truth with effective dates instead of overwriting structure or assignment history.
- Keep searchable data in typed columns, not buried in JSON.
- Prevent dangerous duplicates with database constraints where practical and service-layer guards where partial indexes are environment-specific.
- Treat employee identity, card lifecycle, and audit records as non-destructive records.

## Organization Hierarchy Model

### Core Tables

- `organization_types`: controlled list of types such as city government, bureau, agency, sub-city, woreda, service provider.
- `organizations`: stable organization nodes with status, type, codes, merge tracking, and effective dates.
- `hierarchy_versions`: version containers for draft, published, or archived relationship sets.
- `organization_edges`: parent-child relationships under a hierarchy version with relationship type and effective dates.
- `organization_name_histories`: historical organization names by effective period.
- `organization_closure_paths`: ancestor-descendant cache for fast subtree authorization and reporting.

### Why Node + Edge + Version

- Supports restructuring without data loss.
- Allows multiple relationship types such as executive, geographic, oversight, and service scope.
- Preserves old reporting context after reorganization.
- Avoids hard-coded `bureau_id`, `sub_city_id`, `woreda_id`, `branch_id`, and `pool_id` columns.

### Hierarchy Version Workflow Rules

- Hierarchy versions are created as `draft` by default.
- Only draft versions may be updated, archived, or sent to tree editing.
- Publishing validates that the draft contains edges and that the edge graph is not circular.
- Publishing promotes the selected draft to `published` and archives previously published versions instead of overwriting them.
- Historical versions remain queryable through the module even after they are archived.
- Draft tree editing is handled through add/edit/remove relation workflows on `hierarchy-versions/{version}/tree/edit`.
- Relation mutations are gated by both hierarchy-version draft state and `organization-edges.*` permissions.
- Organization search for relation modals is scope-aware and comes from `hierarchy-versions/{version}/organization-options`.
- Removing a relation only removes the `organization_edges` row; it does not delete the organization record.

### Organization Create-Child Rules

- Parent-organization options are not derived from root nodes anymore.
- Parent selection is scope-aware and uses `OrganizationScopeService` plus policy checks.
- Only active organizations can be chosen as parents for child creation.
- Child structure changes must target a draft hierarchy version.
- The create page preselects the parent passed from `?parent=` but still allows switching to another eligible parent within scope.
- Draft hierarchy changes do not appear in the published tree until that hierarchy version is published.
- The View Tree page exposes relation actions only when the selected version is draft and the current user is authorized.

## Employee Identity vs Assignment

### Stable Identity

- `employees` stores the durable identity record.
- `employee_number` and UUID remain stable across transfers.
- Person-level fields live here, while organization placement does not.

### Assignment History

- `employee_assignments` stores position, organization, hierarchy version context, status, and effective dates.
- Only one active assignment is allowed at a time for each employee.
- Transfers close the previous assignment and create a new one.
- Reports by date can resolve the assignment active on that date.

### Supporting Tables

- `positions`
- `employment_status_histories`
- `employee_documents`
- `employee_duplicate_flags`
- `employee_transfers`

### Implemented Employee Flow Notes

- `RegisterEmployeeAction` creates the stable employee record, initial assignment, status history row, duplicate check, and audit log in one transaction.
- Employee update is separate from assignment history and writes an immutable `employee_updated` audit record.
- `employee_transfers` is the durable transfer workflow table with draft, submitted, confirmed, approved, rejected, cancelled, and completed states.
- Transfer completion closes the current assignment, creates the new assignment, preserves UUID and employee number, and triggers entitlement recalculation.

## Position Model

- `positions` now stores a required unique `job_position_code` plus English and Amharic titles, organization link, grade level, job family, active flag, effective dates, and metadata.
- Positions used by assignments are archived through `is_active` and effective dating rather than destructive deletion.
- New assignments and transfers reject inactive positions through service-layer validation.

## ID Card Lifecycle Model

### Flow

1. `card_requests` capture request submission and verification checklist state.
2. `id_cards` represent actual card identities and lifecycle status.
3. `card_print_batches` and `card_print_batch_items` group printable approved cards.
4. `card_issuances` records pickup/activation events.
5. `card_replacements` links lost/damaged cards to replacement cards.
6. `card_verifications` stores verification attempts and minimal result context.

### Security Design

- QR payload never contains personal information in plain text.
- Raw verification token is returned only at generation time and its hash is stored on the server.
- Card display snapshot fields can preserve what was printed at issue time.
- Only approved and printed cards may be issued.
- Current web workflow supports submit, verify, approve, print, issue, incident reporting, and replacement.

## Entitlement and Service Model

- `service_types` defines transport, cafeteria, consumer association, and future services.
- `service_providers` represents internal or external providers.
- `service_provider_users` maps users to provider contexts.
- `entitlement_rules` stores rule definitions and policy metadata.
- `entitlements` stores employee-level grants or overrides with effective periods.
- `service_transactions` records service usage with authorization result and settlement markers.
- `provider_settlements` and `settlement_runs` provide settlement foundations.

## Workflow Model

- `approval_workflows`
- `approval_steps`
- `approvals`
- `task_assignments`

This supports configurable approval paths for hierarchy publishing, card approvals, transfers, and future high-risk actions.

## Audit Model

- `audit_logs` is append-only at application level.
- Each event records actor, event type, auditable entity, organization context, request metadata, and old/new values.
- Export actions, failed verification attempts, permission/scope changes, position changes, and transfer workflow actions are audited.

## Indexing Strategy

- Standard indexes on `organization_id`, `employee_id`, `service_provider_id`, `service_type_id`, `status`, `effective_from`, `effective_to`, and `created_at`.
- Unique constraints for keys such as `employee_number`, organization codes where applicable, and card numbers.
- Partial uniqueness in PostgreSQL for current active assignment and single active card scenarios.
- Closure-path indexes on ancestor and descendant columns for subtree resolution.

## Soft Deletes

- Avoid soft deletes for core government records.
- Use status changes and effective dating for organizations, employees, cards, and transactions.
- Limit soft deletes to non-critical configuration if introduced later.
