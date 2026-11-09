<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('adds the filing-window columns additively', function () {
    foreach ([
        'initial_issue_id', 'position_id', 'immediate_supervisor_employee_id',
        'filing_clock_started_at', 'filing_deadline_at', 'is_late_submission',
        'late_submission_reason', 'cause_started_at', 'cause_ended_at',
        'late_review_status', 'late_reviewed_by', 'late_reviewed_at', 'late_decision_reason',
    ] as $column) {
        expect(Schema::hasColumn('grievances', $column))->toBeTrue("missing {$column}");
    }
});
