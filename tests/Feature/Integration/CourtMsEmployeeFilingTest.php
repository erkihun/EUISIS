<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\CourtMsLetter;
use App\Models\User;
use App\Notifications\CourtLetterNotification;
use App\Services\CourtMs\CourtLetterInbox;
use App\Services\CourtMs\CourtMsException;
use App\Services\CourtMs\CourtMsStatusSync;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Modules\Grievance\Application\Services\GrievanceCaseService;
use Modules\Grievance\Application\Services\GrievanceCorrespondenceService;
use Modules\Grievance\Application\Services\GrievanceDecisionService;
use Modules\Grievance\Contracts\Data\GrievanceRoles;
use Modules\Grievance\Domain\Enums\GrievanceSignatureMethod;
use Modules\Grievance\Domain\Models\Grievance;
use Tests\Support\GrievanceScenario;

/*
| court-ms Flow 5 (ADR 0013, docs/integration/court-ms.md): employees file their
| tribunal case from My Portal against an institution registered here, with
| their grievance record; the letters the tribunal approves for an institution
| come back through `court-ms:poll` to its tribunal officers.
*/

const COURT_FILING_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    Storage::fake('local');
    config([
        'court_ms.enabled' => true, 'court_ms.url' => 'https://court.test', 'court_ms.token' => 'cms_test-token',
        'court_ms.employee_portal' => true,
    ]);
    $this->s = GrievanceScenario::build();
});

afterEach(fn () => Carbon::setTestNow());

/** The complainant's grievance, filed with a PDF, decided and answered with an issued decision letter. */
function courtFilingDecidedGrievance(object $test): Grievance
{
    $test->actingAs($test->s->complainant)->post(route('employee.grievances.store'), [
        'subject' => 'Transfer decision contested',
        'description' => '<p>I was moved without notice.</p><p>The committee upheld it.</p>',
        'category_id' => $test->s->category->id,
        'submit' => true,
        'files' => [UploadedFile::fake()->createWithContent('transfer-order.pdf', COURT_FILING_PDF)],
        'file_types' => ['document'],
        ...GrievanceScenario::application(),
    ])->assertRedirect();
    $grievance = Grievance::query()->latest('created_at')->firstOrFail();
    $grievance = app(GrievanceCaseService::class)->intakeAccept($grievance, $test->s->intake, 'Complete');

    $decision = app(GrievanceDecisionService::class)->createDraft($grievance, $test->s->writer, ['decision_type' => 'not_upheld', 'decision_text' => '<p>The transfer stands.</p>']);
    $decision = $test->s->headDecides($decision, $test->s->chair);
    $letters = app(GrievanceCorrespondenceService::class);
    $letter = $letters->generate($grievance->refresh(), $test->s->writer, ['letter_type' => 'decision_letter', 'language' => 'am', 'decision_id' => $decision->id]);
    $letter = $letters->sign($letters->finalize($letter, $test->s->chair), $test->s->chair, GrievanceSignatureMethod::ElectronicApproval);
    $letters->issue(GrievanceScenario::seal($letter), $test->s->chair);

    return $grievance->refresh();
}

function courtFilingFakeCourt(int $status = 201, array $body = []): void
{
    Http::fake([
        'court.test/api/v1/integration/case-types' => Http::response(['data' => [['id' => 3, 'name' => 'Administrative', 'prefix' => 'ADM']]]),
        'court.test/api/v1/integration/people/cases' => Http::response($body ?: ['data' => ['case_id' => 9, 'case_number' => 'ADM/00009/26', 'review_status' => 'awaiting_review', 'status' => 'pending']], $status),
    ]);
}

/** @return array<string, string> the multipart fields court-ms received, by name */
function courtFilingFields(HttpRequest $request): array
{
    return collect($request->data())->filter(fn ($part) => ! isset($part['filename']))->mapWithKeys(fn ($part) => [$part['name'] => (string) $part['contents']])->all();
}

test('the filing form lists the employee\'s grievances, the registered institutions and the court\'s case types', function (): void {
    $grievance = courtFilingDecidedGrievance($this);
    courtFilingFakeCourt();
    $this->s->subcity->forceFill(['status' => OrganizationStatus::Inactive])->save();

    $this->actingAs($this->s->complainant)->get(route('employee.court-cases.file'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Employee/CourtCaseFile')
            ->where('caseTypes.0.id', 3)
            ->where('defaultOrganizationId', $this->s->woreda->id)
            ->where('organizations', fn ($organizations) => collect($organizations)->pluck('id')->contains($this->s->bureau->id)
                && ! collect($organizations)->pluck('id')->contains($this->s->subcity->id))
            ->has('grievances', 1, fn (AssertableInertia $row) => $row
                ->where('id', $grievance->id)
                ->where('reference_number', $grievance->reference_number)
                // The issued decision letter and the PDF the employee submitted.
                ->where('documents', 2)
                ->etc())
            ->has('filingId'));
});

test('filing sends the case, the grievance record and its PDFs to court-ms against the chosen institution', function (): void {
    $grievance = courtFilingDecidedGrievance($this);
    courtFilingFakeCourt();
    $filingId = (string) str()->uuid();

    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.file.store'), [
        'filing_id' => $filingId, 'case_type_id' => 3,
        'description' => 'The transfer was unlawful.', 'relief_requested' => 'Reinstatement.',
        'organization_id' => $this->s->bureau->id, 'grievance_ids' => [$grievance->id],
        'files' => [UploadedFile::fake()->createWithContent('payslip.pdf', COURT_FILING_PDF)],
    ])->assertRedirect(route('employee.court-cases'))->assertSessionHas('court_filed', 'ADM/00009/26');

    Http::assertSent(function (HttpRequest $request) use ($filingId, $grievance): bool {
        if (! str_ends_with($request->url(), '/people/cases')) {
            return false;
        }
        $fields = courtFilingFields($request);
        $record = json_decode($fields['grievances'], true);
        $files = collect($request->data())->filter(fn ($part) => isset($part['filename']));

        // court-ms names the case after the applicant; no title is sent.
        return $fields['filing_id'] === $filingId
            && ! isset($fields['title'])
            && $fields['employee[uuid]'] === (string) $this->s->complainant->employee_id
            && $fields['respondent[organization_uuid]'] === (string) $this->s->bureau->id
            && $fields['respondent[name]'] === $this->s->bureau->name_am
            && $record[0]['uuid'] === $grievance->id
            && $record[0]['reference'] === $grievance->reference_number
            && str_contains($record[0]['description'], "I was moved without notice.\n\nThe committee upheld it.")
            && $record[0]['decisions'][0]['text'] === 'The transfer stands.'
            && $record[0]['decisions'][0]['type'] === __('grievances.enums.decision_type.not_upheld')
            && count($record[0]['responses']) === 1
            // The decision letter, the employee's evidence and the added PDF.
            && $files->count() === 3
            && $files->every(fn ($part) => str_starts_with((string) $part['contents'], '%PDF'))
            && in_array($fields['document_sources[0]'], ['EUISIS grievance '.$grievance->reference_number], true);
    });
});

test('only the employee\'s own grievances and an active registered institution may be sent, and court-ms refusals are explained', function (): void {
    $grievance = courtFilingDecidedGrievance($this);
    // Only the last attempt reaches court-ms, which refuses it.
    courtFilingFakeCourt(409, ['message' => 'Opening a court account needs the national ID.', 'error_code' => 'employee_record_incomplete']);
    $form = fn (array $overrides = []): array => [
        'filing_id' => (string) str()->uuid(), 'case_type_id' => 3, 'description' => 'D',
        'organization_id' => $this->s->bureau->id, ...$overrides,
    ];

    // Someone else's grievance.
    $this->actingAs($this->s->chair)->post(route('employee.court-cases.file.store'), $form(['grievance_ids' => [$grievance->id]]))
        ->assertSessionHasErrors('grievance_ids');
    // An institution that is not active in the directory.
    $this->s->subcity->forceFill(['status' => OrganizationStatus::Inactive])->save();
    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.file.store'), $form(['organization_id' => $this->s->subcity->id]))
        ->assertSessionHasErrors('organization_id');
    // A case type court-ms does not offer.
    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.file.store'), $form(['case_type_id' => 99]))
        ->assertSessionHasErrors('case_type_id');
    Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/people/cases'));

    $this->actingAs($this->s->complainant)->from(route('employee.court-cases.file'))->post(route('employee.court-cases.file.store'), $form())
        ->assertRedirect(route('employee.court-cases.file'))->assertSessionHas('court_error', 'employee_record_incomplete');

    // An account with no employee record cannot sue.
    $stranger = User::factory()->create(['status' => 'active']);
    $this->actingAs($stranger)->get(route('employee.court-cases.file'))->assertRedirect(route('employee.court-cases'));
    $this->actingAs($stranger)->post(route('employee.court-cases.file.store'), $form())->assertNotFound();
});

test('the poll delivers court letters to the institution\'s tribunal officers, once each', function (): void {
    Notification::fake();
    $bureauOfficer = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::TRIBUNAL_PERMISSIONS);
    GrievanceScenario::scope($bureauOfficer, $this->s->bureau);
    $woredaOfficer = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::TRIBUNAL_PERMISSIONS);
    GrievanceScenario::scope($woredaOfficer, $this->s->woreda);

    $row = fn (int $cursor, int $letter, string $organization): array => [
        'cursor' => $cursor, 'letter_id' => $letter, 'organization_uuid' => $organization, 'case_number' => 'ADM/00009/26',
        'case_title' => 'Transfer', 'subject' => 'Summons to appear', 'reference_number' => 'REF-'.$letter,
        'recipient' => 'The Head, Bureau', 'approved_by' => 'Registrar', 'approved_at' => '2026-10-04T10:00:00+03:00',
        'pdf_url' => "/api/v1/integration/letters/{$letter}/pdf",
    ];
    Http::fake([
        'court.test/api/v1/integration/referrals/updates*' => Http::response(['data' => [], 'next_cursor' => 0, 'has_more' => false]),
        'court.test/api/v1/integration/letters/updates*' => Http::sequence()
            ->push(['data' => [$row(1, 11, $this->s->bureau->id), $row(2, 12, (string) str()->uuid())], 'next_cursor' => 2, 'has_more' => false])
            ->push(['data' => [], 'next_cursor' => 2, 'has_more' => false]),
        'court.test/api/v1/integration/letters/11/pdf' => Http::response(COURT_FILING_PDF, 200, ['Content-Type' => 'application/pdf']),
        // The poll also reads new cases against institutions (Flow 7); none here.
        'court.test/api/v1/integration/institutions/cases/new' => Http::response(['data' => [], 'next_cursor' => 0, 'has_more' => false]),
    ]);

    $this->artisan('court-ms:poll')->expectsOutputToContain('court-ms letters: 2 read, 1 delivered.')->assertSuccessful();
    $this->artisan('court-ms:poll')->expectsOutputToContain('court-ms letters: 0 read, 0 delivered.')->assertSuccessful();

    $letter = CourtMsLetter::query()->sole();
    expect($letter->organization_id)->toBe($this->s->bureau->id)
        ->and($letter->court_letter_id)->toBe(11)
        ->and(Storage::disk('local')->get($letter->pdf_path))->toBe(COURT_FILING_PDF)
        ->and(app(CourtMsStatusSync::class)->state(CourtLetterInbox::CURSOR_KEY))->toBe('2');
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'letters/updates') && $request['since'] == 2);
    Notification::assertSentTo($bureauOfficer, CourtLetterNotification::class, fn (CourtLetterNotification $n) => $n->caseNumber === 'ADM/00009/26');
    Notification::assertNotSentTo($woredaOfficer, CourtLetterNotification::class);
});

test('a letter whose PDF cannot be read stops the run before the cursor passes it', function (): void {
    Http::fake([
        'court.test/api/v1/integration/letters/updates*' => Http::response(['data' => [
            ['cursor' => 5, 'letter_id' => 21, 'organization_uuid' => $this->s->bureau->id, 'case_number' => 'ADM/1', 'subject' => 'S'],
        ], 'next_cursor' => 5, 'has_more' => false]),
        'court.test/api/v1/integration/letters/21/pdf' => Http::response(['message' => 'down'], 503),
    ]);

    expect(fn () => app(CourtLetterInbox::class)->run())->toThrow(CourtMsException::class);
    expect(CourtMsLetter::query()->count())->toBe(0)
        ->and(app(CourtMsStatusSync::class)->state(CourtLetterInbox::CURSOR_KEY))->toBeNull();
});

test('tribunal officers read their institution\'s court letters only, and opening one marks it read', function (): void {
    $officer = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::TRIBUNAL_PERMISSIONS);
    GrievanceScenario::scope($officer, $this->s->bureau);
    $keep = function (string $organization, int $letterId): CourtMsLetter {
        $path = "court-ms/letters/{$organization}/{$letterId}.pdf";
        Storage::disk('local')->put($path, COURT_FILING_PDF);

        return CourtMsLetter::query()->create([
            'court_letter_id' => $letterId, 'organization_id' => $organization, 'case_number' => 'ADM/'.$letterId, 'subject' => 'Letter '.$letterId,
            'pdf_disk' => 'local', 'pdf_path' => $path, 'pdf_sha256' => hash('sha256', COURT_FILING_PDF), 'received_at' => now(),
        ]);
    };
    $own = $keep($this->s->bureau->id, 31);
    $other = $keep($this->s->woreda->id, 32);

    $this->actingAs($officer)->get(route('tribunal-cases.letters.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('AdministrativeTribunal/CourtLetters')
            ->has('letters.data', 1)
            ->where('letters.data.0.id', $own->id)
            ->where('letters.data.0.opened', false));

    $this->actingAs($officer)->get(route('tribunal-cases.letters.download', $own->id))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($own->refresh()->opened_at)->not->toBeNull()->and($own->opened_by_user_id)->toBe($officer->id);

    $this->actingAs($officer)->get(route('tribunal-cases.letters.download', $other->id))->assertNotFound();
    $this->actingAs($this->s->complainant)->get(route('tribunal-cases.letters.index'))->assertForbidden();
});
