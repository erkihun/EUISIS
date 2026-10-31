<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function caseDetailUser(array $permissions): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $role = Role::query()->create(['name' => 'Case detail '.Str::random(6)]);
    $role->permissions()->sync(collect($permissions)->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name], ['label' => Str::headline($name)])->id));
    $user->roles()->sync([$role->id]);

    return $user;
}

function caseDetailCase(array $attributes = []): CourtCase
{
    $type = CaseType::query()->create(['name' => 'Detail '.Str::random(4)]);

    return CourtCase::query()->create(array_merge([
        'case_number' => 'CD-'.Str::upper(Str::random(8)),
        'title' => 'Abebe Kebede',
        'case_type_id' => $type->id,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'accepted',
        'respondent_name' => 'Addis Ababa City Administration',
    ], $attributes));
}

test('the case detail page renders with its main props for a viewer', function (): void {
    $viewer = caseDetailUser(['cases.view', 'hearing.view', 'file.view']);
    $case = caseDetailCase();
    DB::table('court_case_hearings')->insert([
        'case_id' => $case->id, 'hearing_at' => now()->addDays(5)->setTime(9, 30)->format('Y-m-d H:i:s'),
        'type' => 'Preliminary', 'location' => 'Room 4', 'notes' => 'First sitting', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('court_case_witnesses')->insert([
        'case_id' => $case->id, 'full_name' => 'Marta Tesfaye', 'phone' => '0911000000', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($viewer)->get(route('court.cases.show', $case->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Cases/Show')
            ->where('case.id', $case->id)
            ->where('case.case_number', $case->case_number)
            ->where('case.status', 'pending')
            ->where('case.review_status', 'accepted')
            ->where('case.applicant_display_name', 'Abebe Kebede')
            ->where('case.respondent_name', 'Addis Ababa City Administration')
            ->where('can.editStatus', false)
            ->where('can.viewHearings', true)
            ->where('can.viewFiles', true)
            ->where('can.review', false)
            ->where('caseLocked', false)
            ->where('statuses', ['pending', 'active', 'dismissed', 'closed'])
            ->has('hearings', 1, fn (AssertableInertia $hearing) => $hearing
                ->where('type', 'Preliminary')
                ->where('location', 'Room 4')
                ->where('date_locked', false)
                ->etc())
            ->has('witnesses', 1, fn (AssertableInertia $witness) => $witness->where('full_name', 'Marta Tesfaye')->etc())
            ->has('files', 0)
            ->has('messages', 0)
            ->has('letters', 0)
            ->has('audits')
            ->has('timeline')
            ->has('respondentResponses')
            ->has('letterTemplates')
            ->has('inspectionRequests')
            ->has('inspectionFindings')
            ->where('euisis', null)
            ->etc());
});

test('a team member who is not the assignee is refused the case', function (): void {
    $leader = caseDetailUser(['cases.view']);
    $member = caseDetailUser(['cases.view']);
    $team = Team::query()->create(['name' => 'Bench '.Str::random(4), 'team_leader_id' => $leader->id]);
    $team->users()->attach($member->id);

    $assignedElsewhere = caseDetailCase(['assigned_user_id' => $leader->id, 'assigned_team_id' => $team->id, 'assigned_member_user_id' => $leader->id]);
    $own = caseDetailCase(['assigned_user_id' => $leader->id, 'assigned_team_id' => $team->id, 'assigned_member_user_id' => $member->id]);

    $this->actingAs($member)->get(route('court.cases.show', $assignedElsewhere->id))->assertForbidden();

    $this->actingAs($member)->get(route('court.cases.show', $own->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Cases/Show')->where('case.id', $own->id)->etc());
});

test('a status update, a hearing and a witness still post from the page', function (): void {
    $editor = caseDetailUser(['cases.view', 'cases.edit', 'hearing.create', 'hearing.view']);
    $case = caseDetailCase();

    $this->actingAs($editor)
        ->from(route('court.cases.show', $case->id))
        ->patch(route('court.cases.status.update', $case->id), ['status' => 'active', 'note' => 'Opened for hearing'])
        ->assertRedirect(route('court.cases.show', $case->id))
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('court_cases', ['id' => $case->id, 'status' => 'active']);

    $hearingAt = now()->addDays(7)->setTime(10, 0)->format('Y-m-d H:i:s');
    $this->actingAs($editor)
        ->from(route('court.cases.show', $case->id))
        ->post(route('court.cases.hearings.store', $case->id), ['hearing_at' => $hearingAt, 'type' => 'Hearing', 'location' => 'Room 2', 'notes' => 'Bring the file'])
        ->assertRedirect(route('court.cases.show', $case->id))
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('court_case_hearings', ['case_id' => $case->id, 'type' => 'Hearing', 'location' => 'Room 2']);

    $this->actingAs($editor)
        ->from(route('court.cases.show', $case->id))
        ->post(route('court.cases.witnesses.store', $case->id), ['full_name' => 'Kidist Alemu', 'phone' => '0922000000', 'email' => 'kidist@example.com', 'address' => 'Bole'])
        ->assertRedirect(route('court.cases.show', $case->id))
        ->assertSessionHasNoErrors();
    $witnessId = DB::table('court_case_witnesses')->where('case_id', $case->id)->where('full_name', 'Kidist Alemu')->value('id');
    expect($witnessId)->not->toBeNull();

    $this->actingAs($editor)->get(route('court.cases.show', $case->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('case.status', 'active')
            ->where('can.editStatus', true)
            ->has('hearings', 1)
            ->has('witnesses', 1, fn (AssertableInertia $witness) => $witness->where('full_name', 'Kidist Alemu')->where('email', 'kidist@example.com')->etc())
            ->where('timeline.0.to_status', 'active')
            ->etc());

    $this->actingAs($editor)
        ->delete(route('court.cases.witnesses.delete', [$case->id, $witnessId]))
        ->assertRedirect();
    $this->assertDatabaseMissing('court_case_witnesses', ['id' => $witnessId]);
});

test('the assign page renders for an admin with the team leaders to choose from', function (): void {
    $admin = caseDetailUser(['cases.view', 'cases.assign', 'cases.assign.team']);
    $leader = User::factory()->create(['status' => 'active']);
    $team = Team::query()->create(['name' => 'Civil bench', 'team_leader_id' => $leader->id]);
    $case = caseDetailCase();

    $this->actingAs($admin)->get(route('court.cases.assign.form', $case->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Cases/Assign')
            ->where('case.id', $case->id)
            ->where('case.case_number', $case->case_number)
            ->where('case.assigned_user_id', null)
            ->where('assignmentMode', 'admin')
            ->where('canAssignLeaders', true)
            ->where('leaderTeam', null)
            ->where('teams', fn ($teams) => collect($teams)->contains(fn ($row) => $row['id'] === $team->id && $row['leader']['id'] === $leader->id)));

    $this->actingAs($admin)
        ->patch(route('court.cases.assign.update', $case->id), ['assigned_user_id' => $leader->id, 'unassign' => 0])
        ->assertRedirect(route('court.cases.index'));
    $this->assertDatabaseHas('court_cases', ['id' => $case->id, 'assigned_user_id' => $leader->id, 'assigned_team_id' => $team->id]);
});

test('a respondent response carries its response number to the case page', function (): void {
    $viewer = caseDetailUser(['cases.view']);
    $case = caseDetailCase();
    $respondent = Respondent::query()->create([
        'first_name' => 'Ruth', 'middle_name' => 'M', 'last_name' => 'Tadesse', 'gender' => 'female',
        'position' => 'Legal officer', 'organization_name' => 'Example Bureau', 'address' => 'Kirkos',
        'phone' => '09'.random_int(10000000, 99999999), 'email' => 'cd-resp-'.Str::lower(Str::random(8)).'@example.com',
        'password' => Hash::make(Str::random(16)),
    ]);
    RespondentResponse::query()->create([
        'respondent_id' => $respondent->id, 'case_number' => $case->case_number, 'response_number' => 'R/'.$case->case_number,
        'title' => 'Statement of defence', 'description' => '<p>We dispute the claim.</p>', 'review_status' => 'awaiting_review',
    ]);

    $this->actingAs($viewer)->get(route('court.cases.show', $case->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('respondentResponses', 1, fn (AssertableInertia $response) => $response
                ->where('response_number', 'R/'.$case->case_number)
                ->where('title', 'Statement of defence')
                ->where('pdf_url', null)
                ->etc())
            ->etc());
});

test('accepting the review puts the activation on the timeline and names the case in the notice', function (): void {
    $reviewer = caseDetailUser(['cases.view', 'cases.review']);
    $applicant = Applicant::query()->create([
        'first_name' => 'Abebe', 'middle_name' => 'K', 'last_name' => 'Kebede', 'gender' => 'male',
        'phone' => '09'.random_int(10000000, 99999999), 'email' => 'cd-app-'.Str::lower(Str::random(8)).'@example.com',
        'password' => Hash::make(Str::random(16)),
    ]);
    $case = caseDetailCase(['review_status' => 'awaiting_review', 'applicant_id' => $applicant->id]);

    $this->actingAs($reviewer)->patch(route('court.cases.review.update', $case->id), ['decision' => 'accept', 'note' => ''])->assertRedirect();

    expect(DB::table('court_cases')->where('id', $case->id)->value('status'))->toBe('active');
    $this->assertDatabaseHas('court_case_status_logs', ['case_id' => $case->id, 'from_status' => 'pending', 'to_status' => 'active', 'changed_by_user_id' => $reviewer->id]);
    expect((string) DB::table('court_case_messages')->where('case_id', $case->id)->latest('id')->value('body'))
        ->toContain($case->case_number)->not->toContain(':case');
});

test('the audit trail reaches the page as labelled lines with names resolved', function (): void {
    $viewer = caseDetailUser(['cases.view']);
    $judge = User::factory()->create(['name' => 'Judge Almaz']);
    $team = Team::query()->create(['name' => 'Bench One', 'team_leader_id' => $judge->id]);
    $case = caseDetailCase();
    DB::table('court_case_audits')->insert([
        ['case_id' => $case->id, 'action' => 'assigned', 'actor_type' => 'user', 'actor_id' => $viewer->id,
            'meta' => json_encode(['assigned_team_id' => $team->id, 'assigned_user_id' => $judge->id, 'assigned_member_user_id' => null]), 'created_at' => now()],
        ['case_id' => $case->id, 'action' => 'status_updated', 'actor_type' => 'user', 'actor_id' => $viewer->id,
            'meta' => json_encode(['from' => 'pending', 'to' => 'active', 'note' => null]), 'created_at' => now()],
    ]);

    $this->actingAs($viewer)->get(route('court.cases.show', $case->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('audits', fn ($audits) => collect($audits)->firstWhere('action', 'assigned')['details'] === [
                ['label' => __('court::case_audit.fields.team'), 'value' => 'Bench One', 'kind' => 'text'],
                ['label' => __('court::case_audit.fields.judge'), 'value' => 'Judge Almaz', 'kind' => 'text'],
            ] && collect($audits)->firstWhere('action', 'status_updated')['details'] === [
                ['label' => __('court::case_audit.fields.from'), 'value' => __('court::cases.status.pending'), 'kind' => 'text'],
                ['label' => __('court::case_audit.fields.to'), 'value' => __('court::cases.status.active'), 'kind' => 'text'],
            ])
            ->etc());
});
