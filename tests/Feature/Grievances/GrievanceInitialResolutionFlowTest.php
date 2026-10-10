<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceInitialResolutionOutcome;
use App\Enums\Grievance\GrievanceInitialResolutionStatus;
use App\Services\Grievances\GrievanceCaseService;
use Tests\Support\GrievanceScenario;

it('resolves employee identity server-side and ignores forged ids', function () {
    $scenario = GrievanceScenario::build(withRoutes: false, withSla: false);
    $actor = $scenario->complainant;
    $service = app(GrievanceCaseService::class);

    $issue = $service->raiseInitialIssue($actor, [
        'employee_id' => 'forged-uuid-should-be-ignored',
        'organization_id' => 'forged-org',
        'issue_subject' => 'Shift allocation',
        'issue_description' => 'Night shifts assigned without rotation.',
    ]);

    expect($issue->employee_id)->not->toBe('forged-uuid-should-be-ignored');
    expect($issue->employee_id)->toBe($actor->employee_id);
    expect($issue->organization_id)->toBe($actor->employee->currentAssignment->organization_id);
    expect($issue->status)->toBe(GrievanceInitialResolutionStatus::Pending);
    expect($issue->written_issue_raised_at)->not->toBeNull();
});

it('records an unresolved outcome as a failed, filing-eligible resolution', function () {
    $scenario = GrievanceScenario::build(withRoutes: false, withSla: false);
    $actor = $scenario->complainant;
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($actor, [
        'issue_subject' => 'X',
        'issue_description' => 'Y',
    ]);

    $resolved = $service->recordInitialResolution($issue, $actor, GrievanceInitialResolutionOutcome::Unresolved, [
        'discussion_notes' => 'Met 2026-02-01, no agreement.',
    ]);

    expect($resolved->status)->toBe(GrievanceInitialResolutionStatus::Failed);
    expect($resolved->outcome->startsFilingClock())->toBeTrue();
    expect($resolved->resolved_at)->not->toBeNull();
    expect($resolved->discussion_notes)->toBe('Met 2026-02-01, no agreement.');
});

it('records a resolved outcome as a terminal, non-filing resolution', function () {
    $scenario = GrievanceScenario::build(withRoutes: false, withSla: false);
    $actor = $scenario->complainant;
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($actor, [
        'issue_subject' => 'X',
        'issue_description' => 'Y',
    ]);

    $resolved = $service->recordInitialResolution($issue, $actor, GrievanceInitialResolutionOutcome::Resolved, []);

    expect($resolved->status)->toBe(GrievanceInitialResolutionStatus::Resolved);
    expect($resolved->outcome->startsFilingClock())->toBeFalse();
});
