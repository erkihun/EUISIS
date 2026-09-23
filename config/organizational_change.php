<?php

declare(strict_types=1);

return [
    /*
     * Maps an implementing-unit key to the organization unit responsible for
     * applying approved changes. Keys are generic on purpose; no department
     * name is hard-coded. Structure:
     *
     *   'implementing_units' => [
     *       'default' => ['structure_management' => '<organization_unit_uuid>'],
     *       '<organization_uuid>' => ['establishment_management' => '<unit_uuid>'],
     *   ]
     *
     * A unit may also opt in by carrying {"implementing_unit_key": "..."} in
     * its metadata, which keeps the mapping with the data.
     */
    'implementing_units' => [
        'default' => [],
    ],

    /* Supporting document rules. Files are stored on the private disk only. */
    'attachments' => [
        'disk' => 'local',
        'directory' => 'organizational-change-requests',
        'max_kilobytes' => 10240,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'],
    ],
];
