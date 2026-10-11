# Organization Design — legacy data mapping report

Amharic: የተቋም አወቃቀር — የነባር መረጃ ካርታ ሪፖርት

How the records that existed **before** the Organization Design &
Workforce Planning domain relate to it. Nothing in this report is changed by
the system: links are made by authorized people in the mapping workspace
(`organization-design/mapping`), one record at a time, and a legacy record
without evidence stays `UNMAPPED_LEGACY` and remains fully operational
(brief §6–8, D-11).

## How records are classified

`App\Services\OrganizationDesign\LegacyMappingService::classify($organizationId)`
(read-only) and `php artisan organization-design:legacy-report [--organization=] [--json]`.

| Record | FULLY_MAPPED | PARTIALLY_MAPPED | UNMAPPED_LEGACY | INVALID_REFERENCE |
|---|---|---|---|---|
| Organization unit (active) | holds at least one responsibility **and** every active position in it is linked to work | one of the two | neither | parent unit missing, or no unit type |
| Position (active) | occupation **and** work links **and** published profile **and** job description in force | some of the four | none | its unit is not a live unit of the organization, or a work link points at an object that no longer exists |
| Position service (EPMS, active) | mapped to a master service **and** every active task mapped to a master task | master service only, or only some tasks mapped | nothing mapped | master service of another organization or cancelled, or a task mapped to a foreign/missing master task |

`INVALID_REFERENCE` is `NEEDS_DATA_REVIEW`: a person must decide; the
system never repairs it.

## Development database, 2026-10-11

Run: `php artisan organization-design:legacy-report --json` against the
local development database (synthetic data only; no production data was
read).

| Record | Total | FULLY_MAPPED | PARTIALLY_MAPPED | UNMAPPED_LEGACY | INVALID_REFERENCE |
|---|---|---|---|---|---|
| Organizations in scope | 21 | — | — | — | — |
| Units | 8 | 0 | 0 | 2 | 6 |
| Positions | 3 | 0 | 0 | 3 | 0 |
| Position services | 0 | 0 | 0 | 0 | 0 |

The six `INVALID_REFERENCE` units are the single units of the six
`GRV-*` grievance demo organizations: they carry no organization unit
type (`organization_unit_type_id` is null). This is the same finding the
structure validation reports as `unit_without_type`. Required manual
review: assign a unit type or archive the unit — a decision for the data
owner, not for the migration.

The three `UNMAPPED_LEGACY` positions (`DEMO-HR-001` and two grievance demo
positions) pre-date the design and have no evidence to map from; they stay
operational and are listed in the mapping workspace for the designer.

No position services exist on the development database, so no
position-task links were evaluated here. On a production copy the same
command classifies every active position service and task.

## What the migration preserved

- No organization, unit, position, position service, task, employee
  assignment, code or hierarchy version was modified, deleted or re-keyed.
- All schema changes of the domain are additive (new tables; nullable
  columns `position_service_tasks.master_task_id`,
  `position_services.master_service_id`, `employee_assignments.job_description_id`;
  indexes). Down migrations drop only what they added.
- Legacy position tasks keep their own work standards; Daily Work keeps
  measuring against them. The master link is traceability only.

## What is NEEDS_DATA

| Dataset | Status |
|---|---|
| ISCO-08 groups | not loaded — the importer accepts the official CSV (`organization-design/classifications`, dry run first) |
| ISIC Rev. 4 | not loaded — same importer |
| CPC / local service classification | not loaded — same importer; services may be published without one |
| Competency dictionary | starting catalog only (types and levels); competencies are entered per framework |
| Approved work standards | entered per task/activity; none pre-loaded |
| Approved manpower methodologies | none seeded (D-6/D-7); parameters are policy |

## Running it on a production copy

```bash
php artisan organization-design:legacy-report            # every active organization
php artisan organization-design:legacy-report --organization=<uuid> --json
```

The command reads only; schedule the review of `INVALID_REFERENCE` rows
with the data owners before the domain is opened to designers.
