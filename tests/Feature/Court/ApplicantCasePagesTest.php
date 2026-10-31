<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\LetterTemplate;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Modules\Court\Domain\Models\TermsAndCondition;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

// The applicant's case pages and the replies to respondent responses on the
// EUISIS stack (Court/Applicant/Cases/*, Court/Applicant/Replies/*): each page's
// component and props, and the actions those pages send, posted the way the
// React forms send them (multipart, PATCH as POST + _method).

beforeEach(function (): void {
    Mail::fake();
    Http::fake();
    Storage::fake('court_private');
    Storage::fake('court_public');
});

function acpApplicant(array $overrides = []): Applicant
{
    $tag = Str::lower(Str::random(8));
    $applicant = Applicant::query()->create(array_merge([
        'first_name' => 'Almaz',
        'middle_name' => 'T',
        'last_name' => 'Bekele',
        'gender' => 'female',
        'position' => 'Officer',
        'organization_name' => 'ACME',
        'phone' => '09'.random_int(10000000, 99999999),
        'email' => "acp-{$tag}@example.com",
        'address' => 'Bole, Addis Ababa',
        'national_id_number' => (string) random_int(1000000000000000, 9999999999999999),
        'password' => Hash::make('password'),
        'is_active' => true,
        'is_lawyer' => false,
    ], $overrides));
    $applicant->markEmailAsVerified();

    return $applicant;
}

function acpCaseType(string $name = 'Labour'): int
{
    return DB::table('court_case_types')->insertGetId([
        'name' => $name.' '.Str::random(4),
        'prefix' => 'LAB',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function acpCase(Applicant $owner, array $attributes = []): int
{
    return DB::table('court_cases')->insertGetId(array_merge([
        'applicant_id' => $owner->id,
        'case_number' => 'ACP/'.random_int(10000, 99999).'/'.now()->format('y'),
        'code' => (string) random_int(10000, 99999),
        'title' => 'Almaz T Bekele',
        'description' => '<p>The facts of the case.</p>',
        'relief_requested' => '<p>Reinstatement.</p>',
        'respondent_name' => 'Addis Ababa City Administration',
        'respondent_address' => 'Arada',
        'case_type_id' => acpCaseType(),
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'review_status' => 'awaiting_review',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

function acpRespondentResponse(string $caseNumber, string $reviewStatus = 'accepted'): RespondentResponse
{
    $tag = Str::lower(Str::random(8));
    $respondent = Respondent::query()->create([
        'first_name' => 'Ruth',
        'middle_name' => 'M',
        'last_name' => 'Tadesse',
        'gender' => 'female',
        'position' => 'Legal officer',
        'organization_name' => 'Example Bureau',
        'address' => 'Kirkos',
        'phone' => '09'.random_int(10000000, 99999999),
        'email' => "acp-resp-{$tag}@example.com",
        'password' => Hash::make('password'),
    ]);

    return RespondentResponse::query()->create([
        'respondent_id' => $respondent->id,
        'case_number' => $caseNumber,
        'response_number' => ($reviewStatus === 'accepted' ? 'R/' : 'R-'.$reviewStatus.'/').$caseNumber,
        'title' => 'Statement of defence',
        'description' => '<p>We dispute the claim.</p>',
        'pdf_path' => 'respondent/responses/defence.pdf',
        'review_status' => $reviewStatus,
    ]);
}

function acpReply(int $caseId, Applicant $applicant, RespondentResponse $response, string $reviewStatus = 'awaiting_review'): int
{
    Storage::disk('court_private')->put('applicant/response-replies/reply.pdf', '%PDF-1.4');

    return DB::table('court_applicant_response_replies')->insertGetId([
        'case_id' => $caseId,
        'applicant_id' => $applicant->id,
        'respondent_response_id' => $response->id,
        'description' => "Our reply.\nSecond line.",
        'pdf_path' => 'applicant/response-replies/reply.pdf',
        'review_status' => $reviewStatus,
        'review_note' => $reviewStatus === 'returned' ? 'Add the annex.' : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('the case list shows only the applicant\'s own cases with their review state and delete rule', function (): void {
    $applicant = acpApplicant();
    $pending = acpCase($applicant, ['review_status' => 'returned', 'review_note' => 'Attach the dismissal letter.']);
    $accepted = acpCase($applicant, ['review_status' => 'accepted', 'status' => 'active', 'created_at' => now()->subDay()]);
    acpCase(acpApplicant(), ['title' => 'Someone else']);

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Cases/Index')
            ->has('cases.data', 2)
            ->where('court::cases.total', 2)
            ->where('cases.data.0.id', $pending)
            ->where('cases.data.0.review_status', 'returned')
            ->where('cases.data.0.review_note', 'Attach the dismissal letter.')
            ->where('cases.data.0.can_delete', true)
            ->where('cases.data.0.filing_date', now()->toDateString())
            ->where('cases.data.1.id', $accepted)
            ->where('cases.data.1.status', 'active')
            ->where('cases.data.1.can_delete', false)
            ->has('cases.data.0.case_type')
            ->where('auth.user.id', $applicant->id)
            ->has('workspace.nav')
            ->etc());
});

test('the filing form carries the case types, the published terms and the applicant defaults', function (): void {
    $applicant = acpApplicant();
    $type = acpCaseType('Civil');
    TermsAndCondition::query()->create(['title' => 'Filing terms', 'slug' => 'filing-terms-'.Str::random(4), 'body' => "Be truthful.\nKeep copies.", 'is_published' => true, 'published_at' => now()]);

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Cases/Create')
            ->where('types', fn ($types) => collect($types)->contains(fn ($row) => $row['id'] === $type && $row['prefix'] === 'LAB'))
            ->where('terms.body_html', fn (string $html) => str_contains($html, 'Be truthful.') && str_contains($html, '<br'))
            ->where('applicant.is_lawyer', false)
            ->where('applicant.name', $applicant->full_name)
            ->where('applicant.address', 'Bole, Addis Ababa')
            ->has('organizations')
            ->where('today', now()->toDateString())
            ->etc());
});

test('filing a case from the page payload stores the case, its evidence, witnesses and terms acceptance', function (): void {
    $applicant = acpApplicant();
    $type = acpCaseType();
    $terms = TermsAndCondition::query()->create(['title' => 'Filing terms', 'slug' => 'filing-terms-'.Str::random(4), 'body' => 'Be truthful.', 'is_published' => true, 'published_at' => now()]);

    $response = $this->actingAs($applicant, 'applicant')->post(route('court.applicant.cases.store'), [
        'title' => $applicant->full_name,
        'applicant_address' => 'Bole, Addis Ababa',
        'respondent_name' => 'Kirkos Sub-city',
        'respondent_address' => 'Kirkos',
        'respondent_organization_uuid' => '',
        'case_type_id' => (string) $type,
        'accept_terms' => '1',
        'description' => '<p>I was dismissed without notice.</p>',
        'relief_requested' => '<p>Reinstatement and back pay.</p>',
        'certify_appeal' => '1',
        'certify_evidence' => '1',
        'filing_date' => now()->toDateString(),
        'evidence_titles' => ['Dismissal letter'],
        'evidence_files' => [UploadedFile::fake()->create('dismissal.pdf', 120, 'application/pdf')],
        'witnesses' => [['full_name' => 'Kebede Alemu', 'phone' => '0911223344', 'address' => 'Lideta']],
    ]);

    $case = DB::table('court_cases')->where('applicant_id', $applicant->id)->first();
    expect($case)->not->toBeNull()
        ->and($case->status)->toBe('pending')
        ->and($case->review_status)->toBe('awaiting_review')
        ->and($case->case_number)->toStartWith('LAB/');
    $response->assertRedirect(route('court.applicant.cases.show', $case->id))->assertSessionHasNoErrors();

    $this->assertDatabaseHas('court_case_evidences', ['case_id' => $case->id, 'title' => 'Dismissal letter', 'type' => 'document']);
    $this->assertDatabaseHas('court_case_witnesses', ['case_id' => $case->id, 'full_name' => 'Kebede Alemu', 'phone' => '0911223344']);
    $this->assertDatabaseHas('court_applicant_term_acceptances', ['applicant_id' => $applicant->id, 'terms_and_condition_id' => $terms->id]);
});

test('an unchecked certification is refused with the field errors the form shows', function (): void {
    $applicant = acpApplicant();

    $this->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.cases.create'))
        ->post(route('court.applicant.cases.store'), [
            'title' => 'X',
            'respondent_name' => 'Y',
            'respondent_address' => 'Z',
            'case_type_id' => (string) acpCaseType(),
            'description' => '<p>D</p>',
            'relief_requested' => '<p>R</p>',
            'certify_appeal' => '0',
            'certify_evidence' => '0',
            'filing_date' => now()->toDateString(),
            'evidence_titles' => ['Doc'],
            'evidence_files' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
            'witnesses' => [['full_name' => 'W', 'phone' => '1', 'address' => 'A'], ['full_name' => 'V', 'phone' => '1', 'address' => 'B']],
        ])
        ->assertRedirect(route('court.applicant.cases.create'))
        ->assertSessionHasErrors(['certify_appeal', 'certify_evidence', 'witnesses_duplicate_phone']);
});

test('the case page carries the case, its documents, people, hearings, letters, responses, messages, files and audits', function (): void {
    $applicant = acpApplicant();
    $caseId = acpCase($applicant, ['review_status' => 'returned', 'review_note' => 'Add the contract.']);
    $case = DB::table('court_cases')->find($caseId);
    $staff = User::factory()->create(['name' => 'Registrar Hana']);

    DB::table('court_case_evidences')->insert(['case_id' => $caseId, 'type' => 'document', 'title' => 'Contract', 'file_path' => 'evidences/contract.pdf', 'mime' => 'application/pdf', 'size' => 2048, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('court_case_witnesses')->insert(['case_id' => $caseId, 'full_name' => 'Marta Tesfaye', 'phone' => '0911000000', 'address' => 'Yeka', 'created_at' => now(), 'updated_at' => now()]);
    $hearingId = DB::table('court_case_hearings')->insertGetId(['case_id' => $caseId, 'hearing_at' => now()->addDays(5)->setTime(9, 30)->format('Y-m-d H:i:s'), 'type' => 'Preliminary', 'location' => 'Room 4', 'created_at' => now(), 'updated_at' => now()]);
    $staffMessage = DB::table('court_case_messages')->insertGetId(['case_id' => $caseId, 'sender_user_id' => $staff->id, 'body' => 'Please bring the original.', 'created_at' => now()->subHour(), 'updated_at' => now()]);
    DB::table('court_case_messages')->insert(['case_id' => $caseId, 'sender_applicant_id' => $applicant->id, 'body' => 'Noted.', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('court_case_files')->insert([
        ['case_id' => $caseId, 'uploaded_by_applicant_id' => $applicant->id, 'label' => 'Payslip', 'path' => 'case_files/payslip.pdf', 'mime' => 'application/pdf', 'size' => 1024, 'created_at' => now(), 'updated_at' => now()],
        ['case_id' => $caseId, 'uploaded_by_applicant_id' => null, 'label' => 'Court summons', 'path' => 'case_files/summons.pdf', 'mime' => 'application/pdf', 'size' => 1024, 'created_at' => now()->subMinute(), 'updated_at' => now()],
    ]);
    $template = LetterTemplate::query()->create(['title' => 'Summons', 'body' => '<p>Body</p>']);
    foreach (['01' => ['approved', 'Summons to appear'], '02' => ['pending', 'Draft letter']] as $sequence => [$status, $subject]) {
        DB::table('court_letters')->insert(['letter_template_id' => $template->id, 'user_id' => $staff->id, 'recipient_name' => 'Almaz', 'subject' => $subject, 'body' => '<p>x</p>', 'reference_number' => $case->case_number.'/'.$sequence, 'approval_status' => $status, 'case_number' => $case->case_number, 'created_at' => now(), 'updated_at' => now()]);
    }
    $accepted = acpRespondentResponse($case->case_number);
    acpRespondentResponse($case->case_number, 'awaiting_review');
    DB::table('court_case_audits')->insert(['case_id' => $caseId, 'action' => 'file_uploaded', 'actor_type' => 'applicant', 'actor_id' => $applicant->id, 'meta' => json_encode(['label' => 'Payslip']), 'created_at' => now()]);

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.show', $caseId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Cases/Show')
            ->where('case.id', $caseId)
            ->where('case.case_number', $case->case_number)
            ->where('case.code', $case->code)
            ->where('case.review_status', 'returned')
            ->where('case.review_note', 'Add the contract.')
            ->where('case.description_html', fn (string $html) => str_contains($html, 'The facts of the case.'))
            ->where('case.relief_html', fn (string $html) => str_contains($html, 'Reinstatement.'))
            ->where('applicant.name', 'Almaz T Bekele')
            ->where('applicant.address', 'Bole, Addis Ababa')
            ->has('docs', 1, fn (AssertableInertia $doc) => $doc->where('title', 'Contract')->where('has_file', true)->where('size', 2048)->etc())
            ->has('witnesses', 1, fn (AssertableInertia $witness) => $witness->where('full_name', 'Marta Tesfaye')->etc())
            ->has('hearings', 1, fn (AssertableInertia $hearing) => $hearing->where('id', $hearingId)->where('location', 'Room 4')->etc())
            ->has('letters', 1, fn (AssertableInertia $letter) => $letter->where('subject', 'Summons to appear')->where('approval_status', 'approved')->where('preview_url', fn (string $url) => str_contains($url, 'signature='))->etc())
            ->has('respondentResponses', 1, fn (AssertableInertia $response) => $response->where('id', $accepted->id)->where('has_pdf', true)->where('excerpt', 'We dispute the claim.')->etc())
            ->has('messages', 2)
            ->where('messages.0.mine', false)
            ->where('messages.0.sender_name', 'Registrar Hana')
            ->where('messages.1.mine', true)
            ->has('files', 2)
            ->where('files.0.name', 'Payslip')
            ->where('files.0.can_delete', true)
            ->where('files.1.can_delete', false)
            ->has('audits', 1, fn (AssertableInertia $audit) => $audit->where('action', 'file_uploaded')->where('meta', fn (string $meta) => str_contains($meta, 'Payslip'))->etc())
            ->etc());

    // Opening the page marks the court's message and the upcoming hearing as seen, as before.
    $this->assertDatabaseHas('court_notification_reads', ['applicant_id' => $applicant->id, 'type' => 'message', 'source_id' => $staffMessage]);
    $this->assertDatabaseHas('court_notification_reads', ['applicant_id' => $applicant->id, 'type' => 'hearing', 'source_id' => $hearingId]);
});

test('the edit page carries the case and its filed documents and witnesses, and an approved case cannot be edited', function (): void {
    $applicant = acpApplicant();
    $caseId = acpCase($applicant);
    DB::table('court_case_evidences')->insert(['case_id' => $caseId, 'type' => 'document', 'title' => 'Contract', 'file_path' => 'evidences/contract.pdf', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('court_case_witnesses')->insert(['case_id' => $caseId, 'full_name' => 'Marta Tesfaye', 'phone' => '0911000000', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.edit', $caseId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Cases/Edit')
            ->where('case.id', $caseId)
            ->where('case.filing_date', now()->toDateString())
            ->where('case.description', fn (string $html) => str_contains($html, 'The facts of the case.'))
            ->where('case.respondent_name', 'Addis Ababa City Administration')
            ->where('editable', true)
            ->has('types')
            ->has('docs', 1, fn (AssertableInertia $doc) => $doc->where('title', 'Contract')->where('has_file', true)->etc())
            ->has('witnesses', 1, fn (AssertableInertia $witness) => $witness->where('full_name', 'Marta Tesfaye')->etc())
            ->where('yearSuffix', now()->format('y'))
            ->etc());

    $approved = acpCase($applicant, ['review_status' => 'accepted']);
    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.edit', $approved))
        ->assertRedirect(route('court.applicant.cases.show', $approved))
        ->assertSessionHas('error');
});

test('saving the edit form (multipart PATCH sent as POST) updates the case and adds the new evidence and witness', function (): void {
    $applicant = acpApplicant();
    $caseId = acpCase($applicant, ['review_status' => 'returned']);
    $case = DB::table('court_cases')->find($caseId);

    $this->actingAs($applicant, 'applicant')
        ->from(route('court.applicant.cases.edit', $caseId))
        ->post(route('court.applicant.cases.update', $caseId), [
            '_method' => 'patch',
            'title' => 'Almaz T Bekele',
            'filing_date' => now()->subDay()->toDateString(),
            'case_type_id' => (string) $case->case_type_id,
            'respondent_name' => 'Kirkos Sub-city',
            'respondent_address' => 'Kirkos',
            'description' => '<p>Corrected facts.</p>',
            'relief_requested' => '<p>Back pay.</p>',
            'certify_appeal' => '1',
            'certify_evidence' => '1',
            'evidence_titles' => ['Employment contract'],
            'evidence_files' => [UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf')],
            'witnesses' => [['full_name' => 'Kebede Alemu', 'phone' => '0911223344', 'email' => 'kebede@example.com', 'address' => 'Lideta']],
        ])
        ->assertRedirect(route('court.applicant.cases.show', $caseId))
        ->assertSessionHasNoErrors();

    $updated = DB::table('court_cases')->find($caseId);
    expect($updated->respondent_name)->toBe('Kirkos Sub-city')
        ->and($updated->description)->toContain('Corrected facts.')
        ->and($updated->review_status)->toBe('awaiting_review')
        ->and($updated->case_number)->toBe($case->case_number);
    $this->assertDatabaseHas('court_case_evidences', ['case_id' => $caseId, 'title' => 'Employment contract']);
    $this->assertDatabaseHas('court_case_witnesses', ['case_id' => $caseId, 'full_name' => 'Kebede Alemu', 'email' => 'kebede@example.com']);
});

test('a filed document and a witness are removed from the edit page while the case is pending', function (): void {
    $applicant = acpApplicant();
    $caseId = acpCase($applicant);
    Storage::disk('court_private')->put('evidences/contract.pdf', '%PDF-1.4');
    $evidenceId = DB::table('court_case_evidences')->insertGetId(['case_id' => $caseId, 'type' => 'document', 'title' => 'Contract', 'file_path' => 'evidences/contract.pdf', 'created_at' => now(), 'updated_at' => now()]);
    $witnessId = DB::table('court_case_witnesses')->insertGetId(['case_id' => $caseId, 'full_name' => 'Marta Tesfaye', 'phone' => '0911000000', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($applicant, 'applicant')->from(route('court.applicant.cases.edit', $caseId))
        ->delete(route('court.applicant.cases.evidences.delete', ['id' => $caseId, 'evidenceId' => $evidenceId]))
        ->assertRedirect(route('court.applicant.cases.edit', $caseId));
    $this->actingAs($applicant, 'applicant')->from(route('court.applicant.cases.edit', $caseId))
        ->delete(route('court.applicant.cases.witnesses.delete', ['id' => $caseId, 'witnessId' => $witnessId]))
        ->assertRedirect(route('court.applicant.cases.edit', $caseId));

    $this->assertDatabaseMissing('court_case_evidences', ['id' => $evidenceId]);
    $this->assertDatabaseMissing('court_case_witnesses', ['id' => $witnessId]);
    expect(Storage::disk('court_private')->exists('evidences/contract.pdf'))->toBeFalse();
});

test('a message posted from the case page reaches the thread', function (): void {
    $applicant = acpApplicant();
    $caseId = acpCase($applicant);

    $this->actingAs($applicant, 'applicant')->from(route('court.applicant.cases.show', $caseId))
        ->post(route('court.applicant.cases.messages.post', $caseId), ['body' => 'When is the next hearing?'])
        ->assertRedirect(route('court.applicant.cases.show', $caseId))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('court_case_messages', ['case_id' => $caseId, 'sender_applicant_id' => $applicant->id, 'body' => 'When is the next hearing?']);
    $this->assertDatabaseHas('court_case_audits', ['case_id' => $caseId, 'action' => 'message_posted', 'actor_type' => 'applicant']);
});

test('a file uploaded from the case page is listed and can be removed by its uploader only', function (): void {
    $applicant = acpApplicant();
    $caseId = acpCase($applicant);

    $this->actingAs($applicant, 'applicant')->from(route('court.applicant.cases.show', $caseId))
        ->post(route('court.applicant.cases.files.upload', $caseId), ['label' => 'Payslip', 'file' => UploadedFile::fake()->create('payslip.pdf', 80, 'application/pdf')])
        ->assertRedirect(route('court.applicant.cases.show', $caseId))
        ->assertSessionHasNoErrors();

    $file = DB::table('court_case_files')->where('case_id', $caseId)->first();
    expect($file)->not->toBeNull()->and($file->label)->toBe('Payslip');
    expect(Storage::disk('court_private')->exists($file->path))->toBeTrue();

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.show', $caseId))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('files', 1, fn (AssertableInertia $row) => $row->where('id', $file->id)->where('name', 'Payslip')->where('can_delete', true)->etc())->etc());

    $courtFile = DB::table('court_case_files')->insertGetId(['case_id' => $caseId, 'uploaded_by_applicant_id' => null, 'label' => 'Summons', 'path' => 'case_files/summons.pdf', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($applicant, 'applicant')->delete(route('court.applicant.cases.files.delete', [$caseId, $courtFile]))->assertForbidden();

    $this->actingAs($applicant, 'applicant')->from(route('court.applicant.cases.show', $caseId))
        ->delete(route('court.applicant.cases.files.delete', [$caseId, $file->id]))
        ->assertRedirect(route('court.applicant.cases.show', $caseId));
    $this->assertDatabaseMissing('court_case_files', ['id' => $file->id]);
    expect(Storage::disk('court_private')->exists($file->path))->toBeFalse();
});

test('a pending case is deleted from the list, and the receipt and calendar downloads still answer', function (): void {
    $applicant = acpApplicant();
    $caseId = acpCase($applicant);
    $hearingId = DB::table('court_case_hearings')->insertGetId(['case_id' => $caseId, 'hearing_at' => now()->addDays(3)->format('Y-m-d H:i:s'), 'type' => 'Hearing', 'location' => 'Room 2', 'created_at' => now(), 'updated_at' => now()]);

    // The printable receipt: the case, its hearings with calendar links, and the details as cleaned HTML.
    DB::table('court_cases')->where('id', $caseId)->update(['description' => '<p>Facts of the case</p><script>alert(1)</script>']);
    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.receipt', $caseId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Cases/Receipt')
            ->where('receipt.id', $caseId)
            ->where('receipt.description_html', fn (string $html): bool => str_contains($html, 'Facts of the case') && ! str_contains($html, 'script'))
            ->where('receipt.applicant.email', $applicant->email)
            ->has('hearings', 1)
            ->where('hearings.0.location', 'Room 2')
            ->where('hearings.0.ics_url', route('court.applicant.cases.hearings.ics', [$caseId, $hearingId]))
            ->has('generatedAt'));
    $ics = $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.hearings.ics', [$caseId, $hearingId]));
    $ics->assertOk();
    expect($ics->headers->get('Content-Type'))->toContain('text/calendar')
        ->and($ics->getContent())->toContain('BEGIN:VEVENT');

    $this->actingAs($applicant, 'applicant')->delete(route('court.applicant.cases.destroy', $caseId))
        ->assertRedirect(route('court.applicant.cases.index'));
    $this->assertDatabaseMissing('court_cases', ['id' => $caseId]);
});

test('the respondent response page lists the applicant\'s replies and links the reply form', function (): void {
    $applicant = acpApplicant();
    $caseId = acpCase($applicant, ['review_status' => 'accepted']);
    $case = DB::table('court_cases')->find($caseId);
    $response = acpRespondentResponse($case->case_number);
    $replyId = acpReply($caseId, $applicant, $response, 'returned');

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.respondentResponses.show', [$caseId, $response->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Cases/RespondentResponse')
            ->where('case.id', $caseId)
            ->where('response.id', $response->id)
            ->where('response.title', 'Statement of defence')
            ->where('response.respondent_name', 'Ruth M Tadesse')
            ->where('response.response_number', 'R/'.$case->case_number)
            ->where('response.has_pdf', true)
            ->where('response.pdf_name', 'defence.pdf')
            ->where('response.description_html', fn (string $html) => str_contains($html, 'We dispute the claim.'))
            ->has('replies', 1, fn (AssertableInertia $reply) => $reply
                ->where('id', $replyId)
                ->where('review_status', 'returned')
                ->where('review_note', 'Add the annex.')
                ->where('editable', true)
                ->etc())
            ->etc());

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.respondentResponses.replies.create', [$caseId, $response->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Replies/Create')
            ->where('case.case_number', $case->case_number)
            ->where('response.id', $response->id)
            ->where('applicantName', 'Almaz T Bekele')
            ->etc());

    // The older /reply address serves the same form.
    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.respondentResponses.reply', [$caseId, $response->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/Replies/Create')->etc());
});

test('a reply is written, shown, listed, changed and deleted from the reply pages', function (): void {
    $applicant = acpApplicant();
    $caseId = acpCase($applicant, ['review_status' => 'accepted']);
    $case = DB::table('court_cases')->find($caseId);
    $response = acpRespondentResponse($case->case_number);

    $store = $this->actingAs($applicant, 'applicant')->post(route('court.applicant.cases.respondentResponses.replies.store', [$caseId, $response->id]), [
        'description' => 'We contest the defence.',
        'pdf' => UploadedFile::fake()->create('reply.pdf', 100, 'application/pdf'),
    ]);
    $reply = DB::table('court_applicant_response_replies')->where('case_id', $caseId)->where('applicant_id', $applicant->id)->first();
    expect($reply)->not->toBeNull()->and($reply->review_status)->toBe('awaiting_review');
    $store->assertRedirect(route('court.applicant.cases.respondentResponses.replies.show', [$caseId, $response->id, $reply->id]));

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.respondentResponses.replies.show', [$caseId, $response->id, $reply->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Replies/Show')
            ->where('reply.id', $reply->id)
            ->where('reply.description', 'We contest the defence.')
            ->where('reply.review_status', 'awaiting_review')
            ->where('reply.editable', true)
            ->where('reply.has_pdf', true)
            ->where('reply.applicant_name', 'Almaz T Bekele')
            ->where('response.respondent_name', 'Ruth M Tadesse')
            ->etc());

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.response-replies.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicant/Replies/Index')
            ->where('replies.total', 1)
            ->has('replies.data', 1, fn (AssertableInertia $row) => $row
                ->where('id', $reply->id)
                ->where('case_id', $caseId)
                ->where('respondent_response_id', $response->id)
                ->where('case_number', $case->case_number)
                ->where('editable', true)
                ->etc())
            ->etc());

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.respondentResponses.replies.edit', [$caseId, $response->id, $reply->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/Replies/Edit')->where('reply.description', 'We contest the defence.')->etc());

    // The edit form sends POST + _method=patch (multipart); without a new PDF the old one stays.
    $this->actingAs($applicant, 'applicant')->post(route('court.applicant.cases.respondentResponses.replies.update', [$caseId, $response->id, $reply->id]), [
        '_method' => 'patch',
        'description' => 'We contest the defence, with the annex.',
        'pdf' => '',
    ])->assertRedirect(route('court.applicant.cases.respondentResponses.replies.show', [$caseId, $response->id, $reply->id]));
    $updated = DB::table('court_applicant_response_replies')->find($reply->id);
    expect($updated->description)->toBe('We contest the defence, with the annex.')
        ->and($updated->pdf_path)->toBe($reply->pdf_path);

    $this->actingAs($applicant, 'applicant')->delete(route('court.applicant.cases.respondentResponses.replies.destroy', [$caseId, $response->id, $reply->id]))
        ->assertRedirect(route('court.applicant.cases.respondentResponses.show', [$caseId, $response->id]));
    $this->assertDatabaseMissing('court_applicant_response_replies', ['id' => $reply->id]);
});

test('an accepted reply is shown locked and its edit page is refused', function (): void {
    $applicant = acpApplicant();
    $caseId = acpCase($applicant, ['review_status' => 'accepted']);
    $case = DB::table('court_cases')->find($caseId);
    $response = acpRespondentResponse($case->case_number);
    $replyId = acpReply($caseId, $applicant, $response, 'accepted');

    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.respondentResponses.replies.show', [$caseId, $response->id, $replyId]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/Replies/Show')->where('reply.editable', false)->etc());
    $this->actingAs($applicant, 'applicant')->get(route('court.applicant.cases.respondentResponses.replies.edit', [$caseId, $response->id, $replyId]))
        ->assertForbidden();
});

test('an applicant cannot open or act on another applicant\'s case', function (): void {
    $owner = acpApplicant();
    $intruder = acpApplicant();
    $caseId = acpCase($owner, ['review_status' => 'accepted']);
    $case = DB::table('court_cases')->find($caseId);
    $response = acpRespondentResponse($case->case_number);
    $replyId = acpReply($caseId, $owner, $response);

    $as = fn () => $this->actingAs($intruder, 'applicant');

    $as()->get(route('court.applicant.cases.show', $caseId))->assertNotFound();
    $as()->get(route('court.applicant.cases.edit', $caseId))->assertNotFound();
    $as()->get(route('court.applicant.cases.receipt', $caseId))->assertNotFound();
    $as()->get(route('court.applicant.cases.respondentResponses.show', [$caseId, $response->id]))->assertNotFound();
    $as()->get(route('court.applicant.cases.respondentResponses.replies.create', [$caseId, $response->id]))->assertNotFound();
    $as()->get(route('court.applicant.cases.respondentResponses.replies.show', [$caseId, $response->id, $replyId]))->assertNotFound();
    $as()->post(route('court.applicant.cases.messages.post', $caseId), ['body' => 'Hello'])->assertForbidden();
    $as()->post(route('court.applicant.cases.files.upload', $caseId), ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertForbidden();
    $as()->post(route('court.applicant.cases.update', $caseId), ['_method' => 'patch', 'title' => 'Hijack'])->assertNotFound();

    $as()->get(route('court.applicant.cases.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/Cases/Index')->has('cases.data', 0)->etc());
    $as()->get(route('court.applicant.response-replies.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Applicant/Replies/Index')->has('replies.data', 0)->etc());

    $this->assertDatabaseMissing('court_case_messages', ['case_id' => $caseId, 'body' => 'Hello']);
    expect(DB::table('court_cases')->find($caseId)->title)->toBe('Almaz T Bekele');
});
