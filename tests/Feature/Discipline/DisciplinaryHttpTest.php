<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\Discipline\DisciplinaryCaseStatus;
use App\Models\AuditLog;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryEvidence;
use App\Services\Discipline\DisciplinaryCaseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DisciplineScenario;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    Storage::fake('local');
    $this->s = DisciplineScenario::build();
});

afterEach(fn () => Carbon::setTestNow());

function httpChargedCase(object $test): DisciplinaryCase
{
    $test->actingAs($test->s->officer)->post(route('discipline.cases.store'), [
        'employee_number' => $test->s->accusedEmployee()->employee_number,
        'track' => 'formal', 'severity' => 'serious', 'summary' => 'Absence',
        // Ignored: never mass-assignable from a request.
        'status' => 'decided', 'organization_id' => $test->s->otherInstitution->id, 'committee_id' => $test->s->committee->id,
    ])->assertRedirect();

    $case = DisciplinaryCase::query()->latest('created_at')->firstOrFail();
    expect($case->status)->toBe(DisciplinaryCaseStatus::Draft)
        ->and($case->organization_id)->toBe($test->s->institution->id)
        ->and($case->committee_id)->toBeNull();

    $test->post(route('discipline.cases.charges.store', $case), [
        'title' => 'Absence', 'particulars' => 'Absent five days.', 'occurred_on' => '2026-09-01',
        'occurred_place' => 'Records Team', 'violated_provision' => 'Art. 95', 'evidence_list' => 'Attendance sheet',
    ])->assertSessionHasNoErrors();
    $test->post(route('discipline.cases.panel', $case), ['committee_id' => $test->s->committee->id])->assertSessionHasNoErrors();
    $test->post(route('discipline.cases.file', $case))->assertSessionHasNoErrors();

    return $case->refresh();
}

it('opens and charges a case over HTTP, ignoring any browser-supplied state', function (): void {
    $case = httpChargedCase($this);

    expect($case->status)->toBe(DisciplinaryCaseStatus::Charged)
        ->and($case->charges()->count())->toBe(1);

    $this->actingAs($this->s->officer)->get(route('discipline.cases.show', $case))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Discipline/Show')
            ->where('disciplinaryCase.case_number', $case->case_number)
            ->where('abilities.serveSummons', true)
            ->where('abilities.decide', false));
    expect(AuditLog::query()->where('event_type', AuditEventType::DisciplinaryCaseViewed->value)->exists())->toBeTrue();
});

it('gives the same answer for unknown and out-of-scope employee numbers', function (): void {
    $this->actingAs($this->s->outsider)->post(route('discipline.cases.store'), [
        'employee_number' => $this->s->accusedEmployee()->employee_number, 'track' => 'formal', 'severity' => 'serious',
    ])->assertSessionHasErrors('employee_number');
    $this->post(route('discipline.cases.store'), ['employee_number' => 'NOPE-0000', 'track' => 'formal', 'severity' => 'serious'])
        ->assertSessionHasErrors('employee_number');

    expect(DisciplinaryCase::query()->count())->toBe(0);
});

it('scopes the staff list and blocks out-of-scope or non-seated staff from a case', function (): void {
    $case = httpChargedCase($this);

    $this->actingAs($this->s->officer)->get(route('discipline.cases.index'))
        ->assertInertia(fn (Assert $page) => $page->component('Discipline/Index')->has('cases.data', 1));
    $this->actingAs($this->s->outsider)->get(route('discipline.cases.index'))
        ->assertInertia(fn (Assert $page) => $page->has('cases.data', 0));

    $this->actingAs($this->s->outsider)->get(route('discipline.cases.show', $case))->assertForbidden();
    $this->actingAs($this->s->bystander)->get(route('discipline.cases.show', $case))->assertForbidden();
    $this->actingAs($this->s->chair)->get(route('discipline.cases.show', $case))->assertOk();
});

it('lets the accused answer through My Portal without seeing committee deliberation', function (): void {
    $case = httpChargedCase($this);
    $chargeId = $case->charges()->value('id');

    // The staff URL sends the accused to their own portal view.
    $this->actingAs($this->s->accused)->get(route('discipline.cases.show', $case))->assertRedirect(route('employee.discipline.show', $case));

    $this->get(route('employee.discipline.index'))->assertInertia(fn (Assert $page) => $page->component('Discipline/Portal/Index')->has('cases', 1));
    $this->get(route('employee.discipline.show', $case))
        ->assertInertia(fn (Assert $page) => $page->component('Discipline/Portal/Show')
            ->missing('disciplinaryCase.recommendations')
            ->missing('disciplinaryCase.members')
            ->missing('disciplinaryCase.history')
            ->where('abilities.recordResponse', true)
            ->where('abilities.decide', false));

    $this->post(route('employee.discipline.response', $case), [
        'pleas' => [$chargeId => ['plea' => 'denied', 'statement' => 'I was on sick leave.']],
        'acknowledged_name' => 'Accused Employee',
    ])->assertSessionHasNoErrors();
    $this->post(route('employee.discipline.evidence.store', $case), [
        'side' => 'institution', // forced to "accused" for the accused
        'title' => 'Medical certificate',
        'file' => UploadedFile::fake()->create('certificate.pdf', 30, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    expect($case->refresh()->status)->toBe(DisciplinaryCaseStatus::UnderInvestigation)
        ->and(DisciplinaryEvidence::query()->sole()->side->value)->toBe('accused');

    // The accused cannot take institution actions on their own case.
    $this->post(route('discipline.cases.decision', $case), ['action' => 'approve', 'reason' => 'x'])->assertForbidden();
});

it('hides other employees\' cases in My Portal behind a plain 404', function (): void {
    $case = httpChargedCase($this);

    $this->actingAs($this->s->bystander)->get(route('employee.discipline.show', $case))->assertNotFound();
    $this->actingAs($this->s->officer)->get(route('employee.discipline.show', $case))->assertNotFound();

    $draft = app(DisciplinaryCaseService::class)->open($this->s->officer, $this->s->accusedEmployee(), ['track' => 'formal', 'severity' => 'serious']);
    $this->actingAs($this->s->accused)->get(route('employee.discipline.show', $draft))->assertNotFound();
});

it('serves evidence only through the verified, audited private download', function (): void {
    $case = httpChargedCase($this);
    $this->actingAs($this->s->officer)->post(route('discipline.cases.evidence.store', $case), [
        'side' => 'institution', 'title' => 'Attendance sheet',
        'file' => UploadedFile::fake()->create('attendance.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $evidence = DisciplinaryEvidence::query()->sole();

    expect($evidence->path)->toStartWith('discipline/'.$case->id.'/evidence/')
        ->and($evidence->toArray())->not->toHaveKeys(['path', 'disk']);

    $this->get(route('discipline.cases.evidence.download', [$case, $evidence]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->s->outsider)->get(route('discipline.cases.evidence.download', [$case, $evidence]))->assertForbidden();
    expect(AuditLog::query()->where('event_type', AuditEventType::DisciplinaryEvidenceDownloaded->value)->count())->toBe(1);

    // Executable content is refused.
    $this->actingAs($this->s->officer)->post(route('discipline.cases.evidence.store', $case), [
        'side' => 'institution', 'title' => 'Script', 'file' => UploadedFile::fake()->create('x.php', 1, 'application/x-php'),
    ])->assertSessionHasErrors('file');
});
