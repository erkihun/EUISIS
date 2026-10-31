<?php

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

test('applicant can create a response of response with pdf attachment', function () {
    $applicant = Applicant::create([
        'first_name' => 'Abel',
        'middle_name' => 'K',
        'last_name' => 'Teka',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '0911998877',
        'email' => 'abel-reply@example.com',
        'address' => 'Addis Ababa',
        'national_id_number' => '1111222233334444',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    $caseTypeId = DB::table('court_case_types')->insertGetId([
        'name' => 'Civil',
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $caseId = DB::table('court_cases')->insertGetId([
        'applicant_id' => $applicant->id,
        'case_number' => 'CASE-REPLY-100',
        'title' => 'Reply test case',
        'case_type_id' => $caseTypeId,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $respondent = Respondent::create([
        'first_name' => 'Ruth',
        'middle_name' => 'M',
        'last_name' => 'Bekele',
        'gender' => 'female',
        'position' => 'Officer',
        'organization_name' => 'Example Org',
        'address' => 'Adama',
        'phone' => '0911998878',
        'email' => 'ruth-reply@example.com',
        'password' => Hash::make('password'),
    ]);

    $response = RespondentResponse::create([
        'respondent_id' => $respondent->id,
        'case_number' => 'CASE-REPLY-100',
        'response_number' => "\u{1218}/CASE-REPLY-100",
        'title' => 'Respondent reply',
        'description' => 'Respondent description',
        'pdf_path' => 'respondent/responses/original.pdf',
        'review_status' => 'accepted',
    ]);

    $file = UploadedFile::fake()->create('reply.pdf', 256, 'application/pdf');

    $this->actingAs($applicant, 'applicant')
        ->post(route('court.applicant.cases.respondentResponses.replies.store', [$caseId, $response->id]), [
            'description' => 'Applicant reply to respondent response.',
            'pdf' => $file,
        ])
        ->assertRedirect();

    $reply = DB::table('court_applicant_response_replies')
        ->where('case_id', $caseId)
        ->where('applicant_id', $applicant->id)
        ->where('respondent_response_id', $response->id)
        ->first();

    expect($reply)->not->toBeNull()
        ->and($reply->description)->toBe('Applicant reply to respondent response.');

    expect(Storage::disk('court_private')->exists($reply->pdf_path))->toBeTrue();

    Storage::disk('court_private')->delete($reply->pdf_path);
});

test('applicant cannot edit or delete accepted response of response', function () {
    $suffix = str_replace('.', '', uniqid('accepted-lock', true));

    $applicant = Applicant::create([
        'first_name' => 'Lock',
        'middle_name' => 'K',
        'last_name' => 'Owner',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '0911444'.substr($suffix, -4),
        'email' => "lock-owner-{$suffix}@example.com",
        'address' => 'Addis Ababa',
        'national_id_number' => '1111222233334455',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    $caseTypeId = DB::table('court_case_types')->insertGetId([
        'name' => 'Civil Lock '.$suffix,
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $caseId = DB::table('court_cases')->insertGetId([
        'applicant_id' => $applicant->id,
        'case_number' => 'CASE-LOCK-'.strtoupper(substr($suffix, -8)),
        'title' => 'Lock test case',
        'case_type_id' => $caseTypeId,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $respondent = Respondent::create([
        'first_name' => 'Resp',
        'middle_name' => 'M',
        'last_name' => 'Lock',
        'gender' => 'male',
        'position' => 'Officer',
        'organization_name' => 'Example Org',
        'address' => 'Adama',
        'phone' => '0911555'.substr($suffix, -4),
        'email' => "resp-lock-{$suffix}@example.com",
        'password' => Hash::make('password'),
    ]);

    $response = RespondentResponse::create([
        'respondent_id' => $respondent->id,
        'case_number' => DB::table('court_cases')->where('id', $caseId)->value('case_number'),
        'response_number' => "\u{1218}/CASE-LOCK-".strtoupper(substr($suffix, -8)),
        'title' => 'Respondent reply',
        'description' => 'Respondent description',
        'pdf_path' => 'respondent/responses/original-lock.pdf',
        'review_status' => 'accepted',
    ]);

    Storage::disk('court_private')->put('applicant/response-replies/accepted-lock.pdf', 'test-pdf');

    $replyId = DB::table('court_applicant_response_replies')->insertGetId([
        'case_id' => $caseId,
        'applicant_id' => $applicant->id,
        'respondent_response_id' => $response->id,
        'description' => 'Accepted reply',
        'pdf_path' => 'applicant/response-replies/accepted-lock.pdf',
        'review_status' => 'accepted',
        'review_note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($applicant, 'applicant')
        ->patch(route('court.applicant.cases.respondentResponses.replies.update', [$caseId, $response->id, $replyId]), [
            'description' => 'Updated',
        ])
        ->assertForbidden();

    $this->actingAs($applicant, 'applicant')
        ->delete(route('court.applicant.cases.respondentResponses.replies.destroy', [$caseId, $response->id, $replyId]))
        ->assertForbidden();
});

test('updating returned response of response resets review status to awaiting review', function () {
    $suffix = str_replace('.', '', uniqid('returned-reply', true));

    $applicant = Applicant::create([
        'first_name' => 'Returned',
        'middle_name' => 'K',
        'last_name' => 'Owner',
        'gender' => 'male',
        'position' => 'Manager',
        'organization_name' => 'ACME',
        'phone' => '0911777'.substr($suffix, -4),
        'email' => "returned-owner-{$suffix}@example.com",
        'address' => 'Addis Ababa',
        'national_id_number' => '1111222233334466',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    $caseTypeId = DB::table('court_case_types')->insertGetId([
        'name' => 'Civil Returned '.$suffix,
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $caseNumber = 'CASE-RET-'.strtoupper(substr($suffix, -8));
    $caseId = DB::table('court_cases')->insertGetId([
        'applicant_id' => $applicant->id,
        'case_number' => $caseNumber,
        'title' => 'Returned test case',
        'case_type_id' => $caseTypeId,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $respondent = Respondent::create([
        'first_name' => 'Resp',
        'middle_name' => 'M',
        'last_name' => 'Return',
        'gender' => 'male',
        'position' => 'Officer',
        'organization_name' => 'Example Org',
        'address' => 'Adama',
        'phone' => '0911888'.substr($suffix, -4),
        'email' => "resp-return-{$suffix}@example.com",
        'password' => Hash::make('password'),
    ]);

    $response = RespondentResponse::create([
        'respondent_id' => $respondent->id,
        'case_number' => $caseNumber,
        'response_number' => "\u{1218}/{$caseNumber}",
        'title' => 'Respondent reply',
        'description' => 'Respondent description',
        'pdf_path' => 'respondent/responses/original-return.pdf',
        'review_status' => 'accepted',
    ]);

    Storage::disk('court_private')->put('applicant/response-replies/returned.pdf', 'test-pdf');

    $replyId = DB::table('court_applicant_response_replies')->insertGetId([
        'case_id' => $caseId,
        'applicant_id' => $applicant->id,
        'respondent_response_id' => $response->id,
        'description' => 'Needs correction',
        'pdf_path' => 'applicant/response-replies/returned.pdf',
        'review_status' => 'returned',
        'review_note' => 'Please revise.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($applicant, 'applicant')
        ->patch(route('court.applicant.cases.respondentResponses.replies.update', [$caseId, $response->id, $replyId]), [
            'description' => 'Updated after correction',
        ])
        ->assertRedirect(route('court.applicant.cases.respondentResponses.replies.show', [$caseId, $response->id, $replyId]));

    $reply = DB::table('court_applicant_response_replies')->where('id', $replyId)->first();

    expect($reply)->not->toBeNull()
        ->and($reply->description)->toBe('Updated after correction')
        ->and($reply->review_status)->toBe('awaiting_review')
        ->and($reply->review_note)->toBeNull();
});
