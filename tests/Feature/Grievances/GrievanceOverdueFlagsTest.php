<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceInitialResolutionOutcome as Outcome;
use App\Services\Grievances\GrievanceCaseService;
use Illuminate\Support\Carbon;
use Tests\Support\GrievanceScenario;

afterEach(fn () => Carbon::setTestNow());

it('derives committee-report overdue without changing the workflow status', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00:00'));
    $scenario = GrievanceScenario::build();
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($scenario->complainant, ['issue_subject' => 'A', 'issue_description' => 'B']);
    $service->recordInitialResolution($issue->refresh(), $scenario->complainant, Outcome::Unresolved, []);
    $grievance = $service->submitFormalGrievance($issue->refresh(), $scenario->complainant, [
        'cause_of_grievance' => 'x', 'requested_remedy' => 'y', 'incident_date' => '2026-02-20',
        'category_id' => $scenario->category->id,
    ]);
    $grievance = $service->intakeAccept($grievance->refresh(), $scenario->intake, 'Complete.');

    $statusBefore = $grievance->status;

    // Jump well past the 15-working-day committee-report deadline.
    Carbon::setTestNow(Carbon::parse('2026-05-01 09:00:00'));

    $flags = $grievance->fresh()->overdueFlags();

    expect($flags['committee_report'])->toBeTrue();
    expect($flags['head_decision'])->toBeFalse();
    expect($flags['decision_delivery'])->toBeFalse();
    // Operational flag only — the legal workflow status is untouched.
    expect($grievance->fresh()->status)->toBe($statusBefore);
});

it('reports no overdue flags before any deadline passes', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00:00'));
    $scenario = GrievanceScenario::build();
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($scenario->complainant, ['issue_subject' => 'A', 'issue_description' => 'B']);
    $service->recordInitialResolution($issue->refresh(), $scenario->complainant, Outcome::Unresolved, []);
    $grievance = $service->submitFormalGrievance($issue->refresh(), $scenario->complainant, [
        'cause_of_grievance' => 'x', 'requested_remedy' => 'y', 'incident_date' => '2026-02-20',
        'category_id' => $scenario->category->id,
    ]);
    $grievance = $service->intakeAccept($grievance->refresh(), $scenario->intake, 'Complete.');

    expect($grievance->overdueFlags()['committee_report'])->toBeFalse();
});
