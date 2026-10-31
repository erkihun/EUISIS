<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseInspectionFinding;
use Modules\Court\Domain\Models\CaseInspectionRequest;
use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/** A user holding exactly these permissions through one role. */
function ibrUser(array $permissions, ?string $roleName = null): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $role = Role::query()->firstOrCreate(['name' => $roleName ?? 'IBR '.Str::random(6)]);
    $role->permissions()->syncWithoutDetaching(collect($permissions)->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name], ['label' => Str::headline($name)])->id));
    $user->roles()->sync([$role->id]);

    return $user;
}

/** An admin: the `admin` role bypasses every permission check and sees every finding. */
function ibrAdmin(): User
{
    return ibrUser([], 'admin');
}

function ibrCase(array $attributes = []): CourtCase
{
    $type = CaseType::query()->create(['name' => 'IBR '.Str::random(4)]);

    return CourtCase::query()->create(array_merge([
        'case_number' => 'IBR-'.Str::upper(Str::random(8)),
        'title' => 'Almaz Tadesse',
        'case_type_id' => $type->id,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'accepted',
        'respondent_name' => 'Bole Sub-city',
    ], $attributes));
}

function ibrRequest(CourtCase $case, User $by, array $attributes = []): CaseInspectionRequest
{
    return CaseInspectionRequest::query()->create(array_merge([
        'court_case_id' => $case->id,
        'request_date' => now()->toDateString(),
        'subject' => 'Site inspection',
        'request_note' => 'Visit the premises.',
        'status' => 'pending',
        'requested_by_user_id' => $by->id,
        'assigned_inspector_user_id' => $by->id,
        'created_by_user_id' => $by->id,
        'updated_by_user_id' => $by->id,
    ], $attributes));
}

function ibrFinding(CaseInspectionRequest $request, User $by, array $attributes = []): CaseInspectionFinding
{
    return CaseInspectionFinding::query()->create(array_merge([
        'case_inspection_request_id' => $request->id,
        'finding_date' => now()->toDateString(),
        'title' => 'Boundary wall moved',
        'details' => 'The wall stands two metres into the plot.',
        'severity' => 'high',
        'recorded_by_user_id' => $by->id,
        'created_by_user_id' => $by->id,
        'updated_by_user_id' => $by->id,
    ], $attributes));
}

// --- Inspection requests ---------------------------------------------------

test('the inspection requests list renders with its rows, filters and choices', function (): void {
    $manager = ibrUser(['inspection-requests.manage']);
    $case = ibrCase();
    $request = ibrRequest($case, $manager);

    $this->actingAs($manager)->get(route('court.case-inspection-requests.index', ['status' => 'pending']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Inspections/RequestsIndex')
            ->where('filters', ['case_id' => '', 'status' => 'pending'])
            ->where('statuses', ['pending', 'in_progress', 'completed', 'cancelled'])
            ->where('nav.requests', true)
            ->where('nav.findings', false)
            ->has('cases', 1, fn (AssertableInertia $row) => $row->where('id', $case->id)->where('case_number', $case->case_number)->etc())
            ->where('requests.total', 1)
            ->has('requests.data', 1, fn (AssertableInertia $row) => $row
                ->where('id', $request->id)
                ->where('case_number', $case->case_number)
                ->where('subject', 'Site inspection')
                ->where('status', 'pending')
                ->where('request_date', now()->toDateString())
                ->where('assigned_inspector_name', $manager->name)
                ->where('created_by_name', $manager->name)
                ->etc()));
});

test('the inspection request form renders for a new and an existing request, and the store action still works', function (): void {
    $assigner = ibrUser(['inspection-requests.manage', 'assign.inspections']);
    $inspector = ibrUser(['inspection-findings.manage']);
    $case = ibrCase();

    $this->actingAs($assigner)->get(route('court.case-inspection-requests.create', ['case_id' => $case->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Inspections/RequestForm')
            ->where('request', null)
            ->where('canAssign', true)
            ->where('prefill.case_id', (string) $case->id)
            ->where('today', now()->toDateString())
            ->has('cases', 1)
            ->has('inspectors', 1, fn (AssertableInertia $row) => $row->where('id', $inspector->id)->where('name', $inspector->name)));

    $this->actingAs($assigner)
        ->post(route('court.case-inspection-requests.store'), [
            'court_case_id' => $case->id,
            'request_date' => now()->toDateString(),
            'subject' => 'Measure the plot',
            'request_note' => null,
            'status' => 'in_progress',
            'assigned_inspector_user_id' => $inspector->id,
        ])
        ->assertRedirect(route('court.case-inspection-requests.index'))
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('court_case_inspection_requests', ['court_case_id' => $case->id, 'subject' => 'Measure the plot', 'status' => 'in_progress', 'assigned_inspector_user_id' => $inspector->id]);
    $stored = CaseInspectionRequest::query()->where('subject', 'Measure the plot')->firstOrFail();

    $this->actingAs($assigner)->get(route('court.case-inspection-requests.edit', $stored->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Inspections/RequestForm')
            ->where('request.id', $stored->id)
            ->where('request.subject', 'Measure the plot')
            ->where('request.assigned_inspector_user_id', $inspector->id)
            ->where('request.request_note', null)
            ->etc());

    $this->actingAs($assigner)
        ->patch(route('court.case-inspection-requests.update', $stored->id), [
            'court_case_id' => $case->id, 'request_date' => now()->toDateString(), 'subject' => 'Measure the plot again', 'status' => 'pending', 'assigned_inspector_user_id' => null,
        ])
        ->assertRedirect(route('court.case-inspection-requests.index'))
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('court_case_inspection_requests', ['id' => $stored->id, 'subject' => 'Measure the plot again', 'assigned_inspector_user_id' => null]);
});

test('the inspection request page shows the request with its findings, and a completed request is locked', function (): void {
    $manager = ibrUser(['inspection-requests.manage']);
    $case = ibrCase();
    $request = ibrRequest($case, $manager, ['status' => 'completed']);
    $finding = ibrFinding($request, $manager);

    $this->actingAs($manager)->get(route('court.case-inspection-requests.show', $request->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Inspections/RequestShow')
            ->where('request.id', $request->id)
            ->where('request.case_number', $case->case_number)
            ->where('request.case_title', 'Almaz Tadesse')
            ->where('request.request_note', 'Visit the premises.')
            ->where('request.requested_by_name', $manager->name)
            ->where('canEdit', false)
            ->has('request.findings', 1, fn (AssertableInertia $row) => $row
                ->where('id', $finding->id)->where('title', 'Boundary wall moved')->where('severity', 'high')->where('recorded_by_name', $manager->name)->etc()));

    $this->actingAs($manager)->get(route('court.case-inspection-requests.edit', $request->id))
        ->assertRedirect(route('court.case-inspection-requests.show', $request->id));
});

// --- Inspection findings ---------------------------------------------------

test('the inspection findings list renders for an admin with every finding and the requests to filter by', function (): void {
    $admin = ibrAdmin();
    $inspector = ibrUser(['inspection-findings.manage']);
    $case = ibrCase();
    $request = ibrRequest($case, $inspector);
    $finding = ibrFinding($request, $inspector, ['attachment_path' => 'case-inspections/findings/x.pdf', 'attachment_original_name' => 'survey.pdf']);

    $this->actingAs($admin)->get(route('court.case-inspection-findings.index', ['case_id' => $case->id, 'severity' => 'high']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Inspections/FindingsIndex')
            ->where('filters', ['request_id' => '', 'case_id' => (string) $case->id, 'severity' => 'high'])
            ->where('severities', ['low', 'medium', 'high', 'critical'])
            ->where('nav.requests', true)
            ->where('nav.findings', true)
            ->has('requests', 1, fn (AssertableInertia $row) => $row->where('id', $request->id)->where('case_number', $case->case_number)->where('subject', 'Site inspection')->etc())
            ->has('findings.data', 1, fn (AssertableInertia $row) => $row
                ->where('id', $finding->id)
                ->where('request_id', $request->id)
                ->where('request_subject', 'Site inspection')
                ->where('case_number', $case->case_number)
                ->where('title', 'Boundary wall moved')
                ->where('severity', 'high')
                ->where('accepted', false)
                ->where('has_attachment', true)
                ->where('attachment_name', 'survey.pdf')
                ->etc()));

    // The inspector of another request sees nothing.
    $other = ibrUser(['inspection-findings.manage']);
    $this->actingAs($other)->get(route('court.case-inspection-findings.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Inspections/FindingsIndex')->has('findings.data', 0)->has('requests', 0));
});

test('the finding form renders and the store action with a PDF attachment completes the request', function (): void {
    Storage::fake('local');
    $inspector = ibrUser(['inspection-findings.manage']);
    $case = ibrCase();
    $request = ibrRequest($case, $inspector);

    $this->actingAs($inspector)->get(route('court.case-inspection-findings.create', ['request_id' => $request->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Inspections/FindingForm')
            ->where('finding', null)
            ->where('prefill.request_id', (string) $request->id)
            ->where('severities', ['low', 'medium', 'high', 'critical'])
            ->has('requests', 1, fn (AssertableInertia $row) => $row->where('id', $request->id)->where('request_date', now()->toDateString())->etc()));

    $this->actingAs($inspector)
        ->post(route('court.case-inspection-findings.store'), [
            'case_inspection_request_id' => $request->id,
            'finding_date' => now()->toDateString(),
            'title' => 'Fence encroaches',
            'details' => 'Measured on site.',
            'severity' => 'critical',
            'attachment_pdf' => UploadedFile::fake()->createWithContent('survey.pdf', '%PDF-1.4 survey'),
        ])
        ->assertRedirect(route('court.case-inspection-findings.index'))
        ->assertSessionHasNoErrors();

    $finding = CaseInspectionFinding::query()->where('title', 'Fence encroaches')->firstOrFail();
    expect($finding->attachment_original_name)->toBe('survey.pdf')
        ->and($finding->attachment_path)->not->toBeNull();
    Storage::disk('local')->assertExists($finding->attachment_path);
    $this->assertDatabaseHas('court_case_inspection_requests', ['id' => $request->id, 'status' => 'completed']);

    $this->actingAs($inspector)->get(route('court.case-inspection-findings.edit', $finding->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Inspections/FindingForm')
            ->where('finding.id', $finding->id)
            ->where('finding.details', 'Measured on site.')
            ->where('finding.has_attachment', true)
            ->where('finding.attachment_name', 'survey.pdf')
            ->etc());
});

test('the finding page shows the finding, the admin accepts it, and an accepted finding is locked', function (): void {
    $admin = ibrAdmin();
    $inspector = ibrUser(['inspection-findings.manage']);
    $case = ibrCase();
    $request = ibrRequest($case, $inspector);
    $finding = ibrFinding($request, $inspector);

    $this->actingAs($inspector)->get(route('court.case-inspection-findings.show', $finding->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Inspections/FindingShow')
            ->where('finding.id', $finding->id)
            ->where('finding.details', 'The wall stands two metres into the plot.')
            ->where('finding.recorded_by_name', $inspector->name)
            ->where('finding.accepted', false)
            ->where('finding.accepted_at', null)
            ->where('canEdit', true)
            ->where('canAccept', false)
            ->etc());

    $this->actingAs($admin)->get(route('court.case-inspection-findings.show', $finding->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canAccept', true)->etc());

    $this->actingAs($admin)
        ->from(route('court.case-inspection-findings.show', $finding->id))
        ->post(route('court.case-inspection-findings.accept', $finding->id))
        ->assertRedirect(route('court.case-inspection-findings.show', $finding->id));

    $this->actingAs($admin)->get(route('court.case-inspection-findings.show', $finding->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('finding.accepted', true)
            ->where('finding.accepted_by_name', $admin->name)
            ->where('canEdit', false)
            ->where('canAccept', false)
            ->etc());

    $this->actingAs($inspector)->get(route('court.case-inspection-findings.edit', $finding->id))
        ->assertRedirect(route('court.case-inspection-findings.show', $finding->id));
});

// --- Bench notes -----------------------------------------------------------

test('the bench notes list renders with its cards, the case filter and the permission flags', function (): void {
    $judge = ibrUser(['bench-notes.manage', 'bench-notes.view', 'bench-notes.create', 'bench-notes.update']);
    $second = User::factory()->create(['status' => 'active']);
    $case = ibrCase();
    $noteId = DB::table('court_bench_notes')->insertGetId([
        'case_id' => $case->id, 'user_id' => $judge->id, 'note' => '<p>Adjourned to <strong>next week</strong>.</p>',
        'judge_one_id' => $judge->id, 'judge_two_id' => $second->id, 'judge_three_id' => null,
        'created_at' => now()->subDay(), 'updated_at' => now(),
    ]);

    $this->actingAs($judge)->get(route('court.bench-notes.index', ['case_id' => $case->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/BenchNotes/Index')
            ->where('filters.case_id', (string) $case->id)
            ->where('can', ['view' => true, 'create' => true, 'update' => true, 'delete' => false])
            ->has('cases', 1, fn (AssertableInertia $row) => $row->where('id', $case->id)->etc())
            ->where('notes.total', 1)
            ->has('notes.data', 1, fn (AssertableInertia $row) => $row
                ->where('id', $noteId)
                ->where('case_id', $case->id)
                ->where('case_number', $case->case_number)
                ->where('case_title', 'Almaz Tadesse')
                ->where('note', '<p>Adjourned to <strong>next week</strong>.</p>')
                ->where('author_name', $judge->name)
                ->where('judges.0.name', $judge->name)
                ->where('judges.1.name', $second->name)
                ->where('judges.2', null)
                ->whereNot('updated_at', null)
                ->etc()));
});

test('the bench note form renders and the store, update and delete actions still work', function (): void {
    // Each action checks its own bench-notes.* permission on top of bench-notes.manage.
    $judge = ibrUser(['bench-notes.manage', 'bench-notes.view', 'bench-notes.create', 'bench-notes.update', 'bench-notes.delete']);
    $colleague = User::factory()->create(['status' => 'active']);
    $case = ibrCase();

    $this->actingAs($judge)->get(route('court.bench-notes.create', ['case_id' => $case->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/BenchNotes/Form')
            ->where('note', null)
            ->where('prefill.case_id', (string) $case->id)
            ->where('currentUser.id', $judge->id)
            ->where('noTeammates', false)
            ->has('cases', 1)
            ->where('judgeUsers', fn ($users) => collect($users)->contains(fn ($row) => $row['id'] === $judge->id) && collect($users)->contains(fn ($row) => $row['id'] === $colleague->id))
            ->has('thirdJudgeUsers'));

    $this->actingAs($judge)
        ->post(route('court.bench-notes.store'), [
            'case_id' => $case->id,
            'title' => 'First sitting',
            'note_date' => now()->toDateString(),
            'note' => '<p>Both parties present.</p><script>alert(1)</script>',
            'judges' => [['admin_user_id' => $judge->id], ['admin_user_id' => $colleague->id], ['admin_user_id' => null]],
        ])
        ->assertRedirect(route('court.bench-notes.index', ['case_id' => $case->id]))
        ->assertSessionHasNoErrors();

    $note = DB::table('court_bench_notes')->where('case_id', $case->id)->first();
    expect($note)->not->toBeNull()
        ->and($note->note)->toContain('Both parties present.')
        ->and($note->note)->not->toContain('<script')
        ->and((int) $note->judge_one_id)->toBe($judge->id)
        ->and((int) $note->judge_two_id)->toBe($colleague->id)
        ->and($note->judge_three_id)->toBeNull();

    $this->actingAs($judge)->get(route('court.bench-notes.edit', $note->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/BenchNotes/Form')
            ->where('note.id', $note->id)
            ->where('note.case_id', $case->id)
            ->where('note.judges.0.id', $judge->id)
            ->where('note.judges.1.id', $colleague->id)
            ->where('note.judges.2', null)
            ->etc());

    $this->actingAs($judge)->get(route('court.bench-notes.show', $note->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/BenchNotes/Show')->where('note.id', $note->id)->where('note.author_name', $judge->name)->etc());

    $this->actingAs($judge)
        ->patch(route('court.bench-notes.update', $note->id), [
            'case_id' => $case->id, 'title' => 'First sitting', 'note_date' => null, 'note' => '<p>Adjourned.</p>',
            'judges' => [['admin_user_id' => $judge->id], ['admin_user_id' => null], ['admin_user_id' => null]],
        ])
        ->assertRedirect(route('court.bench-notes.index', ['case_id' => $case->id]))
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('court_bench_notes', ['id' => $note->id, 'note' => '<p>Adjourned.</p>', 'judge_two_id' => null]);

    $this->actingAs($judge)->delete(route('court.bench-notes.destroy', $note->id))
        ->assertRedirect(route('court.bench-notes.index', ['case_id' => $case->id]));
    $this->assertDatabaseMissing('court_bench_notes', ['id' => $note->id]);
});

// --- Case records ----------------------------------------------------------

test('the case records register renders with its rows, filters and choices', function (): void {
    $viewer = ibrUser(['cases.view']);
    $assignee = User::factory()->create(['status' => 'active', 'name' => 'Kebede Alemu']);
    $case = ibrCase(['assigned_user_id' => $assignee->id, 'status' => 'closed']);
    $hidden = ibrCase(['review_status' => 'awaiting_review']);

    $this->actingAs($viewer)->get(route('court.recordes.index', ['q' => $case->case_number, 'status' => 'closed']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Records/Index')
            ->where('filters', ['q' => $case->case_number, 'status' => 'closed', 'case_type_id' => '', 'assignee_id' => '', 'from' => '', 'to' => '', 'appeal' => ''])
            ->where('statuses', ['pending', 'active', 'adjourned', 'dismissed', 'closed'])
            ->where('isReviewer', false)
            ->has('types', 2)
            ->where('users', fn ($users) => collect($users)->contains(fn ($row) => $row['id'] === $assignee->id && $row['name'] === 'Kebede Alemu'))
            ->where('court::cases.total', 1)
            ->has('cases.data', 1, fn (AssertableInertia $row) => $row
                ->where('id', $case->id)
                ->where('case_number', $case->case_number)
                ->where('title', 'Almaz Tadesse')
                ->where('assignee_name', 'Kebede Alemu')
                ->where('status', 'closed')
                ->where('filing_date', now()->toDateString())
                ->where('review_status', 'accepted')
                ->where('decisions_count', 0)
                ->where('has_appeal_record', true)
                ->etc()));

    // A case awaiting review is hidden from a non-reviewer.
    $this->actingAs($viewer)->get(route('court.recordes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('court::cases.total', 1)
            ->where('cases.data.0.id', $case->id)
            ->etc());
    expect($hidden->review_status)->toBe('awaiting_review');
});

test('the case record page renders every section of the file', function (): void {
    $viewer = ibrUser(['cases.view']);
    $author = User::factory()->create(['status' => 'active']);
    $case = ibrCase(['description' => '<p>Boundary <em>dispute</em>.</p>']);
    DB::table('court_case_hearings')->insert([
        'case_id' => $case->id, 'hearing_at' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d H:i:s'),
        'type' => 'Hearing', 'location' => 'Room 1', 'notes' => '<b>Bring</b> the deed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('court_case_witnesses')->insert(['case_id' => $case->id, 'full_name' => 'Marta Tesfaye', 'phone' => '0911000000', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('court_bench_notes')->insert([
        'case_id' => $case->id, 'user_id' => $author->id, 'note' => '<p>Noted.</p>', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($viewer)->get(route('court.recordes.show', $case->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Records/Show')
            ->where('case.id', $case->id)
            ->where('case.case_number', $case->case_number)
            ->where('case.title', 'Almaz Tadesse')
            ->where('case.status', 'pending')
            ->where('case.respondent_name', 'Bole Sub-city')
            ->where('case.description', '<p>Boundary <em>dispute</em>.</p>')
            ->where('case.filing_date', now()->toDateString())
            ->has('generatedAt')
            ->has('hearings', 1, fn (AssertableInertia $row) => $row->where('location', 'Room 1')->where('notes', 'Bring the deed')->etc())
            ->has('witnesses', 1, fn (AssertableInertia $row) => $row->where('full_name', 'Marta Tesfaye')->etc())
            ->has('benchNotes', 1, fn (AssertableInertia $row) => $row->where('note', '<p>Noted.</p>')->where('author', $author->name)->etc())
            ->has('letters', 0)
            ->has('respondentResponses', 0)
            ->has('submittedDocuments', 0)
            ->has('otherDocuments', 0)
            ->has('evidences', 0)
            ->where('decision', null)
            ->where('filesCount', 0)
            ->where('hasAppealRecord', false));

    // The printable record is still the Blade document.
    $this->actingAs($viewer)->get(route('court.recordes.pdf', $case->id))
        ->assertOk()
        ->assertSee($case->case_number);
});
