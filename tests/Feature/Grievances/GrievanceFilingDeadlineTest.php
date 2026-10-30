<?php

declare(strict_types=1);

use App\Models\PublicHoliday;
use App\Models\StatutoryDeadline;
use App\Services\Calendar\PublicHolidayCalendar;
use App\Services\Legal\StatutoryDeadlineService;
use App\Support\Legal\Regulation201;
use Illuminate\Support\Carbon;
use Modules\Grievance\Application\Services\GrievanceCaseService;
use Modules\Grievance\Domain\Enums\GrievanceInitialResolutionOutcome as Outcome;
use Modules\Grievance\Domain\Models\Grievance;
use Tests\Support\GrievanceScenario;

afterEach(fn () => Carbon::setTestNow());

function fileGrievanceFromUnresolved(GrievanceScenario $scenario): Grievance
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

it('rolls the filing deadline past a public holiday inside the window', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00:00')); // Monday
    $scenario = GrievanceScenario::build();

    // A one-off holiday on a working day inside the Mon 03-02 → Mon 03-16 window.
    PublicHoliday::query()->create([
        'name_en' => 'Test holiday',
        'holiday_date' => '2026-03-10',
        'is_active' => true,
    ]);
    app(PublicHolidayCalendar::class)->clearCache();

    $grievance = fileGrievanceFromUnresolved($scenario);

    // Without the holiday the deadline is 2026-03-16; one excluded working day
    // pushes it to the next working day, 2026-03-17 (Tuesday).
    expect($grievance->filing_deadline_at->toDateString())->toBe('2026-03-17');
    expect($grievance->is_late_submission)->toBeFalse();
});

it('flags a submission filed after the working-day deadline as late', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00:00')); // Monday: resolution date
    $scenario = GrievanceScenario::build();
    $service = app(GrievanceCaseService::class);
    $issue = $service->raiseInitialIssue($scenario->complainant, ['issue_subject' => 'A', 'issue_description' => 'B']);
    $service->recordInitialResolution($issue->refresh(), $scenario->complainant, Outcome::Unresolved, []);

    // File the formal grievance after the 10-working-day deadline (2026-03-16).
    Carbon::setTestNow(Carbon::parse('2026-03-18 09:00:00'));
    $grievance = $service->submitFormalGrievance($issue->refresh(), $scenario->complainant, [
        'cause_of_grievance' => 'x',
        'requested_remedy' => 'y',
        'incident_date' => '2026-02-20',
        'category_id' => $scenario->category->id,
    ]);

    expect($grievance->filing_deadline_at->toDateString())->toBe('2026-03-16');
    expect($grievance->is_late_submission)->toBeTrue();
});
