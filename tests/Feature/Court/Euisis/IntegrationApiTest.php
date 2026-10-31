<?php

use Modules\Court\Domain\Models\EuisisReferral;
use Modules\Court\Domain\Models\IntegrationClient;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// The EUISIS integration API (docs/euisis-integration.md, Flows 2 and 3).

function euisisApiClient(array $abilities = [IntegrationClient::ABILITY_REFERRALS_CREATE, IntegrationClient::ABILITY_REFERRALS_READ], array $ips = []): array
{
    $client = new IntegrationClient(['name' => 'EUISIS-'.Str::random(6), 'abilities' => $abilities, 'allowed_ips' => $ips]);
    $token = $client->issueToken();

    return [$client, $token];
}

function euisisApiCaseType(): int
{
    return DB::table('court_case_types')->insertGetId(['name' => 'Administrative', 'prefix' => 'ADM', 'created_at' => now(), 'updated_at' => now()]);
}

function euisisApiReferral(int $caseTypeId, array $overrides = []): array
{
    return array_replace_recursive([
        'referral_id' => (string) Str::uuid(),
        'grievance_reference' => 'GRV-2610-0007',
        'tribunal_case_number' => 'TRB-GRV-2610-0007',
        'case_type_id' => $caseTypeId,
        'title' => 'Transfer decision contested',
        'summary' => "The complainant contests a transfer decision.\n\nThe committee upheld it.",
        'complainant' => ['name' => 'Abebe Kebede', 'employee_uuid' => (string) Str::uuid(), 'phone' => '0911000000'],
        'respondent' => ['name' => 'Bole Sub-city Administration', 'organization_uuid' => (string) Str::uuid()],
        'referring_officer' => 'Grievance officer',
    ], $overrides);
}

test('the integration API refuses a missing, unknown, revoked or under-privileged token and a disallowed address', function () {
    $type = euisisApiCaseType();

    $this->postJson('/court/api/v1/integration/referrals', euisisApiReferral($type))->assertStatus(401)->assertJsonPath('error_code', 'unauthenticated');
    $this->withToken('cms_not-a-token')->postJson('/court/api/v1/integration/referrals', euisisApiReferral($type))->assertStatus(401);

    [, $readOnly] = euisisApiClient([IntegrationClient::ABILITY_REFERRALS_READ]);
    $this->withToken($readOnly)->postJson('/court/api/v1/integration/referrals', euisisApiReferral($type))->assertStatus(403)->assertJsonPath('error_code', 'ability_missing');

    [, $elsewhere] = euisisApiClient(ips: ['10.9.9.9']);
    $this->withToken($elsewhere)->getJson('/court/api/v1/integration/case-types')->assertStatus(403)->assertJsonPath('error_code', 'ip_not_allowed');

    [$revoked, $revokedToken] = euisisApiClient();
    $revoked->forceFill(['revoked_at' => now()])->save();
    $this->withToken($revokedToken)->getJson('/court/api/v1/integration/case-types')->assertStatus(401);

    expect(DB::table('court_cases')->count())->toBe(0);
});

test('an integration token never opens the person-facing API, and a staff token never opens the integration API', function () {
    [, $token] = euisisApiClient();
    $this->withToken($token)->getJson('/court/api/v1/cases')->assertStatus(401);

    $staff = User::factory()->create();
    $staffToken = $staff->createToken('staff', ['*'])->plainTextToken;
    $this->withToken($staffToken)->getJson('/court/api/v1/integration/case-types')->assertStatus(401);
});

test('a referral opens one case in intake review, with its documents, however often it is sent', function () {
    Storage::fake('court_private');
    $type = euisisApiCaseType();
    [$client, $token] = euisisApiClient();
    $payload = euisisApiReferral($type, ['documents' => [UploadedFile::fake()->create('decision.pdf', 40, 'application/pdf')], 'document_titles' => ['Committee decision']]);

    $first = $this->withToken($token)->post('/court/api/v1/integration/referrals', $payload, ['Accept' => 'application/json']);
    $first->assertStatus(201)
        ->assertJsonPath('data.referral_id', $payload['referral_id'])
        ->assertJsonPath('data.review_status', 'awaiting_review')
        ->assertJsonPath('data.status', 'pending');
    expect($first->json('data.case_number'))->toStartWith('ADM/')->toEndWith('/'.now()->format('y'));

    $case = DB::table('court_cases')->first();
    expect($case->source)->toBe('euisis')
        ->and($case->euisis_referral_id)->toBe($payload['referral_id'])
        ->and($case->respondent_organization_uuid)->toBe($payload['respondent']['organization_uuid'])
        ->and($case->applicant_id)->toBeNull()
        ->and($case->description)->toContain('<p>The complainant contests a transfer decision.</p>');
    expect(DB::table('court_case_evidences')->where('case_id', $case->id)->count())->toBe(1);
    expect(EuisisReferral::query()->first()->integration_client_id)->toBe($client->id);

    $again = $this->withToken($token)->post('/court/api/v1/integration/referrals', euisisApiReferral($type, ['referral_id' => $payload['referral_id']]), ['Accept' => 'application/json']);
    $again->assertStatus(200)->assertJsonPath('data.case_number', $first->json('data.case_number'));
    expect(DB::table('court_cases')->count())->toBe(1);

    $this->withToken($token)->getJson('/court/api/v1/integration/referrals/'.$payload['referral_id'])->assertOk()->assertJsonPath('data.case_id', $case->id);
});

test('a referral that misses required fields or names an unknown case type is refused', function () {
    [, $token] = euisisApiClient();
    $this->withToken($token)->postJson('/court/api/v1/integration/referrals', ['referral_id' => 'not-a-uuid', 'case_type_id' => 999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['referral_id', 'case_type_id', 'title', 'summary', 'complainant', 'respondent']);
});

test('the update list reports intake review, hearings and the published decision, each once, after the cursor', function () {
    $type = euisisApiCaseType();
    [, $token] = euisisApiClient();
    $payload = euisisApiReferral($type);
    $this->withToken($token)->postJson('/court/api/v1/integration/referrals', $payload)->assertStatus(201);
    $caseId = (int) DB::table('court_cases')->value('id');

    // Nothing has changed since the referral answer.
    $this->withToken($token)->getJson('/court/api/v1/integration/referrals/updates')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('next_cursor', 0);

    // The registrar returns it with a reason, the way the admin screen writes it.
    DB::table('court_cases')->where('id', $caseId)->update(['review_status' => 'returned', 'review_note' => 'Attach the committee minutes.']);
    $returned = $this->withToken($token)->getJson('/court/api/v1/integration/referrals/updates')->assertOk();
    $returned->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.referral_id', $payload['referral_id'])
        ->assertJsonPath('data.0.review_status', 'returned')
        ->assertJsonPath('data.0.review_reason', 'Attach the committee minutes.');
    $cursor = $returned->json('next_cursor');

    // Accepted, a hearing set, then a decision published.
    DB::table('court_cases')->where('id', $caseId)->update(['review_status' => 'accepted', 'status' => 'active']);
    DB::table('court_case_hearings')->insert(['case_id' => $caseId, 'hearing_at' => now()->addDays(10)->setTime(9, 30), 'created_at' => now(), 'updated_at' => now()]);
    $hearing = $this->withToken($token)->getJson('/court/api/v1/integration/referrals/updates?since='.$cursor)->assertOk();
    $hearing->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.review_status', 'accepted')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.0.hearing_at', now()->addDays(10)->format('Y-m-d').' 09:30:00')
        ->assertJsonPath('data.0.review_reason', null);
    expect($hearing->json('data.0.changed'))->toContain('review_status', 'status', 'hearing_at');

    DB::table('court_decisions')->insert([
        'court_case_id' => $caseId, 'case_number' => 'x', 'decision_date' => now()->toDateString(), 'name' => 'Decision',
        'description' => '<p>The transfer is set aside.</p>', 'decision_content' => '<p>Full text</p>', 'status' => 'published',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $decided = $this->withToken($token)->getJson('/court/api/v1/integration/referrals/updates?since='.$hearing->json('next_cursor'))->assertOk();
    $decided->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.decision_date', now()->toDateString())
        ->assertJsonPath('data.0.decision_summary', 'The transfer is set aside.');

    // Paging: everything from the start, one at a time.
    $page = $this->withToken($token)->getJson('/court/api/v1/integration/referrals/updates?limit=1')->assertOk();
    $page->assertJsonCount(1, 'data')->assertJsonPath('has_more', true);
});

test('changes to cases that did not come from EUISIS are never listed', function () {
    $type = euisisApiCaseType();
    [, $token] = euisisApiClient();
    DB::table('court_cases')->insert([
        'case_number' => 'ADM/00001/26', 'title' => 'Filed by an applicant', 'case_type_id' => $type, 'filing_date' => now()->toDateString(),
        'status' => 'pending', 'review_status' => 'awaiting_review', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('court_cases')->update(['review_status' => 'accepted']);

    $this->withToken($token)->getJson('/court/api/v1/integration/referrals/updates')->assertOk()->assertJsonCount(0, 'data');
});
