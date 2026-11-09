# Institution Offices Module — Deprecation Notice

## What Was Deprecated and Why

The **Institution Offices** module (`institution_offices` table, `InstitutionOffice` model, related routes and UI) has been deprecated. All internal office/directorate/department/team structures are now managed exclusively as **Organization Units**.

### Business Reason

The domain model was duplicated. Organization Units already represent any internal structure under an organization, including offices. The Institution Offices module introduced a parallel, redundant concept with overlapping data.

The correct canonical model is:

| Concept | Model | Example |
|---|---|---|
| Legal / geographic entity | `Organization` | Bole Sub-City Administration |
| Internal structure | `OrganizationUnit` | Bole Sub-City Public Service and HRD Office |
| Job slot | `Position` | HR Officer |
| Employee slot | `EmployeeAssignment` | Employee → Position |

A **Bole Sub-City Public Service and HRD Office** is an `OrganizationUnit` that:
- Structurally belongs to `Bole Sub-City Administration` (`organization_id`)
- Optionally has a parent unit (`parent_unit_id`)
- Functionally reports to `Public Service and HRD Bureau` (via `organization_unit_relationships`)

## What Changed

### Backend
- `CreateInstitutionOfficeAction` — rewritten to create `OrganizationUnit` records instead of `InstitutionOffice` records
- `StoreInstitutionOfficeRequest` — rewritten to validate `OrganizationUnit` fields
- `InstitutionOfficeController` — all GET routes now redirect; POST `store` remains active

### Frontend
- `InstitutionOffices/Create.tsx` — rewritten; form POSTs to `institution-offices.store` but creates an `OrganizationUnit`
- `AppSidebar.tsx` — Institution Offices nav item removed

## Legacy Route Behavior

| Old URL | HTTP Method | Behavior |
|---|---|---|
| `/institution-offices` | GET | 301 redirect → `/organization-units` |
| `/institution-offices/create` | GET | Still renders create form (creates OrganizationUnit on submit) |
| `/institution-offices` | POST | Active — creates OrganizationUnit, redirects to org-unit show |
| `/institution-offices/{id}` | GET | Looks up OrganizationUnit by `institution_office_id`; redirects to it, or to index if not found |
| `/institution-offices/{id}/edit` | GET | Same lookup; redirects to org-unit edit |
| `/institution-offices/{id}` | PATCH/DELETE | Redirects to org-unit index |
| `/institution-offices/{id}/restore` | POST | Redirects to org-unit index |
| `/institution-offices/{id}/move` | POST | Redirects to org-unit index |
| `/institution-offices/{id}/relationships` | GET/POST | Redirects to org-unit index |
| `/institutions/{org}/offices` | GET | Redirects to org-unit index |
| `/institutions/{org}/offices/tree` | GET | Redirects to org-unit tree |
| `/institutions/{org}/offices/parent-options` | GET | Redirects to org-unit options |

## Migration Command

To migrate existing `institution_offices` records to `organization_units`:

```bash
# Preview without writing
php artisan institution-offices:migrate-to-organization-units --dry-run

# Run the migration
php artisan institution-offices:migrate-to-organization-units

# Specify which user account to record as the migration actor
php artisan institution-offices:migrate-to-organization-units --actor-id=<uuid>
```

The command is **idempotent** — safe to run multiple times. Records already mapped via `institution_office_id` are skipped.

Output summary: `Migrated: X | Already mapped: Y | Skipped: Z`

## Permission Mapping

| Old Permission | Replacement |
|---|---|
| `institution-offices.viewAny` | `organization-units.viewAny` |
| `institution-offices.view` | `organization-units.view` |
| `institution-offices.create` | `organization-units.create` |
| `institution-offices.update` | `organization-units.update` |
| `institution-offices.delete` | `organization-units.archive` |
| `institution-offices.restore` | `organization-units.restore` |

Old `institution-offices.*` permissions remain in the database and are not removed, to avoid breaking existing role assignments during transition.

## Database Tables

The `institution_offices` and `institution_office_relationships` tables are **not dropped**. They are preserved for data safety. The `organization_units.institution_office_id` column links migrated records back to their legacy source.
