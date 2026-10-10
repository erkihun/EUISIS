<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceInitialResolutionOutcome as Outcome;
use App\Models\StatutoryDeadline;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Legal\StatutoryDeadlineService;
use App\Support\Legal\Regulation201;
use Illuminate\Support\Carbon;
use Tests\Support\GrievanceScenario;

afterEach(fn () => Carbon::setTestNow());

function fileGrievanceFromUnresolved(GrievanceScenario $scenario): \App\Models\Grievance
{
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($scenario->complainant, ['issue_subject' => 'A', 'issue_description' => 'B']);
    $service->recordInitialResolution($issue->refresh(), $scenario->complainant, Outcome::Unresolved, []);

    return $service->submitFormalGrievance($issue->refresh(), $scenario->complainant, [
        'cause_of_grievance' => 'x',
        'requested_remedy' => 'y',
        'incident_date' => now()->subDays(2)->toDateString(),
        'category_id' => $scenario->category->id,
    ]);
}

it('snapshots the 10-working-day filing deadline from the resolution date', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00:00')); // Monday
    $scenario = GrievanceScenario::build();

    $grievance = fileGrievanceFromUnresolved($scenario);

    $expected = app(StatutoryDeadlineService::class)
        ->dueAt(Regulation201::GRIEVANCE_COMMITTEE_FILING, $grievance->filing_clock_started_at);

    expect($grievance->filing_clock_started_at)->not->toBeNull();
    expect($grievance->filing_deadline_at->toDateTimeString())->toBe($expected->toDateTimeString());
    expect($grievance->filing_deadline_at->toDateString())->toBe('2026-03-16');
    expect($grievance->is_late_submission)->toBeFalse();

    $snapshot = StatutoryDeadline::query()
        ->where('subject_type', $grievance->getMorphClass())
        ->where('subject_id', $grievance->getKey())
        ->where('rule_key', Regulation201::GRIEVANCE_COMMITTEE_FILING)
        ->first();
    expect($snapshot)->not->toBeNull();
    expect($snapshot->amount)->toBe(10);
    expect($snapshot->basis)->toBe(Regulation201::WORKING_DAYS);
});
