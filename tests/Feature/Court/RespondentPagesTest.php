<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\LetterTemplate;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/** Someone who answers cases: the applicant account they sign in with, and its respondent record. */
function respondentPagesPerson(string $first = 'Ruth'): Applicant
{
    $suffix = Str::lower(Str::random(8));
    $applicant = Applicant::create([
        'first_name' => $first, 'middle_name' => 'K', 'last_name' => 'Bekele', 'gender' => 'female',
        'position' => 'Manager', 'organization_name' => 'Example Org', 'phone' => '09'.random_int(10000000, 99999999),
        'email' => "respondent-{$suffix}@example.com", 'address' => 'Addis Ababa', 'national_id_number' => (string) random_int(1000000000000000, 9999999999999999),
        'password' => Hash::make('password'), 'is_active' => true, 'is_lawyer' => false,
    ]);
    $applicant->markEmailAsVerified();

    Respondent::create([
        'first_name' => $first, 'middle_name' => 'K', 'last_name' => 'Bekele', 'gender' => 'female', 'position' => 'Manager',
        'organization_name' => 'Example Org', 'address' => 'Addis Ababa', 'phone' => $applicant->phone,
        'email' => $applicant->email, 'password' => Hash::make('password'),
    ]);

    return $applicant;
}

/** A case another applicant filed, with its access code. @return array{id: int, number: string, code: string, owner: Applicant} */
function respondentPagesCase(string $status = 'pending', array $extra = []): array
{
    $owner = respondentPagesPerson('Owner'.Str::upper(Str::random(3)));
    $typeId = DB::table('court_case_types')->insertGetId(['name' => 'Civil '.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    $number = 'CASE-RP-'.Str::upper(Str::random(6));
    $code = 'AB'.random_int(1000, 9999);
    $id = DB::table('court_cases')->insertGetId([
        'applicant_id' => $owner->id, 'case_number' => $number, 'code' => $code, 'title' => 'Land dispute',
        'case_type_id' => $typeId, 'filing_date' => now()->toDateString(), 'status' => $status, 'review_status' => 'accepted',
        'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);

    return ['id' => $id, 'number' => $number, 'code' => $code, 'owner' => $owner];
}

function respondentPagesRecordView(Applicant $person, array $case): void
{
    DB::table('court_respondent_case_views')->insert([
        'respondent_id' => Respondent::where('email', $person->email)->value('id'), 'case_id' => $case['id'],
        'case_number' => $case['number'], 'viewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('the dashboard shows the counts, recent cases, approved letters and replies inside the respondent portal shell', function (): void {
    $person = respondentPagesPerson();
    $case = respondentPagesCase();
    respondentPagesRecordView($person, $case);
    $respondentId = (int) Respondent::where('email', $person->email)->value('id');

    $author = User::factory()->create(['name' => 'Registrar One']);
    $template = LetterTemplate::query()->create(['title' => 'Summons', 'body' => '<p>Summons</p>']);
    $letterBase = ['letter_template_id' => $template->id, 'user_id' => $author->id, 'recipient_name' => 'Ruth', 'body' => '<p>Come</p>', 'case_number' => $case['number'], 'created_at' => now(), 'updated_at' => now()];
    DB::table('court_letters')->insert([...$letterBase, 'subject' => 'Hearing summons', 'reference_number' => 'REF-'.Str::random(6), 'approval_status' => 'approved']);
    DB::table('court_letters')->insert([...$letterBase, 'subject' => 'Draft letter', 'reference_number' => 'REF-'.Str::random(6), 'approval_status' => 'draft']);

    $response = RespondentResponse::create(['respondent_id' => $respondentId, 'case_number' => $case['number'], 'response_number' => "\u{1218}/{$case['number']}", 'title' => 'My answer', 'pdf_path' => 'respondent/responses/a.pdf']);
    $replyId = DB::table('court_applicant_response_replies')->insertGetId([
        'case_id' => $case['id'], 'applicant_id' => $case['owner']->id, 'respondent_response_id' => $response->id,
        'description' => "Our reply\nsecond line", 'pdf_path' => 'applicant/response-replies/r.pdf', 'review_status' => 'accepted',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($person, 'applicant')->get(route('court.respondent.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Respondent/Dashboard')
            ->where('stats.cases', 1)
            ->where('stats.responses', 1)
            ->where('stats.response_replies', 1)
            ->where('stats.notifications', 1)
            ->where('recentCases.0.case_number', $case['number'])
            ->where('recentCases.0.show_url', route('court.respondent.cases.show', $case['number']))
            ->has('letters', 1)
            ->where('letters.0.subject', 'Hearing summons')
            ->where('letters.0.author_name', 'Registrar One')
            ->where('letters.0.preview_url', fn (string $url): bool => str_contains($url, '/court/case-letters/') && str_contains($url, 'signature='))
            ->where('responseReplies.0.id', $replyId)
            ->where('responseReplies.0.applicant_name', fn (string $name): bool => str_starts_with($name, 'Owner'))
            // The Blade header bell's list, now on the home page.
            ->where('notifications.count', 1)
            ->where('notifications.items.0.type', 'respondent_view')
            ->where('notifications.items.0.case_number', $case['number'])
            // The shared portal props of the shell.
            ->where('portal.name', 'respondent')
            ->where('portal.home', 'respondent.dashboard')
            ->where('portal.logout', 'court.respondent.logout')
            ->where('auth.user.email', $person->email)
            ->where('workspace.home', 'respondent.dashboard')
            ->where('workspace.nav', fn ($nav): bool => collect($nav)->pluck('groups')->flatten(1)->pluck('items')->flatten(1)->pluck('route')->contains('court.respondent.case.search')));

    $viewId = (int) DB::table('court_respondent_case_views')->where('case_id', $case['id'])->value('id');
    $this->actingAs($person, 'applicant')->from(route('court.respondent.dashboard'))
        ->post(route('court.respondent.notifications.markOne'), ['type' => 'respondent_view', 'sourceId' => $viewId])
        ->assertRedirect(route('court.respondent.dashboard'));
    $this->actingAs($person, 'applicant')->get(route('court.respondent.dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('notifications.count', 0)->where('notifications.items', [])->where('stats.notifications', 0));

    $this->actingAs($person, 'applicant')->get(route('court.respondent.response-replies.show', $replyId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Respondent/ResponseReplies/Show')
            ->where('reply.case_number', $case['number'])
            ->where('reply.response_number', "\u{1218}/{$case['number']}")
            ->where('reply.description', "Our reply\nsecond line")
            ->missing('reply.pdf_path')
            ->where('portal.name', 'respondent'));
});

test('searching a case by number and access code opens it and lists it under my cases', function (): void {
    $person = respondentPagesPerson();
    $case = respondentPagesCase();

    $this->actingAs($person, 'applicant')->get(route('court.respondent.case.search', ['case_number' => 'NO-SUCH-CASE', 'case_code' => 'X']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Respondent/CaseSearch')->where('case', null)->where('codeError', false));

    $this->actingAs($person, 'applicant')->get(route('court.respondent.case.search', ['case_number' => $case['number'], 'case_code' => 'WRONG']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('case', null)->where('codeError', true)->where('caseNumber', $case['number']));
    $this->assertDatabaseCount('court_respondent_case_views', 0);

    $this->actingAs($person, 'applicant')->get(route('court.respondent.case.search', ['case_number' => $case['number'], 'case_code' => $case['code']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Respondent/CaseSearch')
            ->where('codeError', false)
            ->where('case.case_number', $case['number'])
            ->where('case.status', 'pending')
            // The session was granted access, so the link carries no access code.
            ->where('case.show_url', route('court.respondent.cases.show', ['caseNumber' => $case['number']]))
            ->missing('case.code')
            ->missing('case.applicant_id')
            ->where('portal.name', 'respondent'));
    $this->assertDatabaseHas('court_respondent_case_views', ['case_id' => $case['id'], 'case_number' => $case['number']]);

    $this->actingAs($person, 'applicant')->get(route('court.respondent.cases.my'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Respondent/MyCases')
            ->has('cases', 1)
            ->where('cases.0.case_number', $case['number'])
            ->where('cases.0.status', 'pending')
            ->where('cases.0.show_url', fn (string $url): bool => str_starts_with($url, route('court.respondent.cases.show', $case['number']))));

    // The applicant-prefixed routes render the same pages in the respondent shell. "My cases" is registered
    // before the catch-all case route, which used to read "my" as a case number and answer 404.
    $this->actingAs($person, 'applicant')->get(route('court.applicant.respondent.cases.my'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Respondent/MyCases')->has('cases', 1));
    $this->actingAs($person, 'applicant')->get(route('court.applicant.respondent.cases.search'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Respondent/CaseSearch')->where('case', null)->where('portal.name', 'respondent'));
    $this->actingAs($person, 'applicant')->get(route('court.applicant.respondent.responses.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Respondent/Responses/Index')->where('portal.name', 'respondent'));
});

test('the case page shows the respondent only what it may see, and records the view', function (): void {
    $person = respondentPagesPerson();
    $case = respondentPagesCase('active', ['description' => '<p>The facts</p><script>alert(1)</script>', 'relief_requested' => '<p>Return the land</p>']);
    respondentPagesRecordView($person, $case);

    DB::table('court_case_hearings')->insert(['case_id' => $case['id'], 'hearing_at' => now()->addWeek(), 'location' => 'Courtroom 2', 'type' => 'Preliminary', 'created_at' => now(), 'updated_at' => now()]);
    $docId = DB::table('court_case_evidences')->insertGetId(['case_id' => $case['id'], 'type' => 'document', 'title' => 'Title deed', 'file_path' => 'cases/deed.pdf', 'created_at' => now(), 'updated_at' => now()]);
    $decision = ['court_case_id' => $case['id'], 'case_number' => $case['number'], 'decision_content' => '<p>Ruling</p>', 'decision_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()];
    $publishedId = DB::table('court_decisions')->insertGetId([...$decision, 'name' => 'Final ruling', 'status' => 'published', 'approved_at' => now()]);
    DB::table('court_decisions')->insert([...$decision, 'name' => 'Draft ruling', 'status' => 'draft']);

    $this->actingAs($person, 'applicant')->get(route('court.respondent.cases.show', $case['number']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Respondent/CaseShow')
            ->where('case.case_number', $case['number'])
            ->where('case.status', 'active')
            ->where('case.review_status', 'accepted')
            ->where('case.description_html', fn (string $html): bool => str_contains($html, 'The facts') && ! str_contains($html, '<script'))
            ->where('case.relief_requested_html', fn (string $html): bool => str_contains($html, 'Return the land'))
            ->missing('case.code')
            ->missing('case.applicant_record_phone')
            ->where('docs.0.id', $docId)
            ->where('docs.0.title', 'Title deed')
            ->where('docs.0.download_url', route('court.applicant.cases.evidences.download', ['id' => $case['id'], 'evidenceId' => $docId]))
            ->missing('docs.0.file_path')
            ->where('hearings.0.location', 'Courtroom 2')
            ->has('decisions', 1)
            ->where('decisions.0.id', $publishedId)
            ->where('decisions.0.download_url', route('court.respondent.decisions.download', $publishedId))
            ->where('letters', [])
            ->where('witnesses', [])
            ->where('audits', [])
            ->missing('timeline')
            ->missing('msgs')
            ->where('portal.name', 'respondent'));

    $this->assertDatabaseHas('court_case_audits', ['case_id' => $case['id'], 'action' => 'respondent_viewed', 'actor_type' => 'respondent']);

    // A person never answers their own case.
    $this->actingAs($case['owner'], 'applicant')->get(route('court.respondent.cases.show', ['caseNumber' => $case['number'], 'case_code' => $case['code']]))
        ->assertNotFound();
});

test('a response is filed, listed, shown, edited and deleted from the page payloads', function (): void {
    Storage::fake('court_private');
    $person = respondentPagesPerson();
    $case = respondentPagesCase();
    respondentPagesRecordView($person, $case);

    $create = $this->actingAs($person, 'applicant')->get(route('court.respondent.responses.create', ['case_number' => $case['number']]));
    $create->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Court/Respondent/Responses/Form')
        ->where('response', null)
        ->where('caseNumber', $case['number'])
        ->where('portal.name', 'respondent'));
    $props = $create->viewData('page')['props'];

    $this->actingAs($person, 'applicant')->post(route('court.respondent.responses.store'), [
        'title' => 'Statement of defence',
        'case_number' => $props['caseNumber'],
        'description' => '<p>We deny the claim.</p>',
        'pdf' => UploadedFile::fake()->createWithContent('defence.pdf', '%PDF-1.4 defence'),
    ])->assertSessionHasNoErrors()->assertRedirect();

    $response = RespondentResponse::query()->where('title', 'Statement of defence')->firstOrFail();
    expect($response->case_number)->toBe($case['number'])
        ->and($response->response_number)->toBe("\u{1218}/{$case['number']}");
    Storage::disk('court_private')->assertExists($response->pdf_path);

    $this->actingAs($person, 'applicant')->get(route('court.respondent.responses.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Respondent/Responses/Index')
            ->has('responses', 1)
            ->where('responses.0.id', $response->id)
            ->where('responses.0.has_pdf', true)
            ->where('responses.0.locked', false)
            ->where('responses.0.review_status', 'awaiting_review')
            ->missing('responses.0.pdf_path'));

    $this->actingAs($person, 'applicant')->get(route('court.respondent.responses.show', $response))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Respondent/Responses/Show')
            ->where('response.title', 'Statement of defence')
            ->where('response.description', fn (string $html): bool => str_contains($html, 'We deny the claim.')));

    $edit = $this->actingAs($person, 'applicant')->get(route('court.respondent.responses.edit', $response));
    $edit->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Respondent/Responses/Form')->where('response.id', $response->id)->where('caseNumber', $case['number']));
    $editing = $edit->viewData('page')['props'];

    // The page sends its PATCH as a multipart POST with _method; no new PDF keeps the stored one.
    $storedPdf = $response->pdf_path;
    $this->actingAs($person, 'applicant')->post(route('court.respondent.responses.update', $response), [
        '_method' => 'patch',
        'title' => 'Amended statement',
        'case_number' => $editing['caseNumber'],
        'description' => $editing['response']['description'],
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.respondent.responses.show', $response));
    expect($response->fresh()->title)->toBe('Amended statement')
        ->and($response->fresh()->pdf_path)->toBe($storedPdf);

    // Another person's response stays out of reach.
    $stranger = respondentPagesPerson('Stranger');
    $this->actingAs($stranger, 'applicant')->get(route('court.respondent.responses.edit', $response))->assertForbidden();

    $this->actingAs($person, 'applicant')->delete(route('court.respondent.responses.destroy', $response))
        ->assertRedirect(route('court.respondent.responses.index'));
    $this->assertDatabaseMissing('court_respondent_responses', ['id' => $response->id]);
    Storage::disk('court_private')->assertMissing($storedPdf);

    // An accepted response is read only on the list.
    RespondentResponse::create(['respondent_id' => Respondent::where('email', $person->email)->value('id'), 'case_number' => $case['number'], 'title' => 'Accepted one', 'pdf_path' => 'respondent/responses/x.pdf', 'review_status' => 'accepted']);
    $this->actingAs($person, 'applicant')->get(route('court.respondent.responses.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('responses.0.title', 'Accepted one')->where('responses.0.locked', true));
});

test('a closed case takes no response', function (): void {
    Storage::fake('court_private');
    $person = respondentPagesPerson();
    $case = respondentPagesCase('closed');
    respondentPagesRecordView($person, $case);

    $this->actingAs($person, 'applicant')->from(route('court.respondent.responses.create', ['case_number' => $case['number']]))
        ->post(route('court.respondent.responses.store'), [
            'title' => 'Too late', 'case_number' => $case['number'],
            'pdf' => UploadedFile::fake()->createWithContent('late.pdf', '%PDF-1.4 late'),
        ])
        ->assertSessionHasErrors('case_number');
    $this->assertDatabaseMissing('court_respondent_responses', ['title' => 'Too late']);
});

test('the profile saves what its page payload holds', function (): void {
    $person = respondentPagesPerson();

    $page = $this->actingAs($person, 'applicant')->get(route('court.respondent.profile.edit'));
    $page->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('Court/Respondent/Profile')
        ->where('tab', 'profile')
        ->where('user.first_name', 'Ruth')
        ->where('user.lawyer_document_url', null)
        ->where('portal.name', 'respondent')
        ->has('workspace.nav'));
    $user = $page->viewData('page')['props']['user'];

    $payload = collect($user)->except(['is_lawyer', 'lawyer_document_url'])->all();
    $this->actingAs($person, 'applicant')->from(route('court.respondent.profile.edit'))
        ->patch(route('court.respondent.profile.update'), [...$payload, 'address' => 'Adama', 'position' => 'Director'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.respondent.profile.edit'));

    expect($person->fresh()->address)->toBe('Adama')
        ->and($person->fresh()->position)->toBe('Director')
        ->and(Respondent::where('email', $person->email)->value('address'))->toBe('Adama');

    // A failed password change reopens the security tab.
    $this->actingAs($person->fresh(), 'applicant')->from(route('court.respondent.profile.edit'))
        ->patch(route('court.respondent.profile.password'), ['current_password' => 'wrong', 'password' => 'NewPassword1!', 'password_confirmation' => 'NewPassword1!'])
        ->assertSessionHasErrors('current_password');
    $this->actingAs($person->fresh(), 'applicant')->get(route('court.respondent.profile.edit'))
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('tab', 'security'));
});
