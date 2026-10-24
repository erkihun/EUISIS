<?php

declare(strict_types=1);

/*
 * The Core platform (docs/architecture/core-authority.md).
 */
return [
    /*
     * Who may write Golden Path master data through this application:
     *
     *  - 'open'   (default) the application is the authoritative writer;
     *  - 'frozen' master-data changes are refused (organizations, units,
     *    positions, employees, placements, ID cards and their reference data),
     *    for a cutover or restore window. Reads, sign-in and service modules
     *    keep working; service transactions are not master data.
     *
     * The switch is the technical control the cutover runbook relies on, so
     * that two writers never change the same master record.
     */
    'master_data_writes' => env('CORE_MASTER_DATA_WRITES', 'open'),

    /** Route-name prefixes whose non-read requests change master data. */
    'master_data_routes' => [
        'organizations.', 'organization-types.', 'organization-units.', 'organization-unit-types.',
        'organization-edges.', 'organization-relationships.', 'reporting-lines.', 'hierarchy-versions.',
        'organizational-change-requests.', 'positions.', 'position-establishments.', 'grade-levels.',
        'occupations.', 'employees.', 'employee-corrections.', 'id-cards.', 'card-requests.',
        'print-batches.', 'id-card-templates.', 'code-rules.',
    ],
];
