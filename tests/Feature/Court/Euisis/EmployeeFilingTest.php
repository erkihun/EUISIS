<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\IntegrationClient;
use Modules\Court\Domain\Models\Letter;
use Modules\Court\Domain\Models\LetterTemplate;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Services\Euisis\LetterDeliveries;
use Modules\Court\Http\Support\Ui\PortalNavigation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
| Flow 5 (docs/euisis-integration.md): an employee files a case from the EUISIS
| employee portal against an EUISIS institution, with their grievance record and
| documents; letters the court approves for that institution are queued for
| EUISIS to deliver; with EUISIS parties only, no case starts in court-ms itself.
*/

/** @return array{0: IntegrationClient, 1: string} */
function filingClient(array $abilities = IntegrationClient::DEFAULT_ABILITIES): array
{
    $client = new IntegrationClient(['name' => 'EUISIS-'.Str::random(6), 'abilities' => $abilities, 'allowed_ips' => []]);

    return [$client, $client->issueToken()];
}

function filingCaseType(): int
{
    return DB::table('court_case_types')->insertGetId(['name' => 'Labour '.Str::random(4), 'prefix' => 'LAB', 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<string, mixed> */
function filingPayload(int $caseTypeId, array $overrides = []): array
{
    return array_replace_recursive([
        'employee' => [
            'uuid' => (string) Str::uuid(),
            'national_id' => (string) random_int(1000000000000000, 9999999999999999),
            'first_name' => 'Meron', 'middle_name' => 'A', 'last_name' => 'Haile', 'gender' => 'female',
            'email' => 'meron-'.Str::lower(Str::random(6)).'@example.com',
            'phone' => '09'.random_int(10000000, 99999999),
            'position' => 'HR Officer', 'organization_name' => 'Bureau of Finance',
        ],
        'filing_id' => (string) Str::uuid(),
        'case_type_id' => $caseTypeId,
        'description' => "I was transferred without notice.\n\nThe grievance committee upheld the transfer.",
        'relief_requested' => 'Reinstatement to my position.',
        'respondent' => ['organization_uuid' => (string) Str::uuid(), 'name' => 'Bureau of Finance', 'address' => 'Addis Ababa'],
        'grievances' => json_encode([[
            'uuid' => (string) Str::uuid(),
            'reference' => 'GRV-2610-0011',
            'subject' => 'Transfer without notice',
            'category' => 'Transfer',
            'status' => 'closed',
            'description' => '<b>Moved</b> to another branch.',
            'submitted_at' => '2026-10-01T09:00:00+03:00',
            'closed_at' => '2026-10-20T15:30:00+03:00',
            'responses' => [['body' => 'The transfer follows the staffing plan.', 'at' => '2026-10-10']],
            'decisions' => [['number' => 'DEC-14', 'type' => 'Upheld', 'text' => '<script>x</script>The transfer stands.', 'at' => '2026-10-20']],
        ]]),
    ], $overrides);
}

function filingPdf(string $name = 'decision.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

beforeEach(function (): void {
    Storage::fake('court_private');
});

test('an employee files a case from EUISIS with the grievance record and documents, once per filing id', function (): void {
    [, $token] = filingClient();
    $payload = filingPayload(filingCaseType());
    $payload['documents'] = [filingPdf()];
    $payload['document_titles'] = ['Committee decision'];
    $payload['document_sources'] = ['EUISIS grievance GRV-2610-0011'];

    $response = $this->withToken($token)->post('/court/api/v1/integration/people/cases', $payload, ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.review_status', 'awaiting_review')
        ->assertJsonPath('data.status', 'pending');

    $case = DB::table('court_cases')->where('id', $response->json('data.case_id'))->first();
    $applicant = Applicant::query()->findOrFail($case->applicant_id);
    expect($applicant->euisis_employee_uuid)->toBe($payload['employee']['uuid'])
        // The case title is the applicant's name, as on the applicant form.
        ->and($case->title)->toBe('Meron A Haile')
        ->and($case->source)->toBe('euisis_employee')
        ->and($case->euisis_filing_id)->toBe($payload['filing_id'])
        ->and($case->respondent_organization_uuid)->toBe($payload['respondent']['organization_uuid'])
        ->and($case->description)->toContain('<p>I was transferred without notice.</p>');

    $grievance = DB::table('court_case_euisis_grievances')->where('court_case_id', $case->id)->sole();
    expect($grievance->reference)->toBe('GRV-2610-0011')
        ->and($grievance->description)->toBe('Moved to another branch.')
        ->and(json_decode($grievance->decisions, true)[0]['text'])->toBe('xThe transfer stands.')
        ->and(json_decode($grievance->responses, true)[0]['body'])->toBe('The transfer follows the staffing plan.');

    $document = DB::table('court_case_evidences')->where('case_id', $case->id)->sole();
    expect($document->title)->toBe('Committee decision');

    // The same filing sent again returns the case it made.
    $payload['documents'] = [filingPdf()];
    $this->withToken($token)->post('/court/api/v1/integration/people/cases', $payload, ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.case_id', (int) $case->id);
    expect(DB::table('court_cases')->where('euisis_filing_id', $payload['filing_id'])->count())->toBe(1)
        ->and(DB::table('court_case_evidences')->where('case_id', $case->id)->count())->toBe(1);
});

test('filing needs the people:file ability, an EUISIS institution and well-formed grievances', function (): void {
    $type = filingCaseType();

    [, $readOnly] = filingClient([IntegrationClient::ABILITY_PEOPLE_READ]);
    $this->withToken($readOnly)->postJson('/court/api/v1/integration/people/cases', filingPayload($type))
        ->assertStatus(403)->assertJsonPath('error_code', 'ability_missing');

    [, $token] = filingClient();
    $this->withToken($token)->postJson('/court/api/v1/integration/people/cases', filingPayload($type, ['grievances' => json_encode([['subject' => 'No uuid']])]))
        ->assertStatus(422);

    // Once court-ms mirrors the EUISIS directory, only an institution in it may be sued.
    $known = (string) Str::uuid();
    DB::table('court_euisis_organizations')->insert(['uuid' => $known, 'name_en' => 'Bureau of Finance', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $this->withToken($token)->postJson('/court/api/v1/integration/people/cases', filingPayload($type))
        ->assertStatus(422)->assertJsonValidationErrors(['respondent.organization_uuid']);
    $this->withToken($token)->postJson('/court/api/v1/integration/people/cases', filingPayload($type, ['respondent' => ['organization_uuid' => $known]]))
        ->assertCreated();
});

test('the case page shows staff the EUISIS grievance record', function (): void {
    [, $token] = filingClient();
    $payload = filingPayload(filingCaseType());
    $caseId = $this->withToken($token)->postJson('/court/api/v1/integration/people/cases', $payload)->assertCreated()->json('data.case_id');
    // The respondent is in the directory cache, so the EUISIS panel shows.
    DB::table('court_euisis_organizations')->insert(['uuid' => $payload['respondent']['organization_uuid'], 'name_en' => 'Bureau of Finance', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $role = Role::query()->create(['name' => 'Filing viewer '.Str::random(6)]);
    $role->permissions()->sync([Permission::query()->firstOrCreate(['name' => 'cases.view'], ['label' => 'View cases'])->id]);
    $user->roles()->sync([$role->id]);
    DB::table('court_cases')->where('id', $caseId)->update(['review_status' => 'accepted']);

    $this->actingAs($user)->get(route('court.cases.show', $caseId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Cases/Show')
            ->where('euisisRecord.filed_from_euisis', true)
            // Linked, though the EUISIS directory cache has no record of the employee yet.
            ->where('euisis.employee', null)
            ->where('euisis.employee_uuid', $payload['employee']['uuid'])
            ->has('euisisRecord.grievances', 1, fn (AssertableInertia $grievance) => $grievance
                ->where('reference', 'GRV-2610-0011')
                ->where('subject', 'Transfer without notice')
                ->where('responses.0.body', 'The transfer follows the staffing plan.')
                ->where('decisions.0.number', 'DEC-14')
                ->etc())
            ->etc());
});

test('an approved letter to an EUISIS institution is queued for EUISIS with its PDF, and nothing else is', function (): void {
    $organization = (string) Str::uuid();
    $type = filingCaseType();
    $case = fn (array $attributes): int => DB::table('court_cases')->insertGetId([
        'case_number' => 'LAB-'.Str::upper(Str::random(6)), 'title' => 'Transfer', 'case_type_id' => $type, 'filing_date' => now()->toDateString(),
        'status' => 'active', 'review_status' => 'accepted', 'respondent_name' => 'Bureau of Finance', 'created_at' => now(), 'updated_at' => now(),
        ...$attributes,
    ]);
    $euisisCase = $case(['respondent_organization_uuid' => $organization]);
    $otherCase = $case(['respondent_name' => 'Private company']);
    $template = LetterTemplate::query()->create(['title' => 'Summons', 'body' => '<p>Body</p>']);
    $author = User::factory()->create();
    $letterFor = fn (int $caseId, bool $toRespondent = true): Letter => Letter::query()->create([
        'letter_template_id' => $template->id, 'user_id' => $author->id,
        'recipient_name' => 'The Head', 'recipient_title' => 'Director', 'recipient_company' => 'Bureau of Finance',
        'subject' => 'Summons to appear', 'reference_number' => 'REF-'.Str::random(5),
        'case_number' => DB::table('court_cases')->where('id', $caseId)->value('case_number'),
        'body' => '<p>You are summoned.</p>', 'send_to_applicant' => true, 'send_to_respondent' => $toRespondent, 'approval_status' => 'approved',
    ]);

    $deliveries = app(LetterDeliveries::class);
    $queued = $letterFor($euisisCase);
    $deliveries->record($queued);
    $deliveries->record($queued);
    $deliveries->record($letterFor($otherCase));
    $deliveries->record($letterFor($euisisCase, false));
    expect(DB::table('court_euisis_letter_deliveries')->count())->toBe(1);

    [, $token] = filingClient();
    $feed = $this->withToken($token)->getJson('/court/api/v1/integration/letters/updates?since=0')->assertOk();
    $feed->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.letter_id', $queued->id)
        ->assertJsonPath('data.0.organization_uuid', $organization)
        ->assertJsonPath('data.0.subject', 'Summons to appear')
        ->assertJsonPath('has_more', false);
    $this->withToken($token)->getJson('/court/api/v1/integration/letters/updates?since='.$feed->json('next_cursor'))->assertOk()->assertJsonCount(0, 'data');

    $pdf = $this->withToken($token)->get('/court/api/v1/integration/letters/'.$queued->id.'/pdf')->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
    $notQueued = $letterFor($otherCase);
    $this->withToken($token)->get('/court/api/v1/integration/letters/'.$notQueued->id.'/pdf', ['Accept' => 'application/json'])->assertNotFound();

    [, $peopleOnly] = filingClient([IntegrationClient::ABILITY_PEOPLE_READ]);
    $this->withToken($peopleOnly)->getJson('/court/api/v1/integration/letters/updates')->assertStatus(403);
});

test('with EUISIS parties only, cases are not started in court-ms and referrals must name EUISIS parties', function (): void {
    config(['court.euisis.parties_only' => true]);
    $applicant = Applicant::create([
        'first_name' => 'Meron', 'middle_name' => 'A', 'last_name' => 'Haile', 'gender' => 'female',
        'position' => 'Officer', 'organization_name' => 'Org', 'phone' => '09'.random_int(10000000, 99999999),
        'email' => 'portal-'.Str::lower(Str::random(6)).'@example.com', 'address' => 'Addis Ababa',
        'national_id_number' => (string) random_int(1000000000000000, 9999999999999999),
        'password' => Hash::make('password'), 'is_active' => true, 'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.create'))
        ->assertRedirect(route('court.applicant.cases.index'))
        ->assertSessionHas('info', __('court::cases.file_in_euisis'));
    $this->actingAs($applicant, 'applicant')->post(route('court.applicant.cases.store'), ['title' => 'x'])
        ->assertRedirect(route('court.applicant.cases.index'));

    $items = collect(PortalNavigation::sections('applicant'))->flatMap(fn (array $s) => $s['groups'])->flatMap(fn (array $g) => $g['items'])->pluck('route');
    expect($items)->not->toContain('court.applicant.cases.create')
        ->and(PortalNavigation::routes('applicant')['filesInEuisis'])->toBeTrue();

    [, $token] = filingClient([IntegrationClient::ABILITY_REFERRALS_CREATE]);
    $this->withToken($token)->postJson('/court/api/v1/integration/referrals', [
        'referral_id' => (string) Str::uuid(), 'case_type_id' => filingCaseType(), 'title' => 'Referred', 'summary' => 'Summary',
        'complainant' => ['name' => 'Abebe Kebede'], 'respondent' => ['name' => 'Some private company'],
    ])->assertStatus(422)->assertJsonValidationErrors(['complainant.employee_uuid', 'respondent.organization_uuid']);
});
