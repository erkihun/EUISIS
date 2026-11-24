<?php

declare(strict_types=1);

/* Column headings and values of the exported compliance register. */
return [
    'too_many_rows' => 'The export would hold more than :max rows. Narrow the filters and try again.',
    'columns' => [
        'institution_code' => 'Institution code',
        'institution' => 'Institution',
        'work_unit' => 'Work unit',
        'responsible_officer' => 'Responsible officer',
        'report_type' => 'Report type',
        'period' => 'Period',
        'original_deadline' => 'Original deadline',
        'effective_deadline' => 'Effective deadline',
        'first_submission' => 'First submission',
        'workflow' => 'Workflow status',
        'timeliness' => 'Timeliness (effective deadline)',
        'timeliness_original' => 'Timeliness (original deadline)',
        'days_overdue' => 'Days overdue',
        'applicability' => 'Applicability',
        'reviewer' => 'Reviewer',
        'review_deadline' => 'Review deadline',
    ],
    'values' => [
        'workflow' => [
            'not_started' => 'Not started', 'draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under review',
            'returned' => 'Returned for revision', 'approved' => 'Approved', 'rejected' => 'Rejected',
        ],
        'timeliness' => [
            'not_due' => 'Not yet due', 'early' => 'Submitted early', 'on_time' => 'On time', 'late' => 'Late', 'overdue' => 'Overdue, not submitted',
        ],
        'applicability' => ['required' => 'Required', 'exempted' => 'Exempted', 'cancelled' => 'Cancelled'],
    ],
];
