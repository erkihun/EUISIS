# Organization Relationships

Addis One keeps one primary structural hierarchy and separate functional/reporting links.

## Model

- `institution_offices.parent_office_id` remains the main office tree parent when the parent is another office.
- `institution_offices.structural_organization_id` records the organization that structurally owns or administers an office.
- `institution_office_relationships` and `organization_unit_relationships` store secondary reporting, supervision, coordination, oversight, service delivery, budget, temporary, dotted-line, and other relationships.
- One active primary `structural_parent` relationship is allowed per source office or unit.
- Many active non-primary functional/reporting relationships are allowed.

## Example

`Bole Sub-city Public Service and HRD Office`:

- Structural organization: `Bole Sub-city Administration`
- Geographic organization: `Bole Sub-city`
- Functional reporting target: `Public Service and Human Resource Development Bureau`
- Technical supervision target: `Public Service and Human Resource Development Bureau`

The office is not duplicated as an organization.

## Tree Behavior

The primary tree stays single-parent. Reporting lines are shown separately as reporting lists/graphs. Functional reporting does not change the organization tree, institution office tree, assignment tree, capacity calculations, or default management scope.

## Permissions

Relationship management uses `relationships.*` permissions. Functional reporting uses `functional-reporting.*` permissions. Functional reporting grants report/supervision access only when the user has an explicit permission; it does not automatically grant full employee management.
