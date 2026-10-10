<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceInitialResolutionOutcome as Outcome;
use App\Enums\GrievanceStatus;
use App\Exceptions\Grievances\InitialResolutionRequiredException;
use App\Services\Grievances\GrievanceCaseService;
use Tests\Support\GrievanceScenario;

it('blocks formal submission when the initial issue was resolved informally', function () {
    $scenario = GrievanceScenario::build();
    $actor = $scenario->complainant;
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($actor, ['issue_subject' => 'A', 'issue_description' => 'B']);
    $service->recordInitialResolution($issue->refresh(), $actor, Outcome::Resolved, []);

    $service->submitFormalGrievance($issue->refresh(), $actor, [
        'cause_of_grievance' => 'x',
        'requested_remedy' => 'y',
        'incident_date' => now()->toDateString(),
        'category_id' => $scenario->category->id,
    ]);
})->throws(InitialResolutionRequiredException::class);

it('allows formal submission after an unresolved outcome and snapshots context', function () {
    $scenario = GrievanceScenario::build();
    $actor = $scenario->complainant;
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($actor, ['issue_subject' => 'A', 'issue_description' => 'B']);
    $service->recordInitialResolution($issue->refresh(), $actor, Outcome::Unresolved, []);

    $grievance = $service->submitFormalGrievance($issue->refresh(), $actor, [
        'cause_of_grievance' => 'Grade not applied',
        'requested_remedy' => 'Apply grade',
        'incident_date' => now()->subDays(3)->toDateString(),
        'category_id' => $scenario->category->id,
    ]);

    expect($grievance->status)->toBe(GrievanceStatus::Submitted);
    expect($grievance->initial_issue_id)->toBe($issue->id);
    expect($grievance->reference_number)->not->toStartWith('DRAFT-');
    expect($grievance->employee_assignment_id)->toBe($issue->employee_assignment_id);
    expect($grievance->position_id)->toBe($issue->position_id);
});
