<?php

declare(strict_types=1);

/*
 * The case page's write endpoints (messages, hearings, files, witnesses,
 * status, review, assignment) follow the case page's own rules on the server:
 * the team scope, the closed-case lock and each action's own permission.
 * Before, a scoped team member could act on any case by id, and cases.edit
 * alone reached the hearing, file and message actions.
 */

require_once __DIR__.'/JudicialWorkHelpers.php';

use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

function caseWriteMember(): User
{
    return jwStaff([
        'cases.view', 'cases.edit', 'cases.review', 'message.create',
        'hearing.view', 'hearing.create', 'hearing.update', 'hearing.delete',
        'file.view', 'file.create', 'file.delete',
    ]);
}

function caseWriteHearing(int $caseId, string $when): int
{
    return DB::table('court_case_hearings')->insertGetId([
        'case_id' => $caseId,
        'hearing_at' => $when,
        'type' => 'Mention',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('a plain team member cannot act on a case outside their scope', function (): void {
    $member = caseWriteMember();
    jwTeam($member);
    $mine = jwCase(['assigned_member_user_id' => $member->id]);
    $other = jwCase();
    $nextWeek = now()->addWeek()->setTime(9, 0)->format('Y-m-d H:i:s');
    $otherHearing = caseWriteHearing($other->id, $nextWeek);
    $otherFile = DB::table('court_case_files')->insertGetId([
        'case_id' => $other->id, 'label' => 'Exhibit', 'path' => 'case_files/x.pdf', 'mime' => 'application/pdf', 'size' => 10,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherWitness = DB::table('court_case_witnesses')->insertGetId([
        'case_id' => $other->id, 'full_name' => 'Marta Tesfaye', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($member)->post(route('court.cases.messages.post', $other->id), ['body' => 'Not my case'])->assertForbidden();
    $this->actingAs($member)->post(route('court.cases.hearings.store', $other->id), ['hearing_at' => $nextWeek, 'type' => 'Mention'])->assertForbidden();
    $this->actingAs($member)->patch(route('court.cases.hearings.update', $otherHearing), ['hearing_at' => $nextWeek, 'type' => 'Changed'])->assertForbidden();
    $this->actingAs($member)->delete(route('court.cases.hearings.delete', $otherHearing))->assertForbidden();
    $this->actingAs($member)->post(route('court.cases.files.upload', $other->id), ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertForbidden();
    $this->actingAs($member)->delete(route('court.cases.files.delete', [$other->id, $otherFile]))->assertForbidden();
    $this->actingAs($member)->post(route('court.cases.witnesses.store', $other->id), ['full_name' => 'New witness'])->assertForbidden();
    $this->actingAs($member)->patch(route('court.cases.witnesses.update', [$other->id, $otherWitness]), ['full_name' => 'Renamed'])->assertForbidden();
    $this->actingAs($member)->delete(route('court.cases.witnesses.delete', [$other->id, $otherWitness]))->assertForbidden();
    $this->actingAs($member)->patch(route('court.cases.status.update', $other->id), ['status' => 'dismissed'])->assertForbidden();
    $this->actingAs($member)->patch(route('court.cases.review.update', $other->id), ['decision' => 'return', 'note' => 'Attach the ID'])->assertForbidden();

    expect(DB::table('court_case_messages')->count())->toBe(0)
        ->and(DB::table('court_case_hearings')->where('id', $otherHearing)->value('type'))->toBe('Mention')
        ->and(DB::table('court_case_hearings')->count())->toBe(1)
        ->and(DB::table('court_case_files')->where('id', $otherFile)->exists())->toBeTrue()
        ->and(DB::table('court_case_witnesses')->where('id', $otherWitness)->value('full_name'))->toBe('Marta Tesfaye')
        ->and(DB::table('court_case_witnesses')->count())->toBe(1)
        ->and(DB::table('court_cases')->where('id', $other->id)->value('status'))->toBe('active')
        ->and(DB::table('court_cases')->where('id', $other->id)->value('review_status'))->toBe('accepted');

    // Their own case still takes the same actions.
    $this->actingAs($member)->from(route('court.cases.show', $mine->id))
        ->post(route('court.cases.messages.post', $mine->id), ['body' => 'My case'])
        ->assertRedirect(route('court.cases.show', $mine->id));
    $this->actingAs($member)->from(route('court.cases.show', $mine->id))
        ->post(route('court.cases.witnesses.store', $mine->id), ['full_name' => 'My witness'])
        ->assertRedirect(route('court.cases.show', $mine->id));

    expect(DB::table('court_case_messages')->pluck('body')->all())->toBe(['My case'])
        ->and(DB::table('court_case_witnesses')->where('case_id', $mine->id)->value('full_name'))->toBe('My witness');
});

test('a team leader cannot assign a case outside their team', function (): void {
    $leader = jwStaff(['cases.view', 'cases.assign', 'cases.assign.member']);
    $member = User::factory()->create(['status' => 'active']);
    $team = Team::query()->create(['name' => 'Bench A', 'team_leader_id' => $leader->id]);
    $team->users()->attach([$leader->id, $member->id]);
    $ours = jwCase(['assigned_team_id' => $team->id]);
    $theirs = jwCase();

    $this->actingAs($leader)->get(route('court.cases.assign.form', $theirs->id))->assertForbidden();
    $this->actingAs($leader)->patch(route('court.cases.assign.update', $theirs->id), ['assigned_user_id' => $member->id])->assertForbidden();
    expect(DB::table('court_cases')->where('id', $theirs->id)->value('assigned_user_id'))->toBeNull();

    $this->actingAs($leader)->get(route('court.cases.assign.form', $ours->id))->assertOk();
    $this->actingAs($leader)->patch(route('court.cases.assign.update', $ours->id), ['assigned_user_id' => $member->id])
        ->assertRedirect(route('court.cases.index'));
    expect((int) DB::table('court_cases')->where('id', $ours->id)->value('assigned_member_user_id'))->toBe($member->id);
});

test('case actions need their own permission, not just cases.edit', function (): void {
    $editor = jwStaff(['cases.view', 'cases.edit']);
    $case = jwCase();
    $nextWeek = now()->addWeek()->setTime(9, 0)->format('Y-m-d H:i:s');
    $hearing = caseWriteHearing($case->id, $nextWeek);
    $file = DB::table('court_case_files')->insertGetId([
        'case_id' => $case->id, 'label' => 'Exhibit', 'path' => 'case_files/x.pdf', 'mime' => 'application/pdf', 'size' => 10,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($editor)->post(route('court.cases.messages.post', $case->id), ['body' => 'Hi'])->assertForbidden();
    $this->actingAs($editor)->post(route('court.cases.hearings.store', $case->id), ['hearing_at' => now()->addDays(10)->format('Y-m-d H:i:s')])->assertForbidden();
    $this->actingAs($editor)->patch(route('court.cases.hearings.update', $hearing), ['hearing_at' => $nextWeek, 'type' => 'Changed'])->assertForbidden();
    $this->actingAs($editor)->delete(route('court.cases.hearings.delete', $hearing))->assertForbidden();
    $this->actingAs($editor)->post(route('court.cases.files.upload', $case->id), ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertForbidden();
    $this->actingAs($editor)->delete(route('court.cases.files.delete', [$case->id, $file]))->assertForbidden();

    expect(DB::table('court_case_messages')->count())->toBe(0)
        ->and(DB::table('court_case_hearings')->count())->toBe(1)
        ->and(DB::table('court_case_hearings')->where('id', $hearing)->value('type'))->toBe('Mention')
        ->and(DB::table('court_case_files')->count())->toBe(1);
});

test('a closed case with a published decision accepts no further changes', function (): void {
    $clerk = caseWriteMember();
    $case = jwCase(['status' => 'closed']);
    DB::table('court_decisions')->insert([
        'court_case_id' => $case->id,
        'case_number' => $case->case_number,
        'name' => 'Judgment',
        'decision_content' => '<p>Final</p>',
        'status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $witness = DB::table('court_case_witnesses')->insertGetId([
        'case_id' => $case->id, 'full_name' => 'Marta Tesfaye', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $show = route('court.cases.show', $case->id);

    $this->actingAs($clerk)->from($show)->post(route('court.cases.messages.post', $case->id), ['body' => 'Too late'])
        ->assertRedirect($show)->assertSessionHas('error', __('court::cases.show.actions_locked'));
    $this->actingAs($clerk)->from($show)->post(route('court.cases.hearings.store', $case->id), ['hearing_at' => now()->addWeek()->format('Y-m-d H:i:s')])
        ->assertRedirect($show)->assertSessionHas('error', __('court::cases.show.actions_locked'));
    $this->actingAs($clerk)->from($show)->post(route('court.cases.witnesses.store', $case->id), ['full_name' => 'Late witness'])
        ->assertRedirect($show)->assertSessionHas('error', __('court::cases.show.actions_locked'));
    $this->actingAs($clerk)->from($show)->delete(route('court.cases.witnesses.delete', [$case->id, $witness]))
        ->assertRedirect($show)->assertSessionHas('error', __('court::cases.show.actions_locked'));
    $this->actingAs($clerk)->from($show)->post(route('court.cases.files.upload', $case->id), ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
        ->assertRedirect($show)->assertSessionHas('error', __('court::cases.show.actions_locked'));

    expect(DB::table('court_case_messages')->count())->toBe(0)
        ->and(DB::table('court_case_hearings')->count())->toBe(0)
        ->and(DB::table('court_case_files')->count())->toBe(0)
        ->and(DB::table('court_case_witnesses')->pluck('full_name')->all())->toBe(['Marta Tesfaye']);
});
