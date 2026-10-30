<?php

declare(strict_types=1);

use App\Models\SystemSetting;
use App\Services\SystemSettings\SystemSettingsService;
use App\Support\Legal\Regulation201;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Grievance\Application\Services\GrievanceCaseService;
use Modules\Grievance\Domain\Models\Grievance;
use Tests\Support\GrievanceScenario;

/*
 * Regulation 201/2018 Arts. 32–33 on the employee's own filing path (My Portal):
 * the application carries what Art. 32(2) lists, the matter was first presented
 * in writing to the immediate supervisor, and the grievance reaches the committee
 * within 10 working days of that date — later only on a force-majeure ground
 * filed within 10 working days of the cause ending, and then reviewed by a person.
 */

beforeEach(function (): void {
    // Monday 2026-10-19; the weekends around it are not working days.
    Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00'));
    Storage::fake('local');
    $this->s = GrievanceScenario::build();
});

afterEach(fn () => Carbon::setTestNow());

function gfwPost(object $test, array $fields, bool $submit = true)
{
    return $test->actingAs($test->s->complainant)->post(route('employee.grievances.store'), [
        'subject' => 'Overtime not paid',
        'description' => 'Overtime for September was not paid.',
        'category_id' => $test->s->category->id,
        'submit' => $submit,
        ...$fields,
    ]);
}

function gfwLatest(): Grievance
{
    return Grievance::query()->latest('created_at')->firstOrFail();
}

it('needs the full Art. 32 application to send, but saves an incomplete draft', function (): void {
    gfwPost($this, [])->assertSessionHasErrors(['incident_date', 'written_issue_raised_at', 'immediate_supervisor_name', 'applicant_address', 'requested_remedy', 'declaration']);
    expect(Grievance::query()->count())->toBe(0);

    gfwPost($this, [], submit: false)->assertSessionHasNoErrors()->assertRedirect();
    $draft = gfwLatest();
    expect($draft->status->value)->toBe('draft');

    // Sending the saved draft on its own still checks the stored application.
    $this->actingAs($this->s->complainant)->post(route('employee.grievances.submit', $draft))
        ->assertSessionHasErrors(['incident_date', 'written_issue_raised_at', 'requested_remedy', 'applicant_declared_at']);
    expect($draft->refresh()->status->value)->toBe('draft');
});

it('accepts a grievance inside the 10 working days and records the application', function (): void {
    // Presented in writing on Monday 2026-10-12: the day itself is not counted, so day 10 is Monday 2026-10-26.
    gfwPost($this, GrievanceScenario::application(['written_issue_raised_at' => '2026-10-12']))->assertSessionHasNoErrors();
    $g = gfwLatest();

    expect($g->status->value)->toBe('submitted')
        ->and($g->is_late_submission)->toBeFalse()
        ->and($g->late_review_status)->toBeNull()
        ->and($g->filing_clock_started_at->toDateString())->toBe('2026-10-12')
        ->and($g->filing_deadline_at->toDateString())->toBe('2026-10-26')
        ->and($g->immediate_supervisor_name)->toBe('Records Team Leader')
        ->and($g->requested_remedy)->toBe('Pay the overtime that is owed.')
        ->and($g->applicant_address)->not->toBeNull()
        ->and($g->applicant_declared_at)->not->toBeNull()
        ->and($g->position_id)->toBe($this->s->complainant->employee->currentAssignment?->position_id);

    expect($g->statutoryDeadline(Regulation201::GRIEVANCE_COMMITTEE_FILING)?->completed_at)->not->toBeNull();
});

it('refuses a grievance filed after the 10 working days without a force-majeure ground', function (): void {
    // Presented on 2026-09-21: the window closed well before today.
    gfwPost($this, GrievanceScenario::application(['written_issue_raised_at' => '2026-09-21']))
        ->assertSessionHasErrors('written_issue_raised_at');
    expect(Grievance::query()->where('status', '!=', 'draft')->count())->toBe(0);

    // A stated ground whose cause ended more than 10 working days ago is also out of time.
    gfwPost($this, GrievanceScenario::application([
        'written_issue_raised_at' => '2026-09-01', 'late_submission_reason' => 'Hospitalised.', 'cause_ended_at' => '2026-09-25',
    ]))->assertSessionHasErrors('written_issue_raised_at');
    expect(Grievance::query()->where('status', '!=', 'draft')->count())->toBe(0);
});

it('accepts a late grievance on a force-majeure ground only through a person at intake', function (): void {
    // Intake review switched off: in-time grievances would route straight to the handler.
    SystemSetting::query()->updateOrCreate(['group' => 'grievances', 'key' => 'intake_review_enabled'], ['value' => 'false']);
    app(SystemSettingsService::class)->clearCache();

    gfwPost($this, GrievanceScenario::application([
        'written_issue_raised_at' => '2026-09-01', 'late_submission_reason' => 'Hospitalised after an accident.', 'cause_ended_at' => '2026-10-14',
    ]))->assertSessionHasNoErrors();
    $g = gfwLatest();

    expect($g->status->value)->toBe('submitted')
        ->and($g->is_late_submission)->toBeTrue()
        ->and($g->late_review_status)->toBe('pending');

    app(GrievanceCaseService::class)->intakeAccept($g, $this->s->intake, 'Hospital record supports the ground.');
    $g->refresh();
    expect($g->late_review_status)->toBe('accepted')
        ->and($g->late_reviewed_by)->toBe($this->s->intake->id)
        ->and($g->late_decision_reason)->toBe('Hospital record supports the ground.');
});
