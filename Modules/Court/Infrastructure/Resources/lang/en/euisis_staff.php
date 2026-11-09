<?php

// Staff accounts and their EUISIS employees (docs/euisis-integration.md, "Staff accounts").
return [
    'title' => 'EUISIS staff',
    'subtitle' => "The court units' employees in EUISIS and their staff accounts. Court roles are given here; names, positions and units come from EUISIS.",
    'roster_link' => 'EUISIS staff',

    'units' => [
        'title' => 'Court units',
        'help' => 'The EUISIS units whose employees are the court\'s staff, chosen from the units mirrored from EUISIS.',
        'none' => 'No court unit is chosen yet.',
        'organization' => 'Institution',
        'choose_organization' => 'Choose an institution',
        'unit' => 'Unit',
        'choose_unit' => 'Choose a unit',
        'include_sub_units' => 'With its sub-units',
        'only_this_unit' => 'This unit only',
        'add' => 'Add unit',
        'remove' => 'Remove',
        'save' => 'Save units',
        'read_only' => 'Only those who manage the system settings can change the court units.',
        'no_units_mirrored' => 'No units of this institution are mirrored yet; run php artisan euisis:sync.',
    ],

    'roster' => [
        'title' => 'Employees of the court units',
        'employee_number' => 'Employee number',
        'name' => 'Name',
        'position' => 'Position',
        'unit' => 'Unit',
        'status' => 'EUISIS status',
        'account' => 'Staff account',
        'not_linked' => 'No account',
        'suspended' => 'Suspended',
        'create_account' => 'Create account',
        'open_account' => 'Open account',
        'empty' => 'EUISIS lists no employees in the court units.',
        'choose_units_first' => 'Choose the court units to see their employees.',
        'disabled' => 'The EUISIS connection is off (EUISIS_ENABLED, EUISIS_URL and EUISIS_TOKEN).',
        'skipped_units' => 'These units have no code in EUISIS and cannot be listed: :units',
        'summary' => ':linked of :total employees have a staff account.',
    ],

    'notices' => [
        'title' => 'Recent account changes',
        'none' => 'No staff account was suspended or restored yet.',
        'suspended' => 'Suspended',
        'restored' => 'Restored',
    ],

    'panel' => [
        'title' => 'EUISIS employee',
        'not_linked' => 'This account is not linked to an EUISIS employee.',
        'disabled' => 'The EUISIS connection is off.',
        'employee_number' => 'Employee number',
        'name' => 'Name in EUISIS',
        'position' => 'Position',
        'unit' => 'Unit',
        'organization' => 'Institution',
        'status' => 'EUISIS status',
        'linked_at' => 'Linked',
        'synced_at' => 'Last read from EUISIS',
        'outside_court' => 'EUISIS no longer places this employee in the court units.',
        'suspended' => 'Suspended by the EUISIS staff check: :reason',
        'link' => 'Link',
        'link_help' => 'The employee\'s EUISIS number. Only an active employee of the court units can be linked.',
        'unlink' => 'Unlink',
        'unlink_confirm' => 'Unlink this account from its EUISIS employee? A suspension stays until the account is set active.',
        'read_only' => 'Name, position and unit come from EUISIS and are changed there.',
    ],

    'create' => [
        'from_euisis' => 'From EUISIS: :name, employee number :number. Add the email and the court roles.',
        'employee_number' => 'EUISIS employee number',
    ],

    'statuses' => [
        'active' => 'Active',
        'draft' => 'Draft',
        'suspended' => 'Suspended',
        'transferred' => 'Transferred',
        'retired' => 'Retired',
        'terminated' => 'Terminated',
        'deceased' => 'Deceased',
    ],

    'reasons' => [
        'left_unit' => 'The employee is no longer placed in the court units in EUISIS.',
        'not_active' => 'The employee is no longer active in EUISIS.',
        'not_in_euisis' => 'EUISIS no longer has this employee.',
    ],

    'notice' => [
        'suspended' => 'Staff account suspended: :name',
        'restored' => 'Staff account restored: :name',
        'restored_detail' => 'The employee is back in the court units in EUISIS.',
    ],

    'errors' => [
        'no_court_unit' => 'Choose the court units first (Users > EUISIS staff).',
        'not_found' => 'EUISIS has no employee with this number.',
        'ambiguous' => 'More than one EUISIS employee has this number.',
        'not_active' => 'This employee is not active in EUISIS.',
        'outside_court' => 'This employee is not placed in the court units in EUISIS.',
        'linked_to_another_account' => 'This employee is already linked to another staff account.',
        'euisis_unavailable' => 'EUISIS could not be reached. Try again later.',
    ],

    'flash' => [
        'units_saved' => 'The court units are saved. Linked accounts are checked at the next EUISIS sync.',
        'linked' => 'The account is linked to its EUISIS employee.',
        'unlinked' => 'The account is no longer linked to EUISIS.',
    ],

    'sign_in_suspended' => 'This staff account is suspended: the employee is no longer in the court units in EUISIS. Ask an administrator.',
];
