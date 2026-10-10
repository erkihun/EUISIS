<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceInitialResolutionOutcome as Outcome;
use App\Models\Grievance;
use App\Models\StatutoryDeadline;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Legal\StatutoryDeadlineService;
use App\Support\Legal\Regulation201;
use Illuminate\Support\Carbon;
use Tests\Support\GrievanceScenario;

afterEach(fn () => Carbon::setTestNow());

function acceptedGrievance(GrievanceScenario $scenario): Grievance
{
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($scenario->complainant, ['issue_subject' => 'A', 'issue_description' => 'B']);
    $service->recordInitialResolution($issue->refresh(), $scenario->complainant, Outcome::Unresolved, []);

    $grievance = $service->submitFormalGrievance($issue->refresh(), $scenario->complainant, [
        'cause_of_grievance' => 'x',
        'requested_remedy' => 'y',
        'incident_date' => now()->subDays(2)->toDateString(),
        'category_id' => $scenario->category->id,
    ]);

    // Intake review is enabled by default, so acceptance is an explicit step.
    return $service->intakeAccept($grievance->refresh(), $scenario->intake, 'Complete.');
}

function openDeadline(Grievance $grievance, string $ruleKey): ?StatutoryDeadline
{
    return StatutoryDeadline::query()
        ->where('subject_type', $grievance->getMorphClass())
        ->where('subject_id', $grievance->getKey())
        ->where('rule_key', $ruleKey)
        ->whereNull('superseded_at')
        ->first();
}

it('snapshots a 15-working-day committee-report deadline on acceptance', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00:00'));
    $scenario = GrievanceScenario::build();
    $grievance = acceptedGrievance($scenario);

    $deadline = openDeadline($grievance, Regulation201::GRIEVANCE_COMMITTEE_REPORT);

    expect($grievance->accepted_at)->not->toBeNull();
    expect($deadline)->not->toBeNull();
    expect($deadline->amount)->toBe(15);
    expect($deadline->basis)->toBe(Regulation201::WORKING_DAYS);

    $expected = app(StatutoryDeadlineService::class)
        ->dueAt(Regulation201::GRIEVANCE_COMMITTEE_REPORT, $deadline->started_at);
    expect($deadline->due_at->toDateTimeString())->toBe($expected->toDateTimeString());
});
