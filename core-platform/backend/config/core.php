<?php

declare(strict_types=1);

/*
 * The standalone Core platform: Organization -> Unit -> Position -> Employee ->
 * Assignment -> ID card, with its own database. See ../README.md.
 */
return [
    /*
     * The Golden Path tables, in the order they are copied and fingerprinted, with
     * the columns that identify a row and its state. The list and the columns are
     * the same as EUISIS `core:snapshot`, so a snapshot file taken there can be
     * compared here (and the other way round).
     */
    'tables' => [
        'organization_types' => ['id', 'code'],
        'organizations' => ['id', 'code', 'status', 'organization_type_id'],
        'hierarchy_versions' => ['id', 'status'],
        'organization_edges' => ['id', 'hierarchy_version_id', 'parent_organization_id', 'child_organization_id', 'relationship_type'],
        'organization_unit_types' => ['id', 'code'],
        'organization_units' => ['id', 'organization_id', 'parent_unit_id', 'code', 'status'],
        'positions' => ['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'is_active'],
        'position_establishments' => ['id', 'position_id', 'approved_slots', 'status'],
        'position_occupancies' => ['id', 'position_id', 'employee_assignment_id', 'status'],
        'employees' => ['id', 'employee_number', 'status', 'current_assignment_id'],
        'employee_assignments' => ['id', 'employee_id', 'organization_id', 'position_id', 'assignment_status', 'is_current', 'effective_from', 'effective_to'],
        'id_cards' => ['id', 'employee_id', 'card_number', 'public_card_uuid', 'status', 'is_current', 'previous_card_id'],
        'card_requests' => ['id', 'employee_id', 'status'],
        'card_replacements' => ['id', 'old_card_id', 'new_card_id'],
    ],

    /* Columns computed by the database; never copied. */
    'generated_columns' => [
        'employee_assignments' => ['current_employee_id'],
        'id_cards' => ['live_current_employee_id'],
    ],

    /* Origins of the Core frontend allowed to call the API from a browser. */
    'frontend_origins' => array_filter(explode(',', (string) env('CORE_FRONTEND_ORIGINS', 'http://localhost:5174'))),
];
