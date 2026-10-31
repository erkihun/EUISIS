<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\ApplicantSignInTicket;
use Modules\Court\Domain\Models\IntegrationClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
| Flow 4 (docs/euisis-integration.md): the EUISIS employee portal reads an
| employee's court summary and opens the applicant portal with a one-time
| sign-in ticket, opening the employee's court account on first use.
*/

/** @return array{0: IntegrationClient, 1: string} */
function employeeAccessClient(array $abilities = IntegrationClient::DEFAULT_ABILITIES): array
{
    $client = new IntegrationClient(['name' => 'EUISIS-'.Str::random(6), 'abilities' => $abilities, 'allowed_ips' => []]);

    return [$client, $client->issueToken()];
}

/** @return array<string, mixed> */
function employeeAccessEmployee(array $overrides = []): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'national_id' => (string) random_int(1000000000000000, 9999999999999999),
        'first_name' => 'Meron', 'middle_name' => 'A', 'last_name' => 'Haile', 'gender' => 'female',
        'email' => 'meron-'.Str::lower(Str::random(6)).'@example.com',
        'phone' => '09'.random_int(10000000, 99999999),
        'position' => 'HR Officer', 'organization_name' => 'Bureau of Finance', 'address' => 'Addis Ababa',
        ...$overrides,
    ];
}

function employeeAccessApplicant(array $overrides = []): Applicant
{
    $applicant = Applicant::create([
        'first_name' => 'Meron', 'middle_name' => 'A', 'last_name' => 'Haile', 'gender' => 'female',
        'position' => 'Officer', 'organization_name' => 'Org', 'phone' => '09'.random_int(10000000, 99999999),
        'email' => 'existing-'.Str::lower(Str::random(6)).'@example.com', 'address' => 'Addis Ababa',
        'national_id_number' => (string) random_int(1000000000000000, 9999999999999999),
        'password' => Hash::make('password'), 'is_active' => true, 'is_lawyer' => false,
        ...collect($overrides)->except('euisis_employee_uuid')->all(),
    ]);
    $applicant->markEmailAsVerified();
    // Not mass-assignable on purpose: only Flow 4 links an employee.
    if (isset($overrides['euisis_employee_uuid'])) {
        $applicant->forceFill(['euisis_employee_uuid' => $overrides['euisis_employee_uuid']])->save();
    }

    return $applicant;
}

test('an employee without a court account has an unlinked summary, and nothing is created by reading it', function (): void {
    [, $token] = employeeAccessClient();
    $employee = employeeAccessEmployee();

    $this->withToken($token)->postJson('/court/api/v1/integration/people/court-summary', ['employee' => $employee])
        ->assertOk()->assertExactJson(['data' => ['linked' => false]]);
    expect(Applicant::query()->where('email', $employee['email'])->exists())->toBeFalse();
});

test('the first sign-in opens a linked court account and the ticket signs the employee in once', function (): void {
    [$client, $token] = employeeAccessClient();
    $employee = employeeAccessEmployee();

    $url = $this->withToken($token)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => $employee, 'target' => 'new_case'])
        ->assertCreated()->json('data.url');

    $applicant = Applicant::query()->where('euisis_employee_uuid', $employee['uuid'])->firstOrFail();
    expect(preg_replace('/\D+/', '', $applicant->national_id_number))->toBe($employee['national_id'])
        ->and($applicant->email)->toBe($employee['email'])
        ->and($applicant->hasVerifiedEmail())->toBeTrue()
        ->and(ApplicantSignInTicket::query()->where('applicant_id', $applicant->id)->value('integration_client_id'))->toBe($client->id);
    // Only the ticket's hash is stored.
    expect(ApplicantSignInTicket::query()->where('token_hash', basename($url))->exists())->toBeFalse();

    $this->get($url)->assertRedirect(route('court.applicant.cases.create'));
    $this->assertAuthenticatedAs($applicant, 'applicant');

    // The same ticket does not work twice.
    auth('applicant')->logout();
    $this->get($url)->assertRedirect(route('court.applicant.login'));
    $this->assertGuest('applicant');
});

test('the summary lists the linked employee\'s cases, hearings and unseen notices', function (): void {
    [, $token] = employeeAccessClient();
    $employee = employeeAccessEmployee();
    $applicant = employeeAccessApplicant(['national_id_number' => $employee['national_id'], 'euisis_employee_uuid' => $employee['uuid']]);
    $typeId = DB::table('court_case_types')->insertGetId(['name' => 'Civil '.Str::random(4), 'created_at' => now(), 'updated_at' => now()]);
    $caseId = DB::table('court_cases')->insertGetId([
        'applicant_id' => $applicant->id, 'case_number' => 'EMP-'.Str::upper(Str::random(5)), 'code' => 'EM1234', 'title' => 'Unpaid allowance',
        'case_type_id' => $typeId, 'filing_date' => now()->toDateString(), 'status' => 'active', 'review_status' => 'accepted',
        'respondent_name' => 'Bureau of Finance', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('court_case_hearings')->insert(['case_id' => $caseId, 'hearing_at' => now()->addDays(3), 'type' => 'Preliminary', 'location' => 'Room 1', 'created_at' => now(), 'updated_at' => now()]);

    $data = $this->withToken($token)->postJson('/court/api/v1/integration/people/court-summary', ['employee' => ['uuid' => $employee['uuid']]])
        ->assertOk()->json('data');

    expect($data['linked'])->toBeTrue()
        ->and($data['counts'])->toMatchArray(['cases' => 1, 'open' => 1, 'upcoming_hearings' => 1, 'unread_notices' => 1])
        ->and($data['cases'][0]['title'])->toBe('Unpaid allowance')
        ->and($data['cases'][0]['respondent'])->toBe('Bureau of Finance')
        ->and($data['cases'][0]['next_hearing_at'])->not->toBeNull()
        ->and($data['notices'][0])->toHaveKeys(['id', 'title', 'message', 'created_at'])
        ->and($data['notices'][0])->not->toHaveKey('url')
        // The access code and other staff columns never leave court-ms.
        ->and(json_encode($data))->not->toContain('EM1234');

    // Notices are worded in the language the employee reads EUISIS in.
    $title = fn (string $locale): string => (string) $this->withToken($token)->postJson('/court/api/v1/integration/people/court-summary', ['employee' => ['uuid' => $employee['uuid']], 'locale' => $locale])
        ->assertOk()->json('data.notices.0.title');
    expect(preg_match('/[\x{1200}-\x{137F}]/u', $title('am')))->toBe(1)
        ->and(preg_match('/[\x{1200}-\x{137F}]/u', $title('en')))->toBe(0);

    // A ticket can open one of the employee's own cases, not someone else's.
    $this->withToken($token)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => $employee, 'target' => "case:{$caseId}"])->assertCreated();
    $this->withToken($token)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => $employee, 'target' => 'case:999999'])
        ->assertNotFound()->assertJsonPath('error_code', 'unknown_target');
});

test('an existing court account is linked by national ID on first use, never taken over', function (): void {
    [, $token] = employeeAccessClient();
    $employee = employeeAccessEmployee();
    $existing = employeeAccessApplicant(['national_id_number' => $employee['national_id']]);

    $this->withToken($token)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => $employee, 'target' => 'dashboard'])->assertCreated();
    expect($existing->fresh()->euisis_employee_uuid)->toBe($employee['uuid'])
        ->and(Applicant::query()->count())->toBe(1);

    // Another employee with the same national ID gets a conflict, not this account.
    $other = employeeAccessEmployee(['national_id' => $employee['national_id']]);
    $this->withToken($token)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => $other, 'target' => 'dashboard'])
        ->assertStatus(409)->assertJsonPath('error_code', 'linked_to_another_employee');
    $this->withToken($token)->postJson('/court/api/v1/integration/people/court-summary', ['employee' => $other])
        ->assertOk()->assertJsonPath('data.linked', false);

    // An email already used with a different national ID is someone else.
    $clash = employeeAccessApplicant();
    $this->withToken($token)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => employeeAccessEmployee(['email' => $clash->email]), 'target' => 'dashboard'])
        ->assertStatus(409)->assertJsonPath('error_code', 'identity_mismatch');
});

test('an incomplete employee record or a deactivated account gets no ticket', function (): void {
    [, $token] = employeeAccessClient();

    $this->withToken($token)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => employeeAccessEmployee(['phone' => null]), 'target' => 'dashboard'])
        ->assertStatus(409)->assertJsonPath('error_code', 'employee_record_incomplete');
    // Gender is a required male/female column: an unknown one is never guessed.
    $this->withToken($token)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => employeeAccessEmployee(['gender' => null]), 'target' => 'dashboard'])
        ->assertStatus(409)->assertJsonPath('error_code', 'employee_record_incomplete');

    $employee = employeeAccessEmployee();
    employeeAccessApplicant(['national_id_number' => $employee['national_id'], 'euisis_employee_uuid' => $employee['uuid'], 'is_active' => false]);
    $this->withToken($token)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => $employee, 'target' => 'dashboard'])
        ->assertStatus(409)->assertJsonPath('error_code', 'account_inactive');
});

test('an expired ticket and a token without the people abilities are refused', function (): void {
    [, $token] = employeeAccessClient();
    $employee = employeeAccessEmployee();
    $url = $this->withToken($token)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => $employee, 'target' => 'dashboard'])->json('data.url');

    $this->travel(ApplicantSignInTicket::LIFETIME_SECONDS + 5)->seconds();
    $this->get($url)->assertRedirect(route('court.applicant.login'));
    $this->assertGuest('applicant');
    $this->travelBack();

    [, $referralsOnly] = employeeAccessClient([IntegrationClient::ABILITY_REFERRALS_READ]);
    $this->withToken($referralsOnly)->postJson('/court/api/v1/integration/people/court-summary', ['employee' => $employee])
        ->assertForbidden()->assertJsonPath('error_code', 'ability_missing');
    $this->withToken($referralsOnly)->postJson('/court/api/v1/integration/people/sign-in-tickets', ['employee' => $employee, 'target' => 'dashboard'])
        ->assertForbidden();
});

test('the token command grants the new abilities without rotating the token', function (): void {
    [$client, $token] = employeeAccessClient([IntegrationClient::ABILITY_REFERRALS_READ]);
    $client->forceFill(['name' => 'EUISIS'])->save();

    $this->artisan('court:integration-token', ['--refresh-abilities' => true])->assertSuccessful();

    expect($client->fresh()->abilities)->toBe(IntegrationClient::DEFAULT_ABILITIES)
        ->and(IntegrationClient::findByToken($token)?->id)->toBe($client->id);
});
