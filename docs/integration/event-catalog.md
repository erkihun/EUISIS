# Event catalog, version 1

Audience: partner engineers consuming Addis One master-data events.

## Envelope

Every event, in the change feed and in webhook bodies, has the same envelope
(JSON Schema: [schemas/event-envelope.v1.json](schemas/event-envelope.v1.json)):

| Field | Meaning |
| --- | --- |
| `event_id` | Globally unique, stable UUID. The same on every redelivery and replay. De-duplicate on it. |
| `event_type` | Dotted name without version, e.g. `employee.updated`. |
| `event_version` | Integer schema version of `data` (currently `1`). |
| `aggregate_type` | `organization`, `organization_unit`, `position`, `employee`, `employee_placement`, `assignment`, `id_card`, `hierarchy_version`. |
| `aggregate_uuid` | The aggregate's stable UUID. For `id_card` it is the card record; the printed QR identity is `data.card_uuid`. |
| `organization_uuid` | Organization the event belongs to (destination, for transfers). `null` for global events. |
| `sequence` | 1, 2, 3 … per `(aggregate_type, aggregate_uuid)`. |
| `change_kind` | `upsert`, or `tombstone` when the record left the active state (archived, inactive, revoked, closed, separated …). |
| `occurred_at` | When Addis One recorded the change (UTC, microseconds). |
| `effective_at` | Business effective date where one exists (transfer effective date, assignment start/end, hierarchy version start). Not the same as `occurred_at`. |
| `correlation_id` | Shared by all events of one operation (e.g. a transfer closes one assignment, opens another and transfers the employee). |
| `data` | Minimised, type-specific fields below. |

Rules: v1 fields never change meaning or disappear. New optional fields may be added
— ignore unknown fields. A breaking change ships as a new `event_version`; reject or
park versions you do not support rather than guessing.

## Types

Scope = the API scope an application needs to see or subscribe to the type.

| Event type | Aggregate | Scope | Trigger | `data` |
| --- | --- | --- | --- | --- |
| `organization.created` | organization | `organizations.read` | Organization created | `organization_uuid, code, name_en, name_am, status` |
| `organization.updated` | organization | `organizations.read` | Code, names, type, effective dates or merge target changed | same + `changed_fields` |
| `organization.status_changed` | organization | `organizations.read` | Status changed, archived (soft delete) or restored | `organization_uuid, status, previous_status, state` |
| `organization_unit.created` | organization_unit | `organization_units.read` | Unit created | `unit_uuid, organization_uuid, parent_unit_uuid, code, name_en, name_am, status` |
| `organization_unit.updated` | organization_unit | `organization_units.read` | Code, names, type or dates changed | same + `changed_fields` |
| `organization_unit.moved` | organization_unit | `organization_units.read` | Parent unit or organization changed | `unit_uuid, organization_uuid, parent_unit_uuid, previous_parent_unit_uuid` |
| `organization_unit.status_changed` | organization_unit | `organization_units.read` | Status changed, archived, restored | `unit_uuid, organization_uuid, status, previous_status, state` |
| `position.created` | position | `positions.read` | Position created | `position_uuid, organization_uuid, unit_uuid, code, title_en, title_am, is_active` |
| `position.updated` | position | `positions.read` | Placement, code, titles, grade, occupation or dates changed | same + `changed_fields` |
| `position.status_changed` | position | `positions.read` | Activated / deactivated, restored | `position_uuid, organization_uuid, is_active, state` |
| `position.archived` | position | `positions.read` | Archived (tombstone) | `position_uuid, organization_uuid, state, reason_category` |
| `employee.created` | employee | `employees.basic_read` | Employee registered | `employee_uuid, employee_number, status` |
| `employee.updated` | employee | `employees.basic_read` | Employee number, name, gender, employment type or photo changed | `employee_uuid, employee_number, status, changed_fields` (categories: `employee_number`, `name`, `gender`, `employment_type`, `photo`) |
| `employee.status_changed` | employee | `employees.basic_read` | Status changed (tombstone when no longer active) | `employee_uuid, employee_number, status, previous_status, state` |
| `assignment.created` | assignment | `employee_assignments.read` | Assignment opened | `assignment_uuid, employee_uuid, organization_uuid, unit_uuid, position_uuid, assignment_status, effective_from, effective_to, is_current` |
| `assignment.updated` | assignment | `employee_assignments.read` | Placement, status or dates changed | same + `changed_fields` |
| `assignment.closed` | assignment | `employee_assignments.read` | No longer current / closed (tombstone) | `assignment_uuid, employee_uuid, organization_uuid, assignment_status, effective_to, state` |
| `employee.assignment_changed` | employee_placement | `employee_assignments.read` | Current assignment pointer moved (not by a transfer) | `employee_uuid, assignment_uuid, previous_assignment_uuid, organization_uuid, unit_uuid, position_uuid` |
| `employee.transferred` | employee_placement | `employee_assignments.read` | Transfer completed | `employee_uuid, transfer_uuid, transfer_kind, effective_date, previous_assignment_uuid, previous_organization_uuid, previous_unit_uuid, previous_position_uuid, assignment_uuid, organization_uuid, unit_uuid, position_uuid, visibility` |
| `id_card.issued` | id_card | `id_cards.verify` | Card issued / activated | `card_uuid, employee_uuid, status, is_current, issued_at, expires_at` |
| `id_card.reprinted` | id_card | `id_cards.verify` | Same card printed again (identity unchanged) | `card_uuid, employee_uuid, status, is_current, printed_at` |
| `id_card.revoked` | id_card | `id_cards.verify` | Card revoked, QR revoked or `revoked_at` set (tombstone) | `card_uuid, employee_uuid, status, is_current, revoked_at, state` |
| `id_card.status_changed` | id_card | `id_cards.verify` | Lost, damaged, suspended, replaced, expired … | `card_uuid, employee_uuid, status, previous_status, is_current, state` |
| `hierarchy_version.published` | hierarchy_version | `organization_structure.read` | A hierarchy version is published (global) | `hierarchy_version_uuid, version_name, effective_from, published_at` |

Never in any event: national ID, phone, e-mail, address, date of birth, salary,
grievance, discipline, performance, documents, photos, names of people (only the
fact that a name changed).

## Semantics consumers rely on

- **Query after event.** An event says *what* changed. Fetch current detail from the
  authorised API (`/api/v1/employees/{uuid}`, …) when you need it.
- **Employee stops being active** → `employee.status_changed` with `change_kind:
  tombstone`. Stop future service; the event carries no cafeteria or transport policy.
- **Card revocation** → `id_card.revoked`; drop any cached eligibility for that
  `card_uuid` immediately.
- **Transfer** → one `employee.transferred` (plus `assignment.closed` and
  `assignment.created` on the assignment aggregates). `occurred_at` is when it was
  completed; `effective_at` / `effective_date` when it takes effect.
- **Reprint** keeps `card_uuid`. A replacement card is a new card record with its own
  `card_uuid`; the old one gets `id_card.status_changed` → `replaced`.
- **Employee number change** → `employee.updated` with `changed_fields` containing
  `employee_number`; `employee_uuid` never changes.

## Transfers across an organization bound

A partner scoped to the **source** organization only receives `employee.transferred`
with `visibility: "source_only"` and the destination fields (`assignment_uuid`,
`organization_uuid`, `unit_uuid`, `position_uuid`) set to `null`, and
`organization_uuid` in the envelope set to the source. Whether a source partner may
learn the destination is **NEEDS_POLICY_DECISION_PARTNER_TRANSFER_EVENT_VISIBILITY**;
until decided, it does not. A partner scoped to the destination receives
`visibility: "full"`.

## Samples (synthetic)

`employee.updated`

```json
{
  "event_id": "019a3c10-7a2e-7d41-9b8e-2f6a1c0d5e01",
  "event_type": "employee.updated",
  "event_version": 1,
  "aggregate_type": "employee",
  "aggregate_uuid": "11111111-2222-4333-8444-555555555555",
  "organization_uuid": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee",
  "sequence": 4,
  "change_kind": "upsert",
  "occurred_at": "2026-10-19T08:15:02.120345Z",
  "effective_at": null,
  "correlation_id": "6f0c2b1e-0d3a-4c55-9a1b-7e2d9c4b8a10",
  "data": { "employee_uuid": "11111111-2222-4333-8444-555555555555", "employee_number": "EMP-0000123", "status": "active", "changed_fields": ["name"] }
}
```

`employee.assignment_changed`

```json
{
  "event_id": "019a3c10-8b11-7e02-a1c3-9d0e2f4a6b02", "event_type": "employee.assignment_changed", "event_version": 1,
  "aggregate_type": "employee_placement", "aggregate_uuid": "11111111-2222-4333-8444-555555555555",
  "organization_uuid": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee", "sequence": 2, "change_kind": "upsert",
  "occurred_at": "2026-10-19T08:20:00.000000Z", "effective_at": "2026-10-20", "correlation_id": "c1",
  "data": { "employee_uuid": "11111111-2222-4333-8444-555555555555", "assignment_uuid": "22222222-3333-4444-8555-666666666666",
            "previous_assignment_uuid": "33333333-4444-4555-8666-777777777777", "organization_uuid": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee",
            "unit_uuid": "44444444-5555-4666-8777-888888888888", "position_uuid": "55555555-6666-4777-8888-999999999999" }
}
```

`employee.transferred` (destination view)

```json
{
  "event_id": "019a3c11-0000-7000-8000-000000000003", "event_type": "employee.transferred", "event_version": 1,
  "aggregate_type": "employee_placement", "aggregate_uuid": "11111111-2222-4333-8444-555555555555",
  "organization_uuid": "dddddddd-0000-4000-8000-000000000001", "sequence": 3, "change_kind": "upsert",
  "occurred_at": "2026-10-19T09:00:00.000000Z", "effective_at": "2026-10-27", "correlation_id": "c2",
  "data": { "employee_uuid": "11111111-2222-4333-8444-555555555555", "transfer_uuid": "66666666-7777-4888-8999-000000000000",
            "transfer_kind": "announcement", "effective_date": "2026-10-27",
            "previous_assignment_uuid": "22222222-3333-4444-8555-666666666666", "previous_organization_uuid": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee",
            "previous_unit_uuid": "44444444-5555-4666-8777-888888888888", "previous_position_uuid": "55555555-6666-4777-8888-999999999999",
            "assignment_uuid": "77777777-8888-4999-8000-111111111111", "organization_uuid": "dddddddd-0000-4000-8000-000000000001",
            "unit_uuid": "88888888-9999-4000-8111-222222222222", "position_uuid": "99999999-0000-4111-8222-333333333333", "visibility": "full" }
}
```

`id_card.revoked`

```json
{
  "event_id": "019a3c12-0000-7000-8000-000000000004", "event_type": "id_card.revoked", "event_version": 1,
  "aggregate_type": "id_card", "aggregate_uuid": "c0c0c0c0-0000-4000-8000-000000000001",
  "organization_uuid": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee", "sequence": 5, "change_kind": "tombstone",
  "occurred_at": "2026-10-19T10:00:00.000000Z", "effective_at": null, "correlation_id": "c3",
  "data": { "card_uuid": "f1f1f1f1-0000-4000-8000-000000000009", "employee_uuid": "11111111-2222-4333-8444-555555555555",
            "status": "revoked", "is_current": false, "revoked_at": "2026-10-19T13:00:00+03:00", "state": "revoked" }
}
```

`organization.updated`

```json
{
  "event_id": "019a3c13-0000-7000-8000-000000000005", "event_type": "organization.updated", "event_version": 1,
  "aggregate_type": "organization", "aggregate_uuid": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee",
  "organization_uuid": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee", "sequence": 7, "change_kind": "upsert",
  "occurred_at": "2026-10-19T11:00:00.000000Z", "effective_at": null, "correlation_id": "c4",
  "data": { "organization_uuid": "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee", "code": "ORG-EXAMPLE", "name_en": "Example Bureau",
            "name_am": null, "status": "active", "changed_fields": ["name_en"] }
}
```

## Field privacy classification

| Field | Class | Why it is allowed |
| --- | --- | --- |
| `*_uuid` | Identifier (non-personal) | Stable keys; carry no personal data. |
| `employee_number` | Work reference | Already returned by `employees.basic_read`; display key, not a credential. |
| `code`, `name_en`, `name_am`, `title_*` (organizations, units, positions) | Public structure | Institutional, not personal. |
| `status`, `previous_status`, `state`, `is_active`, `is_current`, `assignment_status` | State | Needed to stop or start service. |
| `effective_*`, `issued_at`, `expires_at`, `revoked_at`, `printed_at`, `published_at` | Dates | Business timing; no personal dates (no birth date). |
| `changed_fields` | Category names | Names the kind of change, never the value. |
| `transfer_kind`, `reason_category`, `visibility` | Classification | Controlled vocabularies. |
| Not carried | Personal / sensitive | National ID, phone, e-mail, address, birth date, person names, photos, salary, grievance, discipline, performance, documents. |
