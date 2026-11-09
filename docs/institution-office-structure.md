# Institution Office Structure

Institution Offices are a business-facing alias for office-type `OrganizationUnit` records.

Source of truth:

- `organizations`: legal, geographic, or government entities.
- `organization_units`: internal offices, directorates, departments, teams, and similar structures under an organization.
- `organization_units.organization_id`: the primary structural organization for the unit.
- `organization_units.parent_unit_id`: optional parent unit inside the same organization.
- `organization_unit_relationships`: secondary reporting relationships, such as functional reporting to another organization.

The `/institution-offices/create` route creates an `OrganizationUnit` and does not create a separate `InstitutionOffice` record. If a functional reporting organization is selected, the route creates a secondary `organization_unit_relationships` row from the new unit to that organization.

The existing `institution_offices` table is retained for legacy compatibility and old records. New office creation should prefer office-type organization units to avoid duplicate structural records.

Cafeteria, transfer, vacancy, employee assignment, and position capacity flows already use `organization_unit_id` where unit-level placement is needed. `institution_office_id` should only be used for legacy records that have not yet been mapped to organization units.
