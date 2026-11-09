<?php

// Core oversight messages (ADR 0011): data quality, reports and exports, notifications, system health.
return [
    'data_quality_updated' => 'The issue was updated.',
    'data_quality_scan_started' => 'The data-quality scan is running; issues update when it finishes.',
    'export_queued' => 'The export is being prepared. It appears in your exports when it is ready.',
    'export_not_ready' => 'This export is not ready yet.',
    'notifications_read' => 'Notifications marked as read.',
    'job_retried' => 'The job was queued again.',
    'job_forgotten' => 'The failed job was removed.',

    // Export titles (the report names).
    'reports' => [
        'organizations' => 'Organization report', 'employees' => 'Employee report', 'positions' => 'Position report', 'vacancies' => 'Vacancy report',
        'assignments' => 'Assignment report', 'assignment_history' => 'Assignment history report', 'hierarchy' => 'Hierarchy report',
        'hierarchy_changes' => 'Hierarchy change report', 'card_status' => 'ID card status report', 'card_coverage' => 'Card coverage report',
        'expiring_cards' => 'Expiring cards report', 'lost_damaged_replaced' => 'Lost, damaged and replaced cards report',
        'credential_status' => 'Card credential status report', 'data_quality' => 'Data quality report', 'user_access' => 'User access report',
        'organization_scope' => 'Organization scope report', 'audit' => 'Audit report', 'api_client_usage' => 'API client usage report',
        'webhook_deliveries' => 'Webhook delivery report', 'security_events' => 'Security event report',
    ],

    // Values in exports, in the export's language.
    'values' => [
        'boolean' => ['yes' => 'Yes', 'no' => 'No'],
        'organization_status' => ['active' => 'Active', 'inactive' => 'Inactive', 'merged' => 'Merged', 'dissolved' => 'Dissolved', 'archived' => 'Archived', 'draft' => 'Draft'],
        'employee_status' => ['draft' => 'Draft', 'submitted' => 'Submitted', 'verified' => 'Verified', 'approved' => 'Approved', 'active' => 'Active', 'returned' => 'Returned',
            'retired' => 'Retired', 'terminated' => 'Terminated', 'deceased' => 'Deceased', 'transferred' => 'Transferred out'],
        'position_status' => ['draft' => 'Draft', 'approved' => 'Approved', 'suspended' => 'Suspended', 'abolished' => 'Abolished', 'archived' => 'Archived'],
        'assignment_status' => ['active' => 'Active', 'pending' => 'Pending approval', 'closed' => 'Ended'],
        'assignment_type' => ['initial' => 'First placement', 'transfer' => 'Transfer', 'promotion' => 'Promotion', 'demotion' => 'Demotion', 'reassignment' => 'Reassignment',
            'correction' => 'Correction', 'unspecified' => 'Not recorded'],
        'hierarchy_status' => ['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'published' => 'Published', 'archived' => 'Archived', 'rejected' => 'Rejected'],
        'card_status' => ['pending_print' => 'Waiting to print', 'printed' => 'Printed', 'issued' => 'Issued', 'active' => 'Active', 'suspended' => 'Suspended', 'lost' => 'Lost',
            'damaged' => 'Damaged', 'expired' => 'Expired', 'revoked' => 'Revoked', 'replaced' => 'Replaced'],
        'qc_status' => ['pending' => 'Waiting for check', 'passed' => 'Passed', 'failed' => 'Failed', 'not_recorded' => 'Not recorded'],
        'credential_status' => ['active' => 'Active', 'superseded' => 'Replaced', 'revoked' => 'Revoked'],
        'severity' => ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'info' => 'Information'],
        'issue_status' => ['open' => 'Open', 'investigating' => 'Investigating', 'resolved' => 'Resolved', 'accepted_risk' => 'Risk accepted', 'false_positive' => 'False positive'],
        'check' => [
            'positions_missing_organization' => 'Position names a missing organization', 'positions_foreign_unit' => 'Position unit of another organization',
            'employees_bad_pointer' => 'Employee points at a wrong placement', 'assignments_unpointed' => 'Placement the employee does not point at',
            'replacements_unlinked' => 'Replacement not linked', 'separated_still_placed' => 'Separated but still placed', 'separated_card_active' => 'Separated with an active card',
            'employees_multiple_active_cards' => 'More than one active card', 'positions_over_capacity' => 'Position over its seats',
            'placed_in_inactive_position' => 'Placed in a position not approved', 'placed_in_ended_organization' => 'Placed in an inactive organization',
            'active_without_placement' => 'Active without a placement', 'overlapping_assignments' => 'Overlapping placements',
            'possible_duplicate_employees' => 'Possible duplicate', 'expired_card_still_active' => 'Expired card still active', 'active_without_card' => 'Active without a card',
            'active_card_inactive_employee' => 'Active card, inactive holder', 'active_card_revoked_credential' => 'Active card, revoked credential',
            'active_without_photo' => 'Missing required photo', 'active_missing_required_document' => 'Missing required document',
            'occupancy_without_assignment' => 'Occupancy without placement', 'hierarchy_multiple_parents' => 'More than one parent',
            'hierarchy_edge_ended_organization' => 'Inactive organization in the hierarchy',
        ],
    ],
];
