<?php

declare(strict_types=1);

return [
    'title' => 'Relationships',
    'reporting_lines' => 'Reporting Lines',
    'target_types' => [
        'organization' => 'Organization',
        'institution_office' => 'Institution Office',
        'organization_unit' => 'Organization Unit',
    ],
    'types' => [
        'reports_to' => 'Reports To',
        'geographically_under' => 'Geographically Under',
        'service_scope' => 'Service Scope',
        'oversight' => 'Oversight',
        'structural_parent' => 'Structural Parent',
        'functional_reporting' => 'Functional Reporting',
        'technical_supervision' => 'Technical Supervision',
        'administrative_reporting' => 'Administrative Reporting',
        'coordination' => 'Coordination',
        'service_delivery' => 'Service Delivery',
        'budget_reporting' => 'Budget Reporting',
        'temporary_assignment' => 'Temporary Assignment',
        'dotted_line_reporting' => 'Dotted-line Reporting',
        'other' => 'Other',
    ],
    'statuses' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
    ],
    'messages' => [
        'created' => 'Relationship created successfully',
        'updated' => 'Relationship updated successfully',
        'deleted' => 'Relationship deleted successfully',
        'restored' => 'Relationship restored successfully',
    ],
    'validation' => [
        'target_not_found' => 'The selected relationship target does not exist.',
        'invalid_effective_dates' => 'The effective end date must be after or equal to the start date.',
        'only_one_primary_structural_parent' => 'Only one active primary structural parent is allowed.',
        'structural_cycle' => 'Structural relationship cannot create a cycle.',
        'invalid_structural_target' => 'This target type cannot be used as a structural parent.',
        'invalid_unit_relationship_target' => 'Organization unit relationships can only target an organization or another organization unit.',
        'duplicate_active_relationship' => 'An active relationship already exists for this source, target, and relationship type.',
    ],
];
