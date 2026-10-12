# Organization Domain Model

## Final Domain Model

The Addis One system uses a three-tier domain model for workforce organization:

```
Organization  (legal/geographic entity)
  └── OrganizationUnit  (internal office/department/team)
        └── Position  (job slot)
              └── EmployeeAssignment  (employee occupying a position)
```

## Organizations vs Organization Units

### Organization

An `Organization` is a **legal, geographic, or government entity** recognized in its own right:

- Addis Ababa City Administration
- Public Service and Human Resource Development Bureau
- Bole Sub-City Administration
- Bole Woreda 01 Administration

Organizations are versioned structurally through `hierarchy_versions` and `organization_edges`. The published hierarchy version is the source of truth for the approved organization tree.

### Organization Unit (canonical — replaces Institution Offices)

An `OrganizationUnit` is any **internal structural element** under an organization: an office, directorate, department, team, section, or other subdivision.

Examples:
- Bole Sub-City Public Service and HRD Office (under Bole Sub-City Administration)
- Human Resource Directorate (under a bureau)
- Finance Team (under an office)

**Institution Offices are deprecated. All offices and internal structures must be created as Organization Units.**

## Organization vs Organization Unit Distinction

| Criterion | Organization | Organization Unit |
|---|---|---|
| Legal standing | Yes — registered entity | No — internal subdivision |
| Has its own employees | Yes (via positions) | Yes (via positions in the org) |
| Structural versioning | Yes (hierarchy versions) | No (flat tree via parent_unit_id) |
| Functional relationships | Via org hierarchy | Via OrganizationUnitRelationship |
| Example | Bole Sub-City Administration | Bole Sub-City Public Service and HRD Office |

## Structural vs Functional Relationships

An Organization Unit can have two kinds of relationships:

### Structural (via `organization_id` + `parent_unit_id`)

The **structural parent** determines where the unit sits in the org chart.

- `organization_id` — which Organization the unit belongs to (required)
- `parent_unit_id` — which OrganizationUnit is the structural parent within the same org (optional, nullable)

### Functional (via `organization_unit_relationships`)

A **functional relationship** reflects reporting or supervisory links that cross organizational boundaries, without transferring management authority.

Example: Bole Sub-City Public Service and HRD Office (structurally under Bole Sub-City Administration) **functionally reports to** Public Service and HRD Bureau.

Relationship types: `functional_reporting`, `technical_supervision`, `administrative_reporting`, `coordination`, `oversight`, `service_delivery`, `budget_reporting`, `dotted_line_reporting`, `other`.

## Model Diagram

```
Organization
├── id (UUID)
├── name_en / name_am
├── code
├── organization_type_id → OrganizationType
└── status

OrganizationUnit
├── id (UUID)
├── organization_id → Organization          (structural: belongs to)
├── parent_unit_id → OrganizationUnit       (nullable, structural parent within same org)
├── organization_unit_type_id → OrganizationUnitType
├── institution_office_id → InstitutionOffice  (nullable legacy link)
├── code
├── name_en / name_am
├── unit_type (string: office, internal_office, department, ...)
└── status

OrganizationUnitRelationship
├── id (UUID)
├── source_unit_id → OrganizationUnit
├── target_type (organization | organization_unit)
├── target_id (UUID)
├── relationship_type (functional_reporting | technical_supervision | ...)
├── is_primary (bool)
├── effective_from / effective_to (dates, nullable)
└── status (active | inactive | expired | cancelled)

Position
├── id (UUID)
├── organization_id → Organization
├── organization_unit_id → OrganizationUnit  (nullable)
└── ...

EmployeeAssignment
├── id (UUID)
├── employee_id → Employee
├── position_id → Position
└── ...
```

## Institution Offices (Deprecated)

The `InstitutionOffice` model and `institution_offices` table are **deprecated** as of June 2026. They were an earlier attempt at the same concept as Organization Units.

- New offices **must** be created as `OrganizationUnit` records.
- The `InstitutionOffices/Create` page still exists but now creates `OrganizationUnit` records.
- GET routes under `/institution-offices/` redirect to `/organization-units/`.

See [institution-office-deprecation.md](./institution-office-deprecation.md) for full migration details and command usage.
