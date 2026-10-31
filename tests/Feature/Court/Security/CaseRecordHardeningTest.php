<?php

declare(strict_types=1);

/*
 * The case record page and its two PDFs show the whole case file, so they
 * follow the case page's rules: the case policy (a case awaiting review only
 * for reviewers and the assignee) and the team scope of the record list.
 * Before, any cases.view user could open any case's record by id. The case
 * page's submitted-document link follows the same team scope.
 */

require_once __DIR__.'/JudicialWorkHelpers.php';

use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\DB;

test('a case awaiting review is not open to a plain viewer through the record or its PDFs', function (): void {
    $viewer = jwStaff(['cases.view']);
    $reviewer = jwStaff(['cases.view', 'cases.review']);
    $case = jwCase(['review_status' => 'awaiting_review', 'status' => 'closed']);

    $this->actingAs($viewer)->get(route('court.recordes.show', $case->id))->assertForbidden();
    $this->actingAs($viewer)->get(route('court.recordes.pdf', $case->id))->assertForbidden();
    $this->actingAs($viewer)->get(route('court.recordes.appeal-pdf', $case->id))->assertForbidden();

    $this->actingAs($reviewer)->get(route('court.recordes.show', $case->id))->assertOk();
});

test('a plain team member only opens the records of the cases assigned to them', function (): void {
    $member = jwStaff(['cases.view']);
    jwTeam($member);
    $mine = jwCase(['assigned_member_user_id' => $member->id]);
    $other = jwCase(['status' => 'closed']);

    $this->actingAs($member)->get(route('court.recordes.show', $other->id))->assertForbidden();
    $this->actingAs($member)->get(route('court.recordes.pdf', $other->id))->assertForbidden();
    $this->actingAs($member)->get(route('court.recordes.appeal-pdf', $other->id))->assertForbidden();

    $this->actingAs($member)->get(route('court.recordes.show', $mine->id))->assertOk();
    $this->actingAs($member)->get(route('court.recordes.pdf', $mine->id))->assertOk()->assertSee($mine->case_number);
});

test('a plain team member cannot open a submitted document of a case outside their scope', function (): void {
    $member = jwStaff(['cases.view']);
    jwTeam($member);
    $other = jwCase();
    $docId = DB::table('court_case_evidences')->insertGetId([
        'case_id' => $other->id, 'type' => 'document', 'title' => 'Deed', 'file_path' => 'case_evidences/deed.pdf',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($member)->get(route('court.cases.documents.view', [$other->id, $docId]))->assertForbidden();
});

test('a team leader only opens the records of their team', function (): void {
    $leader = jwStaff(['cases.view', 'cases.assign.member']);
    $member = User::factory()->create(['status' => 'active']);
    $team = Team::query()->create(['name' => 'Bench R', 'team_leader_id' => $leader->id]);
    $team->users()->attach([$leader->id, $member->id]);
    $ours = jwCase(['assigned_user_id' => $member->id]);
    $theirs = jwCase();

    $this->actingAs($leader)->get(route('court.recordes.show', $theirs->id))->assertForbidden();
    $this->actingAs($leader)->get(route('court.recordes.pdf', $theirs->id))->assertForbidden();

    $this->actingAs($leader)->get(route('court.recordes.show', $ours->id))->assertOk();
});
