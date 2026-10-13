<?php

// Display labels for enums that label themselves (App\Enums\*::label()).
// Keys are the stored case values, which never change; only the wording does.
return [
    'cafeteria_ledger_entry_type' => [
        'allocation' => 'Allocation',
        'usage' => 'Usage',
        'extra_usage' => 'Extra Usage',
        'carry_forward_deduction' => 'Carry-forward Deduction',
        'adjustment' => 'Adjustment',
        'reversal' => 'Reversal',
    ],
    'cafeteria_report_type' => [
        'daily' => 'Daily Report',
        'weekly' => 'Weekly Report',
        'monthly' => 'Monthly Report',
    ],
    'cafeteria_subsidy_scope' => [
        'all_employees' => 'All Employees',
        'organization' => 'Organization',
        'employee_type' => 'Employee Type',
        'selected_employees' => 'Selected Employees',
    ],
    'cafeteria_transaction_status' => [
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'reversed' => 'Reversed',
        'pending_review' => 'Pending Review',
    ],
    'establishment_status' => [
        'draft' => 'Draft',
        'approved' => 'Approved',
        'archived' => 'Archived',
    ],
    'occupancy_status' => [
        'active' => 'Active',
        'released' => 'Released',
    ],
    'transfer_announcement_status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ],
    'transfer_application_status' => [
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'verified' => 'Verified',
        'selected' => 'Selected',
        'release_pending' => 'Release Pending',
        'receiving_pending' => 'Receiving Pending',
        'final_approval_pending' => 'Final Approval Pending',
        'approved' => 'Approved',
        'implementation_failed' => 'Implementation Failed',
        'transferred' => 'Transferred',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn',
        'cancelled' => 'Cancelled',
    ],
    'transfer_approval_status' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],
    'transfer_approval_type' => [
        'release' => 'Release Approval',
        'receiving' => 'Receiving Approval',
        'final' => 'Final Approval',
    ],
    'transfer_card_reprint_policy' => [
        'no_reprint' => 'No Reprint',
        'request_reprint' => 'Create Reprint Request',
        'auto_reprint' => 'Auto Reprint',
    ],
    'transfer_document_verification_status' => [
        'pending' => 'Pending',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
    ],
    'transfer_override_status' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],
    'transfer_service_recalculation_policy' => [
        'no_recalculation' => 'No Recalculation',
        'recalculate_from_transfer' => 'Recalculate from Transfer Date',
        'recalculate_from_effective_date' => 'Recalculate from Effective Date',
    ],
    'vacancy_announcement_status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ],
    'vacancy_application_status' => [
        'submitted' => 'Submitted',
        'withdrawn' => 'Withdrawn',
        'screened' => 'Screened',
        'shortlisted' => 'Shortlisted',
        'selected' => 'Selected',
        'rejected' => 'Rejected',
        'transferred' => 'Transferred',
    ],
];
