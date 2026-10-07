<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceSignatureMethod;
use App\Models\Grievance;
use App\Models\GrievanceEvidence;
use App\Models\GrievanceRoute;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Calendar\WorkingDayCalculator;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Grievances\GrievanceCorrespondenceService;
use App\Services\Grievances\GrievanceDecisionService;
use App\Services\Grievances\GrievanceReportService;
use App\Support\DailyActivity\DailyActivityRoles;
use App\Support\Grievances\GrievanceRoles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\GrievanceScenario;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    Storage::fake('local');
    $this->s = GrievanceScenario::build();
});

afterEach(fn () => Carbon::setTestNow());

/** File a grievance through the portal endpoints and accept it at intake. */
function httpFile(object $test, bool $accept = true): Grievance
{
    $test->actingAs($test->s->complainant)->post(route('employee.grievances.store'), [
        'subject' => 'Overtime not paid',
        'description' => 'Narrative that must stay confidential.',
        'category_id' => $test->s->category->id,
        'submit' => true,
        'files' => [UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')],
        'file_types' => ['document'],
    ])->assertRedirect();

    $grievance = Grievance::query()->latest('created_at')->firstOrFail();
    if ($accept) {
        app(GrievanceCaseService::class)->intakeAccept($grievance, $test->s->intake, null);
    }

    return $grievance->refresh();
}

it('lets an employee file through My Portal and see only a safe timeline', function (): void {
    $g = httpFile($this);
    app(GrievanceCaseService::class)->addNote($g, $this->s->chair, 'Internal remark');

    $this->actingAs($this->s->complainant)->get(route('employee.grievances.show', $g))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Grievances/Portal/Show')
            ->where('grievance.reference_number', $g->reference_number)
            ->where('grievance.timeline', fn ($events) => collect($events)->every(fn ($e) => $e['visibility'] === 'complainant' && $e['actor'] === null))
            ->missing('tabs')
            ->missing('grievance.notes'));
});

it('never shows another employee grievance on My Portal', function (): void {
    $g = httpFile($this);
    $other = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, $this->s->woredaUnit), GrievanceRoles::EMPLOYEE_PERMISSIONS);

    $this->actingAs($other)->get(route('employee.grievances.show', $g))->assertNotFound();
});

it('keeps plain employees out of every staff grievance page', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $employee = User::factory()->create(['status' => 'active']);
    $employee->assignRole(DailyActivityRoles::EMPLOYEE_ROLE);

    foreach (['grievances.dashboard', 'grievances.cases.index', 'grievances.approvals.index', 'grievances.correspondence.index',
        'grievances.committees.index', 'grievances.routes.index', 'grievances.sla.index', 'grievances.reports.index', 'grievances.settings.index'] as $name) {
        $this->actingAs($employee)->get(route($name))->assertForbidden();
    }
});

it('hides a case from users with permissions but no relationship to it', function (): void {
    $g = httpFile($this);

    $this->actingAs($this->s->chair)->get(route('grievances.cases.show', $g))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Grievances/Cases/Show')->where('viewer.handles', true));
    $this->actingAs($this->s->outsider)->get(route('grievances.cases.show', $g))->assertNotFound();
    $this->actingAs($this->s->outsider)->get(route('grievances.cases.index', ['tab' => 'authorized']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('grievances.total', 0));
});

it('authorizes every evidence download at request time', function (): void {
    $g = httpFile($this);
    $own = $g->evidence()->firstOrFail();

    $this->actingAs($this->s->complainant)->get(route('employee.grievances.evidence.download', [$g, $own]))->assertOk();
    $this->actingAs($this->s->outsider)->get(route('grievances.cases.evidence.download', [$g, $own]))->assertForbidden();

    // Evidence a handler adds is not the complainant's to download.
    $this->actingAs($this->s->writer)->post(route('grievances.cases.evidence.store', $g), [
        'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
        'evidence_type' => 'report', 'title' => 'Investigation note',
    ])->assertRedirect();
    $internal = GrievanceEvidence::query()->where('submitted_by_complainant', false)->firstOrFail();
    $this->actingAs($this->s->complainant)->get(route('employee.grievances.evidence.download', [$g, $internal]))->assertForbidden();
});

it('rejects a file whose content does not match its extension', function (): void {
    $g = httpFile($this);
    $file = UploadedFile::fake()->createWithContent('fake.pdf', 'plain text, not a PDF');
    // A real UploadedFile detects content; Testing\File reports MIME by name.
    $upload = new UploadedFile($file->getPathname(), 'fake.pdf', 'application/pdf', null, true);

    $this->actingAs($this->s->writer)->from(route('grievances.cases.show', $g))->post(route('grievances.cases.evidence.store', $g), [
        'file' => $upload,
        'evidence_type' => 'document', 'title' => 'Fake',
    ])->assertSessionHasErrors('file');
});

it('refuses a checksum mismatch instead of serving a replaced evidence file', function (): void {
    $g = httpFile($this);
    $evidence = $g->evidence()->firstOrFail();
    Storage::disk('local')->put($evidence->path, 'tampered');

    $this->actingAs($this->s->complainant)->get(route('employee.grievances.evidence.download', [$g, $evidence]))->assertStatus(409);
});

it('lets the complainant download an issued decision letter but never a draft', function (): void {
    $g = httpFile($this);
    $decisions = app(GrievanceDecisionService::class);
    $d = $decisions->finalize($decisions->createDraft($g, $this->s->writer, ['decision_type' => 'upheld', 'decision_text' => 'Upheld.']), $this->s->chair);
    $letters = app(GrievanceCorrespondenceService::class);
    $letter = $letters->generate($g->refresh(), $this->s->writer, ['letter_type' => 'decision_letter', 'language' => 'en', 'decision_id' => $d->id]);

    $this->actingAs($this->s->complainant)->get(route('employee.grievances.letters.download', [$g, $letter]))->assertForbidden();

    $letter = $letters->issue($letters->sign($letters->finalize($letter, $this->s->chair), $this->s->chair, GrievanceSignatureMethod::ElectronicApproval), $this->s->chair);
    $this->actingAs($this->s->complainant)->get(route('employee.grievances.letters.download', [$g, $letter]))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('requires a different user to approve a new route', function (): void {
    $admin = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    $other = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);

    $this->actingAs($admin)->post(route('grievances.routes.store'), [
        'source_handler_type' => 'organization_unit', 'source_handler_id' => $this->s->team->id,
        'target_handler_type' => 'committee', 'target_handler_id' => $this->s->committee->id,
        'movement_type' => 'returned', 'priority' => 10, 'effective_from' => '2026-10-01',
    ])->assertSessionHasNoErrors();
    $route = GrievanceRoute::query()->where('movement_type', 'returned')->firstOrFail();
    expect($route->approved_at)->toBeNull();

    $this->actingAs($admin)->post(route('grievances.routes.approve', $route))->assertSessionHasErrors('route');
    $this->actingAs($other)->post(route('grievances.routes.approve', $route))->assertSessionHasNoErrors();
    expect($route->refresh()->approved_at)->not->toBeNull();
});

it('forbids self-delegation of approval authority', function (): void {
    $manager = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), ['grievance_settings.manage_delegations', 'grievance_decisions.approve']);

    $this->actingAs($manager)->post(route('grievances.settings.delegations.store'), [
        'delegator_user_id' => $this->s->approver->id, 'delegate_user_id' => $manager->id,
        'starts_at' => '2026-10-05 00:00', 'ends_at' => '2026-10-20 00:00', 'reason' => 'Leave',
    ])->assertSessionHasErrors('delegate_user_id');
});

it('escalates overdue stages from the scheduled command', function (): void {
    $g = httpFile($this);
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));

    $this->artisan('grievances:process-sla')->assertSuccessful();
    $this->artisan('grievances:process-sla')->assertSuccessful();

    expect($g->refresh()->currentStage->handler_id)->toBe($this->s->team->id)
        ->and($g->stages()->count())->toBe(2);
});

it('sends notifications without grievance content', function (): void {
    $g = httpFile($this);

    $notes = DatabaseNotification::query()->get();
    expect($notes)->not->toBeEmpty();
    foreach ($notes as $note) {
        expect(array_keys($note->data))->toEqualCanonicalizing(['module', 'kind', 'case_number', 'url'])
            ->and(json_encode($note->data))->not->toContain('Overtime')->not->toContain('Narrative');
    }
});

it('exports aggregate reports without employee names and suppresses small groups', function (): void {
    httpFile($this);
    $viewer = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::OVERSIGHT_PERMISSIONS);

    $csv = $this->actingAs($viewer)->get(route('grievances.reports.export', ['report' => 'by_category', 'format' => 'csv']));
    $csv->assertOk();
    $content = $csv->streamedContent();
    expect($content)->not->toContain($this->s->complainant->employee->full_name)
        // One case < the default privacy threshold of 5: the row is suppressed.
        ->and($content)->not->toContain('Working conditions');

    $this->actingAs($viewer)->get(route('grievances.reports.index', ['report' => 'by_category']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('result.suppressed', 1));
});

it('counts working days around recurring Ethiopian and Gregorian holidays', function (): void {
    PublicHoliday::query()->create(['name_en' => 'Meskel', 'holiday_date' => '2025-09-27', 'is_recurring' => true, 'recurrence_type' => 'ethiopian', 'is_active' => true]);
    PublicHoliday::query()->create(['name_en' => 'Adwa', 'holiday_date' => '2020-03-02', 'is_recurring' => true, 'recurrence_type' => 'gregorian', 'is_active' => true]);
    $calc = app(WorkingDayCalculator::class);

    // Fri 25 Sep 2026 + 1 working day: Meskel falls on Sun 27 Sep, so Mon 28th.
    expect($calc->addWorkingDays(Carbon::parse('2026-09-25 10:00'), 1, [1, 2, 3, 4, 5])->toDateString())->toBe('2026-09-28')
        // Fri 27 Feb 2026 + 1: Mon 2 Mar is Adwa → Tue 3 Mar.
        ->and($calc->addWorkingDays(Carbon::parse('2026-02-27 10:00'), 1, [1, 2, 3, 4, 5])->toDateString())->toBe('2026-03-03');
});

it('renders every grievance page for an authorized user', function (): void {
    $g = httpFile($this);
    $admin = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), array_values(array_unique([
        ...GrievanceRoles::ADMINISTRATOR_PERMISSIONS, ...GrievanceRoles::OVERSIGHT_PERMISSIONS, ...GrievanceRoles::REGISTRY_PERMISSIONS,
        'grievance_decisions.approve', 'grievances.intake_review', 'grievances.view_assigned',
    ])));

    foreach ([
        ['grievances.dashboard', [], 'Grievances/Dashboard'],
        ['grievances.cases.index', ['tab' => 'authorized'], 'Grievances/Cases/Index'],
        ['grievances.cases.show', [$g->id], 'Grievances/Cases/Show'],
        ['grievances.approvals.index', [], 'Grievances/Approvals/Index'],
        ['grievances.appeals.index', [], 'Grievances/Appeals/Index'],
        ['grievances.correspondence.index', [], 'Grievances/Correspondence/Index'],
        ['grievances.committees.index', [], 'Grievances/Committees/Index'],
        ['grievances.committees.show', [$this->s->committee->id], 'Grievances/Committees/Show'],
        ['grievances.routes.index', [], 'Grievances/Routing/Index'],
        ['grievances.sla.index', [], 'Grievances/Sla/Index'],
        ['grievances.settings.index', [], 'Grievances/Settings/Index'],
    ] as [$name, $params, $component]) {
        $this->actingAs($admin)->get(route($name, $params))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component($component));
    }
    foreach (GrievanceReportService::REPORTS as $report) {
        $r = $this->actingAs($admin)->get(route('grievances.reports.index', ['report' => $report]));
        expect([$report, $r->status(), $r->status() === 500 ? substr((string) ($r->exception?->getMessage()), 0, 300) : ''])->toBe([$report, 200, '']);
    }
    foreach (['employee.grievances.index' => [], 'employee.grievances.create' => [], 'employee.grievances.edit' => [$g->id]] as $name => $params) {
        $status = $this->actingAs($this->s->complainant)->get(route($name, $params))->status();
        expect($status)->toBeIn($name === 'employee.grievances.edit' ? [403] : [200]);
    }
});

it('saves the policy settings exactly as the settings page submits them', function (): void {
    $admin = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    $payload = collect(app(\App\Services\SystemSettings\SystemSettingsService::class)->getGroupForAdmin('grievances'))
        ->mapWithKeys(fn (array $f) => [$f['key'] => $f['value']])->all();
    $payload['work_week_days'] = ['1', '2', '3', '4', '5', '6'];
    $payload['committee_max_members'] = 7;
    $payload['voting_enabled'] = true;

    $this->actingAs($admin)->put(route('grievances.settings.policy.update'), $payload)->assertSessionHasNoErrors();

    $settings = app(\App\Services\Grievances\GrievanceSettings::class);
    app(\App\Services\SystemSettings\SystemSettingsService::class)->clearCache();
    expect($settings->workWeekDays())->toBe([1, 2, 3, 4, 5, 6])
        ->and($settings->committeeMaxMembers())->toBe(7)
        ->and($settings->votingEnabled())->toBeTrue();
});

it('refuses every My Portal action on another employee grievance', function (): void {
    $g = httpFile($this);
    $other = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, $this->s->woredaUnit), GrievanceRoles::EMPLOYEE_PERMISSIONS);
    $before = [$g->status, $g->evidence()->count(), $g->appeals()->count()];

    $this->actingAs($other)->get(route('employee.grievances.edit', $g))->assertForbidden();
    $this->actingAs($other)->post(route('employee.grievances.update', $g), ['subject' => 'Hijack', 'description' => 'Changed by someone else.', 'category_id' => $this->s->category->id])->assertForbidden();
    $this->actingAs($other)->post(route('employee.grievances.withdraw', $g), ['reason_code' => 'other'])->assertForbidden();
    $this->actingAs($other)->post(route('employee.grievances.accept-outcome', $g))->assertForbidden();
    $this->actingAs($other)->post(route('employee.grievances.appeal', $g), ['reason' => 'Appeal filed by someone else.'])->assertForbidden();
    $this->actingAs($other)->post(route('employee.grievances.evidence.store', $g), [
        'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), 'evidence_type' => 'document', 'title' => 'Planted',
    ])->assertForbidden();
    $this->actingAs($other)->get(route('employee.grievances.evidence.download', [$g, $g->evidence()->firstOrFail()]))->assertForbidden();

    $g->refresh();
    expect([$g->status, $g->evidence()->count(), $g->appeals()->count()])->toEqual($before);
});
