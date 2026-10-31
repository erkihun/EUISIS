<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\ApplicantResponseReply;
use Modules\Court\Domain\Models\IntegrationClient;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Flow 6 (docs/euisis-integration.md): the employee works on their own cases
| from the EUISIS employee portal. court-ms runs its applicant portal actions
| for the linked court account, so the same rules apply.
*/

/** @return array{0: string, 1: array<string, mixed>, 2: int} token, employee, case id */
function workspaceCase(object $test): array
{
    $client = new IntegrationClient(['name' => 'EUISIS-'.Str::random(6), 'abilities' => IntegrationClient::DEFAULT_ABILITIES, 'allowed_ips' => []]);
    $token = $client->issueToken();
    $type = DB::table('court_case_types')->insertGetId(['name' => 'Labour '.Str::random(4), 'prefix' => 'LAB', 'created_at' => now(), 'updated_at' => now()]);
    $employee = [
        'uuid' => (string) Str::uuid(), 'national_id' => (string) random_int(1000000000000000, 9999999999999999),
        'first_name' => 'Meron', 'middle_name' => 'A', 'last_name' => 'Haile', 'gender' => 'female',
        'email' => 'ws-'.Str::lower(Str::random(6)).'@example.com', 'phone' => '09'.random_int(10000000, 99999999),
    ];
    $caseId = $test->withToken($token)->postJson('/court/api/v1/integration/people/cases', [
        'employee' => $employee, 'filing_id' => (string) Str::uuid(), 'case_type_id' => $type,
        'description' => 'Transferred without notice.', 'relief_requested' => 'Reinstatement.',
        'respondent' => ['organization_uuid' => (string) Str::uuid(), 'name' => 'Bureau of Finance'],
    ])->assertCreated()->json('data.case_id');

    return [$token, $employee, (int) $caseId];
}

beforeEach(function (): void {
    Storage::fake('court_private');
    Mail::fake();
});

test('the workspace shows the employee their case, and nobody else gets it', function (): void {
    [$token, $employee, $caseId] = workspaceCase($this);

    $data = $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/workspace", ['employee_uuid' => $employee['uuid']])
        ->assertOk()->json('data');
    expect($data['case']['id'])->toBe($caseId)
        ->and($data['can']['modify'])->toBeTrue()
        ->and($data['review']['status'])->toBe('awaiting_review')
        ->and($data)->toHaveKeys(['hearings', 'docs', 'messages', 'files', 'respondentResponses', 'letters', 'decisions'])
        ->and($data)->not->toHaveKey('audits');

    // Another employee's case, an unknown employee, and a token without the people abilities.
    [, $other, $otherCase] = workspaceCase($this);
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$otherCase}/workspace", ['employee_uuid' => $employee['uuid']])->assertNotFound();
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/workspace", ['employee_uuid' => (string) Str::uuid()])
        ->assertNotFound()->assertJsonPath('error_code', 'not_linked');
    $readOnly = (new IntegrationClient(['name' => 'RO-'.Str::random(5), 'abilities' => [IntegrationClient::ABILITY_PEOPLE_READ], 'allowed_ips' => []]))->issueToken();
    $this->withToken($readOnly)->postJson("/court/api/v1/integration/people/cases/{$caseId}/messages", ['employee_uuid' => $employee['uuid'], 'body' => 'Hi'])
        ->assertStatus(403)->assertJsonPath('error_code', 'ability_missing');
});

test('the employee writes to the court and adds, downloads and removes a document', function (): void {
    [$token, $employee, $caseId] = workspaceCase($this);
    $as = ['employee_uuid' => $employee['uuid']];

    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/messages", [...$as, 'body' => 'When is the hearing?'])
        ->assertOk()->assertJsonPath('data.ok', true);
    expect(DB::table('court_case_messages')->where('case_id', $caseId)->value('body'))->toBe('When is the hearing?');
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/messages", [...$as, 'body' => ''])->assertStatus(422);

    $this->withToken($token)->post("/court/api/v1/integration/people/cases/{$caseId}/files", [...$as, 'label' => 'Payslip', 'file' => UploadedFile::fake()->createWithContent('payslip.pdf', "%PDF-1.4\n%%EOF\n")], ['Accept' => 'application/json'])
        ->assertOk();
    $fileId = (int) DB::table('court_case_files')->where('case_id', $caseId)->value('id');

    $workspace = $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/workspace", $as)->json('data');
    expect(collect($workspace['files'])->firstWhere('id', $fileId)['can_delete'])->toBeTrue()
        ->and($workspace['messages'][0]['mine'])->toBeTrue();

    $download = $this->withToken($token)->post("/court/api/v1/integration/people/cases/{$caseId}/download", [...$as, 'kind' => 'file', 'id' => $fileId])->assertOk();
    expect($download->streamedContent())->toStartWith('%PDF');
    $this->withToken($token)->post("/court/api/v1/integration/people/cases/{$caseId}/download", [...$as, 'kind' => 'receipt'])->assertOk()->assertHeader('content-type', 'application/pdf');

    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/files/{$fileId}/delete", $as)->assertOk();
    expect(DB::table('court_case_files')->where('id', $fileId)->exists())->toBeFalse();

    // A mail server that refuses does not turn a posted message into an error, nor skip its audit.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('530 Authentication Required'));
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/messages", [...$as, 'body' => 'Second message'])
        ->assertOk()->assertJsonPath('data.ok', true);
    expect(DB::table('court_case_audits')->where('case_id', $caseId)->where('action', 'message_posted')->count())->toBe(2);
});

test('a case sent back is corrected from EUISIS and goes back to review', function (): void {
    [$token, $employee, $caseId] = workspaceCase($this);
    DB::table('court_cases')->where('id', $caseId)->update(['review_status' => 'returned', 'review_note' => 'Add the transfer order.']);
    $as = ['employee_uuid' => $employee['uuid']];

    $form = $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/edit", $as)->assertOk()->json('data');
    expect($form)->toHaveKey('case');
    $case = DB::table('court_cases')->where('id', $caseId)->first();

    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/correct", [...$as, 'title' => $case->title, 'description' => 'Transferred without notice; order attached.'])
        ->assertStatus(422);
    $this->withToken($token)->post("/court/api/v1/integration/people/cases/{$caseId}/correct", [
        ...$as, 'title' => $case->title, 'case_type_id' => $case->case_type_id, 'filing_date' => $case->filing_date,
        'description' => 'Transferred without notice; order attached.', 'relief_requested' => 'Reinstatement.',
        'respondent_name' => $case->respondent_name, 'certify_appeal' => '1', 'certify_evidence' => '1',
        'evidence_titles' => ['Transfer order'], 'evidence_files' => [UploadedFile::fake()->createWithContent('order.pdf', "%PDF-1.4\n%%EOF\n")],
    ], ['Accept' => 'application/json'])->assertOk();

    $case = DB::table('court_cases')->where('id', $caseId)->first();
    expect($case->description)->toContain('order attached')
        ->and($case->review_status)->not->toBe('returned');
});

test('the employee reads a respondent response and replies to it', function (): void {
    [$token, $employee, $caseId] = workspaceCase($this);
    $as = ['employee_uuid' => $employee['uuid']];
    $caseNumber = DB::table('court_cases')->where('id', $caseId)->value('case_number');
    $respondent = Respondent::create([
        'first_name' => 'Ruth', 'middle_name' => 'M', 'last_name' => 'Bekele', 'gender' => 'female', 'position' => 'Officer',
        'organization_name' => 'Bureau of Finance', 'address' => 'Addis Ababa', 'phone' => '09'.random_int(10000000, 99999999),
        'email' => 'ruth-'.Str::lower(Str::random(5)).'@example.com', 'password' => Hash::make('password'),
    ]);
    Storage::disk('court_private')->put('respondent/responses/defence.pdf', "%PDF-1.4\n%%EOF\n");
    $response = RespondentResponse::create([
        'respondent_id' => $respondent->id, 'case_number' => $caseNumber, 'response_number' => 'R/'.$caseNumber,
        'title' => 'Statement of defence', 'description' => '<p>We dispute the claim.</p>', 'pdf_path' => 'respondent/responses/defence.pdf', 'review_status' => 'accepted',
    ]);

    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/responses/{$response->id}", $as)
        ->assertOk()->assertJsonPath('data.response.title', 'Statement of defence');
    $this->withToken($token)->post("/court/api/v1/integration/people/cases/{$caseId}/download", [...$as, 'kind' => 'response', 'id' => $response->id])->assertOk();

    $this->withToken($token)->post("/court/api/v1/integration/people/cases/{$caseId}/responses/{$response->id}/replies", [
        ...$as, 'description' => 'The transfer order was never served.', 'pdf' => UploadedFile::fake()->createWithContent('reply.pdf', "%PDF-1.4\n%%EOF\n"),
    ], ['Accept' => 'application/json'])->assertOk();
    $reply = ApplicantResponseReply::query()->where('respondent_response_id', $response->id)->sole();
    expect($reply->review_status)->toBe('awaiting_review');

    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/responses/{$response->id}/replies/{$reply->id}", $as)->assertOk();
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/responses/{$response->id}/replies/{$reply->id}/delete", $as)->assertOk();
    expect(ApplicantResponseReply::query()->whereKey($reply->id)->exists())->toBeFalse();
});

test('the employee reads their court notices and marks them read', function (): void {
    [$token, $employee] = workspaceCase($this);
    $as = ['employee_uuid' => $employee['uuid']];

    $feed = $this->withToken($token)->postJson('/court/api/v1/integration/people/notices', $as)->assertOk()->json('data');
    expect($feed)->toHaveKeys(['items', 'unread_count']);
    if ($feed['items'] !== []) {
        $this->withToken($token)->postJson('/court/api/v1/integration/people/notices/read', [...$as, 'id' => $feed['items'][0]['id']])->assertOk();
    }
    $this->withToken($token)->postJson('/court/api/v1/integration/people/notices/read', [...$as, 'all' => true])->assertOk();
    expect($this->withToken($token)->postJson('/court/api/v1/integration/people/notices', $as)->json('data.unread_count'))->toBe(0);
});
