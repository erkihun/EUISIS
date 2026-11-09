# Code Rules Architecture

Last updated: 2026-05-14

## Purpose

`code_rules` provides reusable, audited, database-backed numbering rules for business entities that need stable generated codes.

## Current Supported Entity Types

- `organization`
- `organization_type`
- `employee`
- `position`
- `employee_position`
- `id_card`
- `card_request`
- `service_provider`
- `service_type`
- `entitlement_rule`
- `transport_route`
- `transport_plan`
- `coupon_program`
- `meal_plan`
- `approval_workflow`
- `api_client`
- `device_binding`
- `support_ticket`

Not every entity type is fully integrated into a create flow yet, but the rule system is designed to support them consistently.

## Tables

### `code_rules`

Stores the active or archived rule definition:

- `entity_type`
- `scope_type`
- `scope_id`
- `name_en`, `name_am`
- `prefix`, `suffix`, `format`, `separator`
- `sequence_length`, `next_number`
- `reset_frequency`, `last_reset_at`, `year_format`
- `is_active`
- `allow_manual_override`
- `require_approval_for_override`
- `description_en`, `description_am`
- `metadata`
- `created_by`, `updated_by`

### `code_generation_logs`

Read-only operational history for generated codes:

- `code_rule_id`
- `entity_type`
- `entity_id`
- `generated_code`
- `sequence_number`
- `generated_by`
- `generated_at`
- `metadata`

## Format Tokens

Currently supported:

- `{PREFIX}`
- `{SUFFIX}`
- `{YEAR}`
- `{MONTH}`
- `{DAY}`
- `{SEQUENCE}`
- `{ORG_CODE}`
- `{ORG_TYPE_CODE}`
- `{SERVICE_TYPE_CODE}`
- `{CUSTOM}`

`{SEQUENCE}` is required.

## Resolution Rules

`CodeRuleResolver` currently resolves in this order:

1. exact entity + scope type + scope id
2. entity + scope type with no scope id
3. global entity rule

## Concurrency Safety

`CodeGeneratorService` generates codes inside a database transaction and uses `lockForUpdate()` on the chosen `code_rules` row. This prevents duplicate sequence consumption under concurrent requests.

Generation flow:

1. resolve active rule
2. lock the rule row
3. reset sequence when reset frequency boundary is crossed
4. format a candidate code
5. verify uniqueness against the target business column when that entity is integrated
6. increment `next_number`
7. write `code_generation_logs`

## Manual Override Behavior

If a manual code is supplied:

- it is allowed only when the resolved rule has `allow_manual_override = true`
- if `require_approval_for_override = true`, the actor must also have `code-rules.manageOverrides`

Otherwise a validation error is returned to the form.

## Preview vs Final Generation

**Preview endpoint** (`POST /code-rules/preview-code`):

- Returns an estimated code for the given entity type based on the *current* `next_number`.
- Does NOT increment `next_number` or write a `code_generation_logs` entry.
- Does NOT expose `next_number` unless the caller has `code-rules.view` permission.
- Returns `200 OK` even when no rule is configured (with `code: null` and a localized error message).
- Authorization: requires `code-rules.preview` OR any of `organizations.manage`, `employees.manage`, `positions.create`, `service-types.create`, `organization-types.create`.

Request body: `{ entity_type, scope_type?, scope_id?, context?: { organization_type_id?, organization_id?, service_type_id? } }`

Response: `{ code, rule: { id, name, entity_type, is_scoped, scope_type }, manual_override_allowed, requires_override_permission, error }`

**Final generation** (store time):

- `GenerateCodeAction::execute()` is called inside each entity's create action.
- Uses `lockForUpdate()` inside a `DB::transaction()` to prevent concurrent duplicates.
- Writes a `CodeGenerationLog` entry for every generated code.

## CodeRuleField Frontend Component

Location: `resources/js/Components/code-rules/CodeRuleField.tsx`

Props: `entityType`, `context`, `scopeType`, `scopeId`, `value`, `onChange`, `fieldName`, `label`, `canManualOverride`, `required`, `disabled`, `existingCode`, `preserveExistingCodeOnEdit`, `error`.

**On create forms**: fetches preview on mount, re-fetches on context change. In auto mode sends empty string — backend generates the real code. In manual mode (if `canManualOverride=true`) sends the typed value.

**On edit forms**: when `preserveExistingCodeOnEdit=true` renders the existing code read-only; no API call is made.

## Current Integration Points

Automatically generates codes when the field is omitted for:

- organizations (via `CreateOrganizationAction`)
- organization types (via `CreateOrganizationTypeAction`)
- employees (via `RegisterEmployeeAction`)
- positions (via `CreatePositionAction`)
- ID cards during card-request approval (via `ApproveCardRequestAction` → `GenerateCardNumberAction`)
- service types (via `CreateServiceTypeAction`) — added 2026-05-14

Frontend `CodeRuleField` integrated in:

- `Organizations/Create.tsx` — auto-generate with org-type context refresh
- `Organizations/Edit.tsx` — read-only existing code
- `OrganizationTypes/Create.tsx` — auto-generate
- `OrganizationTypes/Edit.tsx` — read-only existing code
- `Positions/Create.tsx` — auto-generate with organization context
- `Positions/Edit.tsx` — read-only existing code
- `Employees/Create.tsx` — auto-generate with organization context
- `ServiceTypes/Form.tsx` — auto-generate on create, read-only on edit

## Permissions

- `code-rules.viewAny`
- `code-rules.view`
- `code-rules.create`
- `code-rules.update`
- `code-rules.archive`
- `code-rules.restore`
- `code-rules.preview`
- `code-rules.generate`
- `code-rules.export`
- `code-rules.manageOverrides`

## Known Limitations

- The current UI preloads scope options for organizations, organization types, and service types rather than using an async search endpoint.
- Some entity types are supported in the rule registry but not yet integrated into a create workflow.
- The test suite currently verifies transactional generation behavior through sequential feature coverage, not a true parallel stress harness.
