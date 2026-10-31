<?php

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

test('respondent response of response list and download include accepted records only', function () {
    $suffix = str_replace('.', '', uniqid('respondent-reply', true));

    $viewerApplicant = Applicant::create([
        'first_name' => 'Resp',
        'middle_name' => 'Viewer',
        'last_name' => 'User',
        'gender' => 'male',
        'position' => 'Officer',
        'organization_name' => 'Resp Org',
        'phone' => '0912111' . substr($suffix, -4),
        'email' => "resp-viewer-{$suffix}@example.com",
        'address' => 'Addis Ababa',
        'national_id_number' => '1212121212121212',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);

    $respondent = Respondent::create([
        'first_name' => 'Resp',
        'middle_name' => 'Viewer',
        'last_name' => 'User',
        'gender' => 'male',
        'position' => 'Officer',
        'organization_name' => 'Resp Org',
        'address' => 'Addis Ababa',
        'phone' => '0912222' . substr($suffix, -4),
        'email' => $viewerApplicant->email,
        'password' => Hash::make('password'),
    ]);

    $ownerApplicant = Applicant::create([
        'first_name' => 'AcceptedApplicant',
        'middle_name' => 'A',
        'last_name' => 'One',
        'gender' => 'female',
        'position' => 'Owner',
        'organization_name' => 'Owner Org',
        'phone' => '0912333' . substr($suffix, -4),
        'email' => "owner-accepted-{$suffix}@example.com",
        'address' => 'Adama',
        'national_id_number' => '1313131313131313',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);

    $otherApplicant = Applicant::create([
        'first_name' => 'AwaitingApplicant',
        'middle_name' => 'B',
        'last_name' => 'Two',
        'gender' => 'female',
        'position' => 'Owner',
        'organization_name' => 'Owner Org',
        'phone' => '0912444' . substr($suffix, -4),
        'email' => "owner-awaiting-{$suffix}@example.com",
        'address' => 'Hawassa',
        'national_id_number' => '1414141414141414',
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ]);

    $caseTypeId = DB::table('court_case_types')->insertGetId([
        'name' => 'Civil Respondent Reply ' . $suffix,
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $caseNumber = 'CASE-RSP-' . strtoupper(substr($suffix, -8));
    $caseId = DB::table('court_cases')->insertGetId([
        'applicant_id' => $ownerApplicant->id,
        'case_number' => $caseNumber,
        'title' => 'Respondent reply case',
        'case_type_id' => $caseTypeId,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = RespondentResponse::create([
        'respondent_id' => $respondent->id,
        'case_number' => $caseNumber,
        'response_number' => "\u{1218}/{$caseNumber}",
        'title' => 'Respondent response',
        'description' => 'Response body',
        'pdf_path' => 'respondent/responses/respondent.pdf',
        'review_status' => 'accepted',
    ]);

    Storage::disk('court_private')->put('applicant/response-replies/accepted-download.pdf', 'accepted-file');
    Storage::disk('court_private')->put('applicant/response-replies/awaiting-download.pdf', 'awaiting-file');

    $acceptedReplyId = DB::table('court_applicant_response_replies')->insertGetId([
        'case_id' => $caseId,
        'applicant_id' => $ownerApplicant->id,
        'respondent_response_id' => $response->id,
        'description' => 'Accepted reply body',
        'pdf_path' => 'applicant/response-replies/accepted-download.pdf',
        'review_status' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $awaitingReplyId = DB::table('court_applicant_response_replies')->insertGetId([
        'case_id' => $caseId,
        'applicant_id' => $otherApplicant->id,
        'respondent_response_id' => $response->id,
        'description' => 'Awaiting reply body',
        'pdf_path' => 'applicant/response-replies/awaiting-download.pdf',
        'review_status' => 'awaiting_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('court_respondent_case_views')->insert([
        'respondent_id' => $respondent->id,
        'case_id' => $caseId,
        'case_number' => $caseNumber,
        'viewed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($viewerApplicant, 'applicant')
        ->get(route('court.respondent.response-replies.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Court/Respondent/ResponseReplies/Index')
            ->has('replies.data', 1)
            ->where('replies.data.0.id', $acceptedReplyId)
            ->where('replies.data.0.applicant_name', fn (string $name): bool => str_contains($name, 'AcceptedApplicant')))
        ->assertDontSee('AwaitingApplicant');

    $this->actingAs($viewerApplicant, 'applicant')
        ->get(route('court.respondent.response-replies.download', $acceptedReplyId))
        ->assertOk();

    $this->actingAs($viewerApplicant, 'applicant')
        ->get(route('court.respondent.response-replies.download', $awaitingReplyId))
        ->assertForbidden();
});
