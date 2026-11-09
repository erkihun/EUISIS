<?php

declare(strict_types=1);

return [
    'organization_units' => 'Organization Units',
    'organization_unit' => 'Organization Unit',
    'created_successfully' => 'Organization unit created successfully.',
    'updated_successfully' => 'Organization unit updated successfully.',
    'archived_successfully' => 'Organization unit archived.',
    'restored_successfully' => 'Organization unit restored.',
    'select_organization' => 'Select an organization to view its units.',
    'no_units_found' => 'No organization units found.',
    'parent_must_belong_to_same_org' => 'The parent unit must belong to the selected organization.',
    'circular_hierarchy_not_allowed' => 'Circular hierarchy is not allowed.',
    'cannot_be_own_parent' => 'A unit cannot be its own parent.',
    'cannot_archive_assigned' => ':count employee(s) are currently assigned to this unit. Reassign them before archiving.',
    'cannot_archive_with_children' => 'This unit has :count sub-unit(s). Move or archive them first.',
    'cannot_archive_with_positions' => 'This unit has :count position(s). Move or close them first.',
    'unit_type_in_use' => ':count unit(s) use this unit type. Change their type before archiving it.',
    'inactive_unit_type' => 'This unit type is inactive and cannot be used.',
    'organization_cannot_change' => 'A unit stays in the organization it was created in. Create the unit in the other organization instead.',
    'duplicate_sibling_name' => 'Another unit under the same parent already has this name (:code).',
    'root_unit' => 'Root Unit',
    'add_organization_unit' => 'Add Organization Unit',
    'back_to_organizations' => 'Back to Organizations',
    'help_text' => 'Organization Units are offices, departments, directorates, teams, and other internal structures under an organization.',
    'do_not_create_offices_as_units' => 'Institution offices are created as organization units under a structural organization.',
    'parent_unit_must_same_office' => 'Parent unit must belong to the same organization.',
    'institution_office' => 'Institution Office',
    'select_institution_office' => 'Select Institution Office',
    'structure_copied' => 'Organization unit structure copied successfully.',
];
