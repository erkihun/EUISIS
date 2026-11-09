# Organization Relationship Map

**Date:** 2026-06-02

---

## Core Distinctions

| Concept | What it is | Example |
|---|---|---|
| **Organization** | A legal/geographic entity registered in the system | Addis Ababa City Administration, Finance Bureau, Kirkos Sub-city, Woreda 3 |
| **Organization Type** | Classifies an Organization | City Administration, Bureau, Sub-city, Woreda |
| **Institution Office** | A physical/operational branch of an institution at a geographic level | Finance Bureau – Kirkos Sub-city Office, Finance Bureau – City Level Head Office |
| **Organization Unit** | An internal department, directorate, or team inside an Institution Office | Human Resources Directorate, Finance Team, IT Support Unit |
| **Organization Unit Type** | Classifies an Organization Unit | Directorate, Department, Team, Division, Unit |
| **Hierarchy Version** | A versioned snapshot of inter-organization reporting lines | FY2026 Structure, Post-Reform 2025 |

---

## ER Diagram (Text Format)

```
+------------------+       +-------------------+
| organization_    |       | organizations     |
| types            |       |                   |
|------------------|       |-------------------|
| id (PK)          |<------| organization_     |
| code             |       |   type_id (FK)    |
| name_en          |       | code              |
| name_am          |       | name_en           |
| is_active        |       | name_am           |
| prefix           |       | status            |
+------------------+       | merged_into_id    |----+
                           | effective_from    |    | self-ref
                           | effective_to      |    |
                           +-------------------+<---+
                                  |           |
                         (HasMany)|           |(HasMany geographic)
                                  |           |
                    +----------------------------+
                    | institution_offices         |
                    |-----------------------------|
                    | id (PK)                      |
                    | institution_id (FK) ---------> organizations
                    | geographic_organization_id---> organizations (nullable)
                    | parent_office_id ------------> institution_offices (nullable)
                    | office_level                 |
                    | office_code (unique)         |
                    | name_en                      |
                    | is_head_office               |
                    | status                       |
                    +----------------------------+
                                  |
                         (HasMany)|
                                  |
                    +----------------------------+
                    | organization_units          |
                    |-----------------------------|
                    | id (PK)                      |
                    | organization_id (FK) --------> organizations (legacy)
                    | institution_office_id (FK) --> institution_offices (nullable)
                    | parent_unit_id (FK) ---------> organization_units (nullable)
                    | organization_unit_type_id ---> organization_unit_types (nullable)
                    | unit_type (string, legacy)   |
                    | code                         |
                    | name_en                      |
                    | status                       |
                    +----------------------------+
                                  ^
                                  | (self-ref HasMany children)
                                  |
                    +----------------------------+
                    | organization_unit_types     |
                    |-----------------------------|
                    | id (PK)                      |
                    | code (unique)                |
                    | name_en                      |
                    | name_am                      |
                    | prefix                       |
                    | is_active                    |
                    +----------------------------+

+-------------------+         +-------------------+
| hierarchy_        |         | organization_     |
| versions          |         | edges             |
|-------------------|         |-------------------|
| id (PK)           |<--------| hierarchy_        |
| version_name      |         |   version_id (FK) |
| status            |         | parent_org_id (FK)-----> organizations
| approved_by (FK)  |         | child_org_id  (FK)-----> organizations
| effective_from    |         | relationship_type |
| effective_to      |         | effective_from    |
+-------------------+         +-------------------+

+-------------------+         +-------------------+
| employees         |         | employee_         |
|                   |         | assignments       |
|-------------------|         |-------------------|
| id (PK)           |<--------| employee_id (FK)  |
| employee_number   |         | organization_id ---> organizations
| current_assignment|         | position_id ------> positions
|   _id (FK)        |         | organization_unit -> organization_units (nullable)
| status            |         | hierarchy_version-> hierarchy_versions (nullable)
+-------------------+         | is_current        |
                               +-------------------+
```

---

## Relationship Descriptions

### Organization → Institution Office
One organization (as institution) may have **many** Institution Offices at different geographic levels.  
An Institution Office optionally references a second Organization as its geographic area (e.g., Kirkos Sub-city).  
These are separate FK columns: `institution_id` (the bureau/institution) vs `geographic_organization_id` (the sub-city/woreda).

### Institution Office → Organization Unit
An Institution Office may contain **many** Organization Units (internal teams/departments).  
Units can be nested (tree via `parent_unit_id`).  
Each unit still carries a legacy `organization_id` (direct org link) for backward compatibility with the pre-InstitutionOffice data model.

### Organization → Hierarchy Version (via edges)
Organizations do **not** have a `parent_id` column.  
The inter-organization reporting hierarchy is stored as versioned edges in `organization_edges` linked to a `hierarchy_version`.  
This allows multiple versions of the org chart to coexist (draft, published, archived).

### Organization Unit → Type (dual path)
Units carry both a free-text `unit_type` string (legacy enum value) and a FK `organization_unit_type_id` to the `organization_unit_types` lookup table.  
The `StoreOrganizationUnitRequest` syncs `unit_type` from the selected type's code automatically.

---

## Clear Distinction: Three Levels of Organizational Structure

```
LEVEL 1: Organization (legal/geographic entity)
   e.g. "Finance Bureau" (type: Bureau)
   e.g. "Kirkos Sub-city" (type: Sub-city)
   Hierarchy between orgs managed via HierarchyVersion + OrganizationEdge

LEVEL 2: Institution Office (operational branch)
   e.g. "Finance Bureau – Kirkos Branch" (institution: Finance Bureau, geographic: Kirkos)
   e.g. "Finance Bureau – City Head Office" (institution: Finance Bureau, is_head_office: true)
   Tree via parent_office_id within same institution

LEVEL 3: Organization Unit (internal team/department)
   e.g. "HR Directorate" (inside Finance Bureau – Kirkos Branch)
   e.g. "Procurement Team" (child of HR Directorate)
   Tree via parent_unit_id within same institution office
```
