<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceInitialResolutionOutcome as Outcome;
use App\Models\Grievance;
use App\Models\PublicHoliday;
use App\Models\StatutoryDeadline;
use App\Services\Calendar\PublicHolidayCalendar;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Grievances\GrievanceDecisionService;
use App\Services\Grievances\GrievanceEscalationService;
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

it('snapshots the 10-wd head-decision on submission and the 5-wd delivery on finalize', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00')); // Monday
    $scenario = GrievanceScenario::build();
    $scenario->requireDirectorateApproval();
    $grievance = acceptedGrievance($scenario);

    // Escalate twice (committee → team → directorate, where approval is required),
    // past each stage's SLA timeout, matching the committee 3-wd / team 10-wd seed.
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    app(GrievanceEscalationService::class)->autoEscalate($grievance->refresh()->current_stage_id);
    Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00'));
    app(GrievanceEscalationService::class)->autoEscalate($grievance->refresh()->current_stage_id);
    $grievance->refresh();
    expect($grievance->currentStage->handler_id)->toBe($scenario->directorate->id);

    $decisions = app(GrievanceDecisionService::class);
    $decision = $decisions->createDraft($grievance, $scenario->directorateOfficer, [
        'decision_type' => 'upheld', 'decision_text' => 'Upheld.', 'findings' => 'F',
    ]);
    $decision = $decisions->submitForApproval($decision, $scenario->directorateOfficer);

    $head = openDeadline($grievance, Regulation201::GRIEVANCE_HEAD_DECISION);
    expect($head)->not->toBeNull();
    expect($head->amount)->toBe(10);

    $decisions->approve($decision, $scenario->approver, null);
    $decisions->finalize($decision->refresh(), $scenario->directorateOfficer);

    // Head-decision deadline is completed; delivery (5 wd) is now open.
    expect($head->refresh()->completed_at)->not->toBeNull();
    $delivery = openDeadline($grievance, Regulation201::GRIEVANCE_DECISION_DELIVERY);
    expect($delivery)->not->toBeNull();
    expect($delivery->amount)->toBe(5);
});

it('tracks the head-decision deadline from the committee recommendation to the head approving it', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $scenario = GrievanceScenario::build();
    $grievance = acceptedGrievance($scenario);

    $decisions = app(GrievanceDecisionService::class);
    $decision = $decisions->createDraft($grievance->refresh(), $scenario->writer, ['decision_type' => 'not_upheld', 'decision_text' => 'Not upheld.']);
    // Art. 35(1): the committee recommends; the head of the institution decides.
    $scenario->headDecides($decision, $scenario->chair);

    $head = StatutoryDeadline::query()
        ->where('subject_type', $grievance->getMorphClass())
        ->where('subject_id', $grievance->getKey())
        ->where('rule_key', Regulation201::GRIEVANCE_HEAD_DECISION)
        ->first();

    expect($head)->not->toBeNull()
        ->and($head->amount)->toBe(10)
        ->and($head->completed_at)->not->toBeNull()
        ->and(openDeadline($grievance, Regulation201::GRIEVANCE_DECISION_DELIVERY))->not->toBeNull();
});

it('keeps a snapshotted deadline unchanged when a holiday is added later', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00:00'));
    $scenario = GrievanceScenario::build();
    $grievance = acceptedGrievance($scenario);

    $before = $grievance->statutoryDeadline(Regulation201::GRIEVANCE_COMMITTEE_REPORT)->due_at->toDateTimeString();

    // Insert a public holiday inside the window AFTER the snapshot was taken.
    PublicHoliday::query()->create([
        'name_en' => 'Late-added holiday',
        'holiday_date' => '2026-03-10',
        'is_active' => true,
    ]);
    app(PublicHolidayCalendar::class)->clearCache();

    $after = $grievance->fresh()->statutoryDeadline(Regulation201::GRIEVANCE_COMMITTEE_REPORT)->due_at->toDateTimeString();

    expect($after)->toBe($before);
});

it('reports the statutory deadline as the effective deadline, not the internal SLA', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00:00'));
    $scenario = GrievanceScenario::build();
    $grievance = acceptedGrievance($scenario);

    $statutory = $grievance->statutoryDeadline(Regulation201::GRIEVANCE_COMMITTEE_REPORT);

    expect($grievance->effectiveDeadline(Regulation201::GRIEVANCE_COMMITTEE_REPORT)->toDateTimeString())
        ->toBe($statutory->due_at->toDateTimeString());
});
