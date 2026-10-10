<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceInitialResolutionOutcome;
use App\Enums\Grievance\GrievanceInitialResolutionStatus;
use App\Models\GrievanceInitialIssue;
use Tests\Support\GrievanceScenario;

it('persists an initial issue with server-resolved identity and casts enums', function () {
    $scenario = GrievanceScenario::build(withRoutes: false, withSla: false);
    $employee = $scenario->complainant->employee;
    $assignment = $employee->currentAssignment;

    $issue = GrievanceInitialIssue::create([
        'employee_id' => $employee->id,
        'employee_assignment_id' => $assignment->id,
        'organization_id' => $assignment->organization_id,
        'organization_unit_id' => $assignment->organization_unit_id,
        'issue_subject' => 'Grading dispute',
        'issue_description' => 'My grade was not applied per directive.',
        'written_issue_raised_at' => now(),
        'status' => GrievanceInitialResolutionStatus::Pending,
    ]);

    expect($issue->refresh()->status)->toBe(GrievanceInitialResolutionStatus::Pending);
    expect($issue->employee->is($employee))->toBeTrue();

    $issue->update([
        'outcome' => GrievanceInitialResolutionOutcome::Unresolved,
        'status' => GrievanceInitialResolutionStatus::Failed,
        'resolved_at' => now(),
    ]);

    expect($issue->refresh()->outcome)->toBe(GrievanceInitialResolutionOutcome::Unresolved);
    expect($issue->status)->toBe(GrievanceInitialResolutionStatus::Failed);
});
