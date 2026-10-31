<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CaseApplicant;
use Modules\Court\Domain\Models\CaseRepresentative;
use Modules\Court\Domain\Models\EuisisOrganization;
use Modules\Court\Domain\Models\IntegrationClient;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Notifications\ApplicantResetPassword;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Http\Support\Ui\PortalNavigation;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
| Several parties to a case (EUISIS ADR 0013 #13): an employee files through a
| lawyer, with co-applicants who each confirm in their own employee portal; an
| employee applies to intervene and only the judge of the case decides; a judge
| or registrar merges open cases against the same respondent into one.
*/

const PARTIES_RESPONDENT = '03c0ffee-0000-7000-8000-000000000001';

function partiesToken(): string
{
    return (new IntegrationClient(['name' => 'EUISIS-'.Str::random(6), 'abilities' => IntegrationClient::DEFAULT_ABILITIES, 'allowed_ips' => []]))->issueToken();
}

/** @return array<string, string> an EUISIS employee as the integration API names one */
function partiesEmployee(string $first = 'Meron'): array
{
    return [
        'uuid' => (string) Str::uuid(), 'national_id' => (string) random_int(1000000000000000, 9999999999999999),
        'first_name' => $first, 'middle_name' => 'A', 'last_name' => 'Haile', 'gender' => 'female',
        'email' => Str::lower($first).'-'.Str::lower(Str::random(6)).'@example.com', 'phone' => '09'.random_int(10000000, 99999999),
    ];
}

function partiesPdf(string $name = 'document.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

function partiesCaseType(): int
{
    return DB::table('court_case_types')->insertGetId(['name' => 'Labour '.Str::random(4), 'prefix' => 'LAB', 'created_at' => now(), 'updated_at' => now()]);
}

/** Files a case from EUISIS for this employee and returns its id. */
function partiesFile(object $test, string $token, array $employee, array $extra = [], string $respondent = PARTIES_RESPONDENT): int
{
    return (int) $test->withToken($token)->post('/court/api/v1/integration/people/cases', [
        'employee' => $employee, 'filing_id' => (string) Str::uuid(), 'case_type_id' => partiesCaseType(),
        'description' => 'Transferred without notice.', 'relief_requested' => 'Reinstatement.',
        'respondent' => ['organization_uuid' => $respondent, 'name' => 'Bureau of Finance'],
        ...$extra,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.case_id');
}

function partiesLawyer(bool $verified = true, array $attributes = []): Applicant
{
    $lawyer = Applicant::create([
        'first_name' => 'Dawit', 'middle_name' => 'K', 'last_name' => 'Tadesse', 'gender' => 'male', 'position' => 'Advocate',
        'organization_name' => 'Tadesse Law Office', 'phone' => '09'.random_int(10000000, 99999999), 'email' => 'lawyer-'.Str::lower(Str::random(6)).'@example.com',
        'address' => 'Addis Ababa', 'national_id_number' => (string) random_int(1000000000000000, 9999999999999999), 'password' => Hash::make('Lawyer#Pass2026'),
        'is_active' => true,
    ]);
    $lawyer->forceFill(['is_lawyer' => true, 'lawyer_license_number' => 'LIC-'.Str::upper(Str::random(6)), 'lawyer_verified_at' => $verified ? now() : null, ...$attributes])->save();

    return $lawyer->fresh();
}

function partiesStaff(array $permissions, array $attributes = []): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now(), ...$attributes]);
    $role = Role::query()->create(['name' => 'Parties '.Str::random(6)]);
    $role->permissions()->sync(collect($permissions)->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name], ['label' => Str::headline($name)])->id));
    $user->roles()->sync([$role->id]);

    return $user;
}

function partiesWorkspace(object $test, string $token, int $caseId, string $employeeUuid)
{
    return $test->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/workspace", ['employee_uuid' => $employeeUuid]);
}

beforeEach(function (): void {
    Storage::fake('court_private');
    Mail::fake();
});

test('an employee files with co-applicants and a lawyer; each co-applicant joins only after confirming', function (): void {
    $token = partiesToken();
    $lawyer = partiesLawyer();
    [$lead, $joins, $declines] = [partiesEmployee('Meron'), partiesEmployee('Selam'), partiesEmployee('Hana')];

    $caseId = partiesFile($this, $token, $lead, [
        'co_applicants' => [['uuid' => $joins['uuid'], 'name' => 'Selam A Haile'], ['uuid' => $declines['uuid'], 'name' => 'Hana A Haile']],
        'lawyer_license_number' => $lawyer->lawyer_license_number,
        'power_of_attorney' => partiesPdf('poa.pdf'),
    ]);

    $rows = CaseApplicant::query()->where('case_id', $caseId)->orderBy('id')->get();
    expect($rows->pluck('role')->all())->toBe(['lead', 'co_applicant', 'co_applicant'])
        ->and($rows->pluck('status')->all())->toBe(['active', 'invited', 'invited']);
    $representative = CaseRepresentative::query()->where('case_id', $caseId)->sole();
    expect($representative->lawyer_applicant_id)->toBe($lawyer->id)
        ->and($representative->case_applicant_id)->toBe($rows[0]->id)
        // The employee chose the lawyer themself; the registry still checks the power of attorney.
        ->and($representative->status)->toBe('pending')
        ->and($representative->confirmed_by_party_at)->not->toBeNull();
    Storage::disk('court_private')->assertExists($representative->power_of_attorney_path);

    // The invitation waits in the co-applicant's portal; until they answer, the case is not theirs.
    $invitations = $this->withToken($token)->postJson('/court/api/v1/integration/people/parties', ['employee_uuid' => $joins['uuid']])->assertOk()->json('data.invitations');
    expect($invitations)->toHaveCount(1)
        ->and($invitations[0]['case_id'])->toBe($caseId)
        ->and($invitations[0]['role'])->toBe('co_applicant')
        ->and($invitations[0]['invited_by'])->toBe('Meron A Haile');
    partiesWorkspace($this, $token, $caseId, $joins['uuid'])->assertNotFound();

    $this->withToken($token)->postJson("/court/api/v1/integration/people/parties/{$rows[1]->id}/respond", ['employee' => $joins, 'accept' => true])
        ->assertOk()->assertJsonPath('data.status', 'active');
    $this->withToken($token)->postJson("/court/api/v1/integration/people/parties/{$rows[1]->id}/respond", ['employee' => $joins, 'accept' => true])
        ->assertUnprocessable();
    $this->withToken($token)->postJson("/court/api/v1/integration/people/parties/{$rows[2]->id}/respond", ['employee' => $declines, 'accept' => false])
        ->assertOk()->assertJsonPath('data.status', 'declined');
    // Someone else's invitation is not theirs to answer.
    $this->withToken($token)->postJson("/court/api/v1/integration/people/parties/{$rows[2]->id}/respond", ['employee' => $joins, 'accept' => true])->assertNotFound();

    $data = partiesWorkspace($this, $token, $caseId, $joins['uuid'])->assertOk()->json('data');
    expect($data['role'])->toBe('co_applicant')
        // Only the lead applicant (or their lawyer) corrects the case.
        ->and($data['can']['modify'])->toBeFalse()
        ->and(collect($data['parties'])->pluck('name')->all())->toBe(['Meron A Haile', 'Selam A Haile'])
        ->and(collect($data['parties'])->firstWhere('is_you', true)['role'])->toBe('co_applicant')
        ->and($data['parties'][0]['lawyers'][0]['license_number'])->toBe($lawyer->lawyer_license_number);
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/edit", ['employee_uuid' => $joins['uuid']])->assertNotFound();
    // A co-applicant writes to the court on the case.
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/messages", ['employee_uuid' => $joins['uuid'], 'body' => 'I support this case.'])->assertSuccessful();
    expect(DB::table('court_case_messages')->where('case_id', $caseId)->count())->toBe(1);

    partiesWorkspace($this, $token, $caseId, $declines['uuid'])->assertNotFound();
    expect(partiesWorkspace($this, $token, $caseId, $lead['uuid'])->assertOk()->json('data.role'))->toBe('lead');
});

test('a lawyer must be a verified one in the register, with a power of attorney, and the register shows names and licences only', function (): void {
    $token = partiesToken();
    $verified = partiesLawyer();
    $unverified = partiesLawyer(false);
    partiesLawyer(true, ['is_active' => false]);

    $this->withToken($token)->post('/court/api/v1/integration/people/cases', [
        'employee' => partiesEmployee(), 'filing_id' => (string) Str::uuid(), 'case_type_id' => partiesCaseType(), 'description' => 'x',
        'respondent' => ['organization_uuid' => PARTIES_RESPONDENT, 'name' => 'Bureau of Finance'],
        'lawyer_license_number' => $unverified->lawyer_license_number, 'power_of_attorney' => partiesPdf(),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('lawyer_license_number');
    $this->withToken($token)->post('/court/api/v1/integration/people/cases', [
        'employee' => partiesEmployee(), 'filing_id' => (string) Str::uuid(), 'case_type_id' => partiesCaseType(), 'description' => 'x',
        'respondent' => ['organization_uuid' => PARTIES_RESPONDENT, 'name' => 'Bureau of Finance'],
        'lawyer_license_number' => $verified->lawyer_license_number,
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('power_of_attorney');

    $register = $this->withToken($token)->postJson('/court/api/v1/integration/people/lawyers', [])->assertOk()->json('data');
    expect($register)->toBe([['name' => 'Dawit K Tadesse', 'license_number' => $verified->lawyer_license_number]]);
});

test('an employee applies to intervene; only the judge of the case decides, a refusal needs a reason, and an admitted intervener follows the case', function (): void {
    $token = partiesToken();
    [$lead, $intervener] = [partiesEmployee('Meron'), partiesEmployee('Abel')];
    $caseId = partiesFile($this, $token, $lead);
    $caseNumber = (string) DB::table('court_cases')->where('id', $caseId)->value('case_number');
    $judge = partiesStaff(['cases.view'], ['user_type' => 'judge']);
    $otherJudge = partiesStaff(['cases.view'], ['user_type' => 'judge']);
    // Accepted by the registry, so the bench sees it.
    DB::table('court_cases')->where('id', $caseId)->update(['judge_id' => $judge->id, 'review_status' => 'accepted']);

    $this->withToken($token)->post('/court/api/v1/integration/people/interventions', ['employee' => $intervener, 'case_number' => 'NO-SUCH-1', 'statement' => 'x'], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('error_code', 'unknown_case');
    $this->withToken($token)->post('/court/api/v1/integration/people/interventions', [
        'employee' => $intervener, 'case_number' => $caseNumber, 'statement' => 'The same transfer moved me too.', 'document' => partiesPdf('order.pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'requested');
    $this->withToken($token)->post('/court/api/v1/integration/people/interventions', ['employee' => $intervener, 'case_number' => $caseNumber, 'statement' => 'Again.'], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('case_number');
    // The lead applicant cannot intervene in their own case.
    $this->withToken($token)->post('/court/api/v1/integration/people/interventions', ['employee' => $lead, 'case_number' => $caseNumber, 'statement' => 'Me.'], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $party = CaseApplicant::query()->where('case_id', $caseId)->where('role', 'intervener')->sole();
    expect($party->statement)->toBe('The same transfer moved me too.');
    partiesWorkspace($this, $token, $caseId, $intervener['uuid'])->assertNotFound();

    // The case page shows the application; only the judge of the case may decide it.
    $this->actingAs($otherJudge)->get(route('court.cases.show', $caseId))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('parties.can.decide', false)
            ->where('parties.rows.1.role', 'intervener')->where('parties.rows.1.status', 'requested')->where('parties.rows.1.statement', 'The same transfer moved me too.'));
    $this->actingAs($otherJudge)->post(route('court.cases.parties.decide', [$caseId, $party->id]), ['decision' => 'admit'])->assertForbidden();
    $this->actingAs($judge)->get(route('court.cases.parties.attachment', [$caseId, $party->id]))->assertOk();
    $this->actingAs($judge)->from(route('court.cases.show', $caseId))->post(route('court.cases.parties.decide', [$caseId, $party->id]), ['decision' => 'refuse'])
        ->assertSessionHasErrors('note');
    $this->actingAs($judge)->post(route('court.cases.parties.decide', [$caseId, $party->id]), ['decision' => 'admit'])->assertRedirect()->assertSessionHasNoErrors();
    expect($party->fresh()->status)->toBe('admitted')->and($party->fresh()->decided_by_user_id)->toBe($judge->id);
    $this->actingAs($judge)->from(route('court.cases.show', $caseId))->post(route('court.cases.parties.decide', [$caseId, $party->id]), ['decision' => 'refuse', 'note' => 'Late.'])
        ->assertSessionHasErrors('decision');

    expect(partiesWorkspace($this, $token, $caseId, $intervener['uuid'])->assertOk()->json('data.role'))->toBe('intervener');
    $interventions = $this->withToken($token)->postJson('/court/api/v1/integration/people/parties', ['employee_uuid' => $intervener['uuid']])->json('data.interventions');
    expect($interventions)->toHaveCount(1)->and($interventions[0]['status'])->toBe('admitted');
});

test('a judge or registrar merges open cases against the same respondent; the parties, documents and coming hearings move and nothing is deleted', function (): void {
    $token = partiesToken();
    [$first, $second, $third] = [partiesEmployee('Meron'), partiesEmployee('Selam'), partiesEmployee('Hana')];
    $target = partiesFile($this, $token, $first);
    $source = partiesFile($this, $token, $second, ['documents' => [partiesPdf()], 'document_titles' => ['Transfer letter']]);
    $elsewhere = partiesFile($this, $token, $third, [], '03c0ffee-0000-7000-8000-000000000099');
    DB::table('court_case_hearings')->insert([
        ['case_id' => $source, 'hearing_at' => now()->addDays(7)->format('Y-m-d H:i:s'), 'type' => 'Main', 'location' => 'Room 1', 'created_at' => now(), 'updated_at' => now()],
        ['case_id' => $source, 'hearing_at' => now()->subDays(7)->format('Y-m-d H:i:s'), 'type' => 'Past', 'location' => 'Room 1', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('court_cases')->whereIn('id', [$target, $source, $elsewhere])->update(['review_status' => 'accepted']);
    $registrar = partiesStaff(['cases.view', 'cases.merge']);
    $viewer = partiesStaff(['cases.view']);

    $this->actingAs($registrar)->get(route('court.cases.show', $target))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('parties.can.merge', true)
            // Only open cases against the same respondent are offered.
            ->where('parties.candidates', fn ($candidates) => collect($candidates)->pluck('id')->all() === [$source]));
    $this->actingAs($viewer)->post(route('court.cases.merge', $target), ['cases' => [$source], 'note' => 'Same transfer.'])->assertRedirect(route('court.dashboard'));
    $this->actingAs($registrar)->from(route('court.cases.show', $target))->post(route('court.cases.merge', $target), ['cases' => [$elsewhere], 'note' => 'Same transfer.'])
        ->assertSessionHasErrors('cases');
    $this->actingAs($registrar)->from(route('court.cases.show', $target))->post(route('court.cases.merge', $target), ['cases' => [$source]])->assertSessionHasErrors('note');

    $this->actingAs($registrar)->post(route('court.cases.merge', $target), ['cases' => [$source], 'note' => 'Same transfer.'])->assertRedirect()->assertSessionHasNoErrors();

    $merged = DB::table('court_cases')->where('id', $source)->first();
    expect((int) $merged->merged_into_case_id)->toBe($target)
        ->and($merged->status)->toBe('closed')
        ->and($merged->merge_note)->toBe('Same transfer.')
        ->and(DB::table('court_case_evidences')->where('case_id', $target)->pluck('title')->all())->toBe(['Transfer letter'])
        ->and(DB::table('court_case_hearings')->where('case_id', $target)->pluck('type')->all())->toBe(['Main'])
        // A past hearing is the merged case's own record and stays with it.
        ->and(DB::table('court_case_hearings')->where('case_id', $source)->pluck('type')->all())->toBe(['Past']);
    $joined = CaseApplicant::query()->where('case_id', $target)->where('euisis_employee_uuid', $second['uuid'])->sole();
    expect($joined->role)->toBe('co_applicant')->and($joined->status)->toBe('active')->and($joined->merged_from_case_id)->toBe($source);

    // Each employee follows the merged case; the merged one says where it went.
    $data = partiesWorkspace($this, $token, $target, $second['uuid'])->assertOk()->json('data');
    expect($data['role'])->toBe('co_applicant')->and($data['merged_from'])->toBe([$merged->case_number]);
    expect(partiesWorkspace($this, $token, $source, $second['uuid'])->assertOk()->json('data.merged_into.id'))->toBe($target);
    $this->actingAs($registrar)->get(route('court.cases.show', $source))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('parties.mergedInto.id', $target)->where('parties.can.merge', false));

    $this->actingAs($registrar)->from(route('court.cases.show', $target))->post(route('court.cases.merge', $target), ['cases' => [$source], 'note' => 'Again.'])->assertSessionHasErrors('cases');
    expect(DB::table('court_cases')->whereIn('id', [$target, $source, $elsewhere])->count())->toBe(3);
});

test('the registry opens and verifies lawyers, who choose a password by email; only a verified lawyer signs in', function (): void {
    Notification::fake();
    $manager = partiesStaff(['applicants.view', 'applicants.manage']);
    $viewer = partiesStaff(['applicants.view']);

    $this->actingAs($viewer)->get(route('court.lawyers.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Lawyers/Index')->where('canManage', false));
    $fields = [
        'first_name' => 'Ruth', 'middle_name' => 'B', 'last_name' => 'Alemu', 'gender' => 'female', 'email' => 'ruth-'.Str::lower(Str::random(5)).'@example.com',
        'phone' => '09'.random_int(10000000, 99999999), 'national_id_number' => (string) random_int(1000000000000000, 9999999999999999),
        'address' => 'Addis Ababa', 'license_number' => 'LIC-'.Str::upper(Str::random(6)),
    ];
    $this->actingAs($viewer)->post(route('court.lawyers.store'), $fields)->assertRedirect(route('court.dashboard'));
    $this->actingAs($manager)->post(route('court.lawyers.store'), $fields)->assertRedirect()->assertSessionHasNoErrors();

    $lawyer = Applicant::query()->where('email', $fields['email'])->sole();
    expect((bool) $lawyer->is_lawyer)->toBeTrue()->and($lawyer->lawyer_verified_at)->not->toBeNull()->and($lawyer->lawyer_verified_by_user_id)->toBe($manager->id);
    Notification::assertSentTo($lawyer, ApplicantResetPassword::class, fn (ApplicantResetPassword $notice) => str_contains($notice->toMail($lawyer)->actionUrl, '/court/lawyer/reset-password/'));

    $this->actingAs($manager)->get(route('court.lawyers.index', ['q' => $fields['license_number']]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lawyers.data.0.license_number', $fields['license_number'])->where('lawyers.data.0.verified', true)->where('canManage', true));

    $resetToken = Password::broker('applicants')->createToken($lawyer);
    $this->post(route('court.lawyer.password.update'), ['token' => $resetToken, 'email' => $lawyer->email, 'password' => 'Lawyer#Pass2026!', 'password_confirmation' => 'Lawyer#Pass2026!'])
        ->assertRedirect(route('court.lawyer.login'));
    $this->post(route('court.lawyer.login.submit'), ['email' => $lawyer->email, 'password' => 'Lawyer#Pass2026!'])->assertRedirect(route('court.applicant.cases.index'));
    $this->assertAuthenticatedAs($lawyer, 'applicant');
    $this->post(route('court.lawyer.logout'))->assertRedirect(route('court.lawyer.login'));

    $this->actingAs($manager, 'web')->patch(route('court.lawyers.verify', $lawyer->id), ['verified' => false])->assertRedirect();
    expect($lawyer->fresh()->lawyer_verified_at)->toBeNull();
    $this->post(route('court.lawyer.login.submit'), ['email' => $lawyer->email, 'password' => 'Lawyer#Pass2026!'])->assertSessionHasErrors('email');
    $this->assertGuest('applicant');
});

test('a verified lawyer files for a client found in EUISIS, works in the closed portal, and the client confirms in their portal', function (): void {
    config([
        'court.euisis.enabled' => true, 'court.euisis.url' => 'https://euisis.test', 'court.euisis.token' => 'partner-token',
        'court.euisis.portal_in_euisis' => true, 'court.euisis.portal_url' => 'https://euisis.example.test/my-portal/court-cases',
    ]);
    $client = partiesEmployee('Tigist');
    Http::fake(function (HttpRequest $request) use ($client) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $rows = ($query['employee_number'] ?? null) === 'AA-2001'
            ? [['id' => $client['uuid'], 'employee_number' => 'AA-2001', 'full_name' => 'Tigist A Haile', 'full_name_en' => 'Tigist A Haile', 'employment_status' => 'active']]
            : [];

        return Http::response(['data' => $rows, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($rows)]]);
    });
    EuisisOrganization::query()->create(['uuid' => PARTIES_RESPONDENT, 'code' => 'BOF', 'name_en' => 'Bureau of Finance', 'name_am' => null, 'status' => 'active']);
    $lawyer = partiesLawyer();
    $type = partiesCaseType();

    // The portal is closed to everyone else: its sign-in stays gone, even for the lawyer.
    $this->get(route('court.lawyer.login'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Lawyer/Login'));
    $this->actingAs($lawyer, 'applicant')->get('/court/applicant/login')->assertNotFound();
    $this->actingAs($lawyer, 'applicant')->get(route('court.applicant.lawyer.file'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Lawyer/FileCase')->where('organizations.0.uuid', PARTIES_RESPONDENT));
    expect(collect(PortalNavigation::sections('applicant'))->flatMap(fn (array $section) => collect($section['groups'])->flatMap(fn (array $group) => collect($group['items'])->pluck('route')))->all())
        ->toContain('court.applicant.lawyer.file');
    // The dashboard and the case list show "File for a client" from this flag.
    $this->actingAs($lawyer, 'applicant')->get(route('court.applicant.cases.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.can_file_for_client', true));

    $this->actingAs($lawyer, 'applicant')->postJson(route('court.applicant.lawyer.client'), ['employee_number' => 'AA-2001'])->assertOk()->assertJsonPath('data.name', 'Tigist A Haile');
    $this->actingAs($lawyer, 'applicant')->postJson(route('court.applicant.lawyer.client'), ['employee_number' => 'AA-9999'])->assertNotFound();

    $this->actingAs($lawyer, 'applicant')->post(route('court.applicant.lawyer.file.store'), [
        'client_employee_number' => 'AA-2001', 'case_type_id' => $type, 'respondent_organization_uuid' => PARTIES_RESPONDENT,
        'description' => '<p>My client was transferred without notice.</p>', 'power_of_attorney' => partiesPdf('poa.pdf'), 'documents' => [partiesPdf('letter.pdf')],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $case = DB::table('court_cases')->where('source', 'lawyer')->sole();
    expect($case->applicant_id)->toBeNull()->and($case->title)->toBe('Tigist A Haile')->and($case->review_status)->toBe('awaiting_review');
    $lead = CaseApplicant::query()->where('case_id', $case->id)->sole();
    expect($lead->role)->toBe('lead')->and($lead->status)->toBe('invited')->and($lead->euisis_employee_uuid)->toBe($client['uuid']);
    $representative = CaseRepresentative::query()->where('case_applicant_id', $lead->id)->sole();
    expect($representative->status)->toBe('accepted')->and($representative->confirmed_by_party_at)->toBeNull();
    $this->actingAs($lawyer, 'applicant')->get(route('court.applicant.cases.show', $case->id))->assertOk();

    // The client sees the case their lawyer filed and confirms it; it is then theirs.
    $token = partiesToken();
    $invitation = $this->withToken($token)->postJson('/court/api/v1/integration/people/parties', ['employee_uuid' => $client['uuid']])->json('data.invitations.0');
    expect($invitation['role'])->toBe('lead')->and($invitation['lawyer']['license_number'])->toBe($lawyer->lawyer_license_number);
    $this->withToken($token)->postJson("/court/api/v1/integration/people/parties/{$lead->id}/respond", ['employee' => $client, 'accept' => true])->assertOk();

    $applicant = Applicant::query()->where('euisis_employee_uuid', $client['uuid'])->sole();
    expect((int) DB::table('court_cases')->where('id', $case->id)->value('applicant_id'))->toBe($applicant->id)
        ->and($representative->fresh()->confirmed_by_party_at)->not->toBeNull();
    expect(partiesWorkspace($this, $token, (int) $case->id, $client['uuid'])->assertOk()->json('data.role'))->toBe('lead');
});

test('only a verified lawyer files for a client, and an employee account gets no lawyer pages', function (): void {
    $unverified = partiesLawyer(false);
    $this->actingAs($unverified, 'applicant')->get(route('court.applicant.lawyer.file'))->assertForbidden();
    expect(collect(PortalNavigation::sections('applicant'))->flatMap(fn (array $section) => collect($section['groups'])->flatMap(fn (array $group) => collect($group['items'])->pluck('route')))->all())
        ->not->toContain('court.applicant.lawyer.file');
    $this->actingAs($unverified, 'applicant')->get(route('court.applicant.cases.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.can_file_for_client', false));

    // With the portal closed, an account that is not a verified lawyer has no court portal at all.
    config(['court.euisis.portal_in_euisis' => true]);
    $this->actingAs($unverified, 'applicant')->get(route('court.applicant.lawyer.file'))->assertNotFound();
});

test('a lawyer files once for several clients: the first is the applicant, the others co-applicants, all represented', function (): void {
    config(['court.euisis.enabled' => true, 'court.euisis.url' => 'https://euisis.test', 'court.euisis.token' => 'partner-token']);
    [$first, $second, $third] = [partiesEmployee('Tigist'), partiesEmployee('Abel'), partiesEmployee('Ruth')];
    $directory = ['AA-2001' => [$first, 'Tigist A Haile'], 'AA-2002' => [$second, 'Abel A Haile'], 'AA-2003' => [$third, 'Ruth A Haile']];
    Http::fake(function (HttpRequest $request) use ($directory) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $hit = $directory[$query['employee_number'] ?? ''] ?? null;
        $rows = $hit ? [['id' => $hit[0]['uuid'], 'employee_number' => $query['employee_number'], 'full_name' => $hit[1], 'full_name_en' => $hit[1], 'employment_status' => 'active']] : [];

        return Http::response(['data' => $rows, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($rows)]]);
    });
    EuisisOrganization::query()->create(['uuid' => PARTIES_RESPONDENT, 'code' => 'BOF', 'name_en' => 'Bureau of Finance', 'name_am' => null, 'status' => 'active']);
    $lawyer = partiesLawyer();
    $filing = fn (array $others) => [
        'client_employee_number' => 'AA-2001', 'co_client_employee_numbers' => $others, 'case_type_id' => partiesCaseType(),
        'respondent_organization_uuid' => PARTIES_RESPONDENT, 'description' => '<p>All three were transferred without notice.</p>', 'power_of_attorney' => partiesPdf('poa.pdf'),
    ];

    // A client listed twice, or one EUISIS does not know, stops the filing at that row.
    $this->actingAs($lawyer, 'applicant')->post(route('court.applicant.lawyer.file.store'), $filing(['AA-2002', 'AA-2001']))->assertSessionHasErrors('co_client_employee_numbers.1');
    $this->actingAs($lawyer, 'applicant')->post(route('court.applicant.lawyer.file.store'), $filing(['AA-9999']))->assertSessionHasErrors('co_client_employee_numbers.0');
    expect(DB::table('court_cases')->where('source', 'lawyer')->count())->toBe(0);

    $this->actingAs($lawyer, 'applicant')->post(route('court.applicant.lawyer.file.store'), $filing(['AA-2002', '', 'AA-2003']))->assertRedirect()->assertSessionHasNoErrors();

    $case = DB::table('court_cases')->where('source', 'lawyer')->sole();
    $rows = CaseApplicant::query()->where('case_id', $case->id)->orderBy('id')->get();
    expect($rows->pluck('role')->all())->toBe(['lead', 'co_applicant', 'co_applicant'])
        ->and($rows->pluck('status')->unique()->values()->all())->toBe(['invited'])
        ->and($rows->pluck('euisis_employee_uuid')->all())->toBe([$first['uuid'], $second['uuid'], $third['uuid']]);
    $representatives = CaseRepresentative::query()->where('case_id', $case->id)->get();
    expect($representatives)->toHaveCount(3)
        ->and($representatives->pluck('lawyer_applicant_id')->unique()->values()->all())->toBe([$lawyer->id])
        ->and($representatives->pluck('status')->unique()->values()->all())->toBe(['accepted']);

    // Each co-client's invitation shows it was filed by their lawyer; joining confirms the lawyer.
    $token = partiesToken();
    $invitation = $this->withToken($token)->postJson('/court/api/v1/integration/people/parties', ['employee_uuid' => $second['uuid']])->json('data.invitations.0');
    expect($invitation['role'])->toBe('co_applicant')->and($invitation['invited_by'])->toBeNull()->and($invitation['lawyer']['license_number'])->toBe($lawyer->lawyer_license_number);
    $this->withToken($token)->postJson("/court/api/v1/integration/people/parties/{$rows[1]->id}/respond", ['employee' => $second, 'accept' => true])->assertOk();
    expect(CaseRepresentative::query()->where('case_applicant_id', $rows[1]->id)->sole()->confirmed_by_party_at)->not->toBeNull()
        ->and(CaseRepresentative::query()->where('case_applicant_id', $rows[2]->id)->sole()->confirmed_by_party_at)->toBeNull();
});

test('a co-applicant names their own lawyer when joining; the registry then checks the power of attorney', function (): void {
    $token = partiesToken();
    $lawyer = partiesLawyer();
    [$lead, $joins] = [partiesEmployee('Meron'), partiesEmployee('Selam')];
    $caseId = partiesFile($this, $token, $lead, ['co_applicants' => [['uuid' => $joins['uuid'], 'name' => 'Selam A Haile']]]);
    $party = CaseApplicant::query()->where('case_id', $caseId)->where('euisis_employee_uuid', $joins['uuid'])->sole();

    // A licence number the register does not know is refused, and the invitation stays open.
    $this->withToken($token)->post("/court/api/v1/integration/people/parties/{$party->id}/respond",
        ['employee' => $joins, 'accept' => '1', 'lawyer_license_number' => 'LIC-NOPE', 'power_of_attorney' => partiesPdf('poa.pdf')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('error_code', 'unknown_lawyer');
    // A lawyer without the power of attorney is refused too.
    $this->withToken($token)->postJson("/court/api/v1/integration/people/parties/{$party->id}/respond",
        ['employee' => $joins, 'accept' => true, 'lawyer_license_number' => $lawyer->lawyer_license_number])->assertStatus(422);
    expect($party->fresh()->status)->toBe('invited');

    $this->withToken($token)->post("/court/api/v1/integration/people/parties/{$party->id}/respond",
        ['employee' => $joins, 'accept' => '1', 'lawyer_license_number' => $lawyer->lawyer_license_number, 'power_of_attorney' => partiesPdf('poa.pdf')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.status', 'active');

    $representative = CaseRepresentative::query()->where('case_applicant_id', $party->id)->sole();
    expect($representative->lawyer_applicant_id)->toBe($lawyer->id)
        ->and($representative->status)->toBe('pending')
        ->and($representative->confirmed_by_party_at)->not->toBeNull()
        ->and(Storage::disk('court_private')->exists((string) $representative->power_of_attorney_path))->toBeTrue();
});

test('the registry records a lawyer for a party from the power of attorney, and the lawyer follows the case', function (): void {
    $token = partiesToken();
    [$lead, $joins] = [partiesEmployee('Meron'), partiesEmployee('Selam')];
    $caseId = partiesFile($this, $token, $lead, ['co_applicants' => [['uuid' => $joins['uuid'], 'name' => 'Selam A Haile']]]);
    $invited = CaseApplicant::query()->where('case_id', $caseId)->where('euisis_employee_uuid', $joins['uuid'])->sole();
    $this->withToken($token)->postJson("/court/api/v1/integration/people/parties/{$invited->id}/respond", ['employee' => $joins, 'accept' => true])->assertOk();
    $party = $invited->fresh();
    $lawyer = partiesLawyer();
    $unverified = partiesLawyer(false);
    $registrar = partiesStaff(['cases.view', 'cases.edit']);
    DB::table('court_cases')->where('id', $caseId)->update(['review_status' => 'accepted']);

    // The case page offers the register to staff who may record a lawyer.
    $this->actingAs($registrar, 'web')->get(route('court.cases.show', $caseId))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('parties.lawyers', fn ($lawyers) => collect($lawyers)->pluck('id')->contains($lawyer->id) && ! collect($lawyers)->pluck('id')->contains($unverified->id)));

    $store = fn (int $lawyerId) => $this->actingAs($registrar, 'web')->from(route('court.cases.show', $caseId))
        ->post(route('court.cases.parties.representatives.store', [$caseId, $party->id]), ['lawyer_id' => $lawyerId, 'power_of_attorney' => partiesPdf('poa.pdf'), 'note' => 'Brought to the registry']);
    $store($unverified->id)->assertSessionHasErrors('lawyer_id');
    $store($lawyer->id)->assertRedirect(route('court.cases.show', $caseId))->assertSessionHasNoErrors();
    $store($lawyer->id)->assertSessionHasErrors('lawyer');

    $representative = CaseRepresentative::query()->where('case_applicant_id', $party->id)->sole();
    expect($representative->status)->toBe('accepted')
        ->and($representative->verified_by_user_id)->toBe($registrar->id)
        ->and($representative->note)->toBe('Brought to the registry');
    $this->assertDatabaseHas('court_case_audits', ['case_id' => $caseId, 'action' => 'representation_added']);
    expect(DB::query()->fromSub(CaseParties::caseIdsFor($lawyer->id), 'ids')->pluck('id')->map(fn ($id) => (int) $id)->all())->toContain($caseId);

    // Recording a lawyer needs cases.edit.
    $viewer = partiesStaff(['cases.view']);
    $this->actingAs($viewer, 'web')->post(route('court.cases.parties.representatives.store', [$caseId, $party->id]), ['lawyer_id' => $lawyer->id, 'power_of_attorney' => partiesPdf('poa.pdf')])
        ->assertRedirect(route('court.dashboard'));
});
