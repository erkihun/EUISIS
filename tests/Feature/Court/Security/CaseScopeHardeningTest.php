<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\RespondentResponse;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/JudicialWorkHelpers.php';

/*
| Endpoints outside #3's list that read or change a case by id follow the same
| team scope (Modules\Court\Application\Support\CaseScope): case file and response downloads, the
| EUISIS links on the case page, and the case page itself, which also applies
| the review policy the case list and the record page apply.
*/

test('a plain team member cannot download files of a case outside their scope', function (): void {
    $member = jwStaff(['cases.view']);
    jwTeam($member);
    $other = jwCase();
    $fileId = DB::table('court_case_files')->insertGetId(['case_id' => $other->id, 'label' => 'Deed', 'path' => 'case_files/deed.pdf', 'mime' => 'application/pdf', 'size' => 10, 'created_at' => now(), 'updated_at' => now()]);
    $respondent = \Modules\Court\Domain\Models\Respondent::create(['first_name' => 'R', 'last_name' => 'One', 'phone' => '09'.random_int(10000000, 99999999), 'email' => 'scope-'.uniqid().'@example.com', 'password' => 'x']);
    $response = RespondentResponse::create(['respondent_id' => $respondent->id,'case_number' => $other->case_number, 'title' => 'Answer', 'pdf_path' => 'respondent/responses/x.pdf']);

    $this->actingAs($member)->get(route('court.cases.files.download', [$other->id, $fileId]))->assertForbidden();
    $this->actingAs($member)->get(route('court.respondent-responses.download', $response->id))->assertForbidden();

    // Within scope the request gets past the check (the file itself is not on disk here).
    $mine = jwCase(['assigned_member_user_id' => $member->id]);
    $mineFile = DB::table('court_case_files')->insertGetId(['case_id' => $mine->id, 'label' => 'Own', 'path' => 'case_files/own.pdf', 'mime' => 'application/pdf', 'size' => 10, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member)->get(route('court.cases.files.download', [$mine->id, $mineFile]))->assertNotFound();
});

test('a plain team member cannot link a case outside their scope to EUISIS', function (): void {
    $member = jwStaff(['cases.view', 'cases.edit']);
    jwTeam($member);
    $other = jwCase();

    $this->actingAs($member)->patch(route('court.cases.euisis.organization', $other->id), ['organization_uuid' => (string) \Illuminate\Support\Str::uuid()])
        ->assertForbidden();
});

test('the case page opens a case awaiting review only for reviewers and its assignee', function (): void {
    $viewer = jwStaff(['cases.view']);
    $reviewer = jwStaff(['cases.view', 'cases.review']);
    $assignee = jwStaff(['cases.view']);
    $case = jwCase(['review_status' => 'awaiting_review', 'assigned_user_id' => $assignee->id]);

    $this->actingAs($viewer)->get(route('court.cases.show', $case->id))->assertForbidden();
    $this->actingAs($reviewer)->get(route('court.cases.show', $case->id))->assertOk();
    $this->actingAs($assignee)->get(route('court.cases.show', $case->id))->assertOk();
});
