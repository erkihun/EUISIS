<?php

declare(strict_types=1);

/*
 * The Core platform (docs/architecture/core-authority.md).
 */
return [
    /*
     * Who may write Golden Path master data through this application
     * (App\Enums\MasterDataWriteMode):
     *
     *  - 'core'     (default) this application is the authoritative writer;
     *  - 'legacy'   before cutover, this deployment is still the writer;
     *  - 'cutover'  master-data changes are refused (organizations, units,
     *    positions, employees, placements, ID cards and their reference data)
     *    for the switch or a restore window;
     *  - 'rollback' refused too, while the previous writer is reconciled.
     *
     * 'open' and 'frozen' still mean core and cutover. Reads, sign-in and
     * service modules keep working; service transactions are not master data.
     * An unknown value is read-only (fail closed). The switch is the technical
     * control the cutover runbook relies on, so that two writers never change
     * the same master record.
     */
    'master_data_writes' => env('CORE_MASTER_DATA_WRITES', 'core'),

    /** Route-name prefixes whose non-read requests change master data. */
    'master_data_routes' => [
        'organizations.', 'organization-types.', 'organization-units.', 'organization-unit-types.',
        'organization-edges.', 'organization-relationships.', 'reporting-lines.', 'hierarchy-versions.',
        'organizational-change-requests.', 'positions.', 'position-establishments.', 'grade-levels.',
        'occupations.', 'employees.', 'employee-corrections.', 'id-cards.', 'card-requests.',
        'print-batches.', 'id-card-templates.', 'code-rules.',
    ],
];
