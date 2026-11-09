<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/*
| My Portal > My court cases (court-ms Flows 4 and 6, ADR 0013): the employee's
| tribunal cases read from court-ms, and each case followed and answered here;
| court-ms's own applicant portal is closed.
*/

function courtPortalEmployee(array $attributes = []): array
{
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-CRT-'.random_int(1000, 9999), 'first_name' => 'Meron', 'middle_name' => 'A', 'last_name' => 'Haile',
        'full_name' => 'Meron A Haile', 'gender' => 'female', 'status' => EmployeeStatus::Active,
        'email' => 'meron-'.random_int(1000, 9999).'@example.test', 'phone' => '0911'.random_int(100000, 999999),
        ...$attributes,
    ]);
    $employee->national_id = '1234567890123456';
    $employee->save();
    $user = User::factory()->create(['status' => 'active', 'employee_id' => $employee->id]);

    return [$employee, $user];
}

beforeEach(function (): void {
    config([
        'court_ms.enabled' => true, 'court_ms.url' => 'https://court.test', 'court_ms.token' => 'cms_test-token',
        'court_ms.employee_portal' => true,
    ]);
});

test('my court cases shows the employee\'s cases read from court-ms', function (): void {
    [$employee, $user] = courtPortalEmployee();
    Http::fake(['https://court.test/api/v1/integration/people/court-summary' => Http::response(['data' => [
        'linked' => true,
        'counts' => ['cases' => 1, 'open' => 1, 'awaiting_review' => 0, 'upcoming_hearings' => 1, 'unread_notices' => 2],
        'cases' => [['id' => 7, 'case_number' => 'CIV-00007-26', 'title' => 'Meron A Haile', 'respondent' => 'Bureau of Finance', 'case_type' => 'Civil', 'status' => 'active', 'review_status' => 'accepted', 'filed_at' => now()->toIso8601String(), 'next_hearing_at' => now()->addDays(3)->toIso8601String()]],
        'notices' => [['id' => 'hearing:3', 'title' => 'Hearing: CIV-00007-26', 'message' => 'Preliminary · Room 1', 'created_at' => now()->toIso8601String()]],
    ]])]);

    $this->actingAs($user)->get(route('employee.court-cases'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Employee/CourtCases')
            ->where('state', 'linked')
            ->where('summary.counts.unread_notices', 2)
            ->where('summary.cases.0.case_number', 'CIV-00007-26')
            ->where('summary.cases.0.respondent', 'Bureau of Finance')
            ->where('features.court_portal', true));

    // court-ms is asked with the employee's UUID and national ID, by POST, so the ID is never in a URL.
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/people/court-summary')
        && $request['employee']['uuid'] === (string) $employee->id
        && $request['employee']['national_id'] === '1234567890123456'
        // court-ms words the notices in the language the page is read in.
        && in_array($request['locale'], ['en', 'am'], true)
        && $request->hasHeader('Authorization', 'Bearer cms_test-token'));
});

test('an employee without a court account is told filing creates it, and court-ms down is said plainly', function (): void {
    [, $user] = courtPortalEmployee();
    Http::fake(['https://court.test/api/v1/integration/people/court-summary' => Http::sequence()
        ->push(['data' => ['linked' => false]])
        ->push(['message' => 'down'], 503)]);

    $this->actingAs($user)->get(route('employee.court-cases'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'not_linked')->where('summary', null));

    cache()->flush();
    $this->actingAs($user)->get(route('employee.court-cases'))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'unavailable'));
});

test('the section, and every case page, is off unless configured', function (): void {
    [, $user] = courtPortalEmployee();
    Http::fake();
    config(['court_ms.employee_portal' => false]);

    $this->actingAs($user)->get(route('employee.court-cases'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'disabled')->where('features.court_portal', false));
    $this->actingAs($user)->get(route('employee.court-cases.show', 7))->assertNotFound();
    Http::assertNothingSent();
});

test('an account without an employee record has no court cases', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    Http::fake();

    $this->actingAs($user)->get(route('employee.court-cases'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'no_employee'));
    Http::assertNothingSent();
});

/*
| Flow 6: the employee works on a case here; every step is a court-ms call naming
| them by UUID in the body, and court-ms's own rules answer it.
*/

function courtWorkspace(array $overrides = []): array
{
    return [
        'case' => ['id' => 7, 'case_number' => 'CIV-00007-26', 'status' => 'pending', 'review_status' => 'returned', 'case_type' => 'Civil', 'filed_at' => now()->toIso8601String(),
            'respondent_name' => 'Bureau of Finance', 'respondent_address' => null, 'description_html' => '<p>What happened</p>', 'relief_html' => null],
        'docs' => [['id' => 4, 'title' => 'Letter', 'created_at' => null, 'has_file' => true]],
        'witnesses' => [], 'hearings' => [], 'letters' => [], 'decisions' => [], 'respondentResponses' => [],
        'messages' => [['id' => 1, 'body' => 'Please attach the decision.', 'mine' => false, 'sender_name' => 'Registrar', 'created_at' => null]],
        'files' => [], 'review' => ['status' => 'returned', 'note' => 'Attach the decision.'], 'can' => ['modify' => true],
        ...$overrides,
    ];
}

test('a case is shown from court-ms, asked for by the employee, never by a URL id', function (): void {
    [$employee, $user] = courtPortalEmployee();
    Http::fake(['https://court.test/api/v1/integration/people/cases/7/workspace' => Http::response(['data' => courtWorkspace()])]);

    $this->actingAs($user)->get(route('employee.court-cases.show', 7))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Employee/CourtCaseShow')
            ->where('workspace.case.case_number', 'CIV-00007-26')
            ->where('workspace.review.note', 'Attach the decision.')
            ->where('workspace.can.modify', true)
            ->where('error', null));

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request['employee_uuid'] === (string) $employee->id
        && $request->hasHeader('Authorization', 'Bearer cms_test-token'));
});

test('a case that is not the employee\'s is not found, and court-ms down is explained on the list', function (): void {
    [, $user] = courtPortalEmployee();
    Http::fake([
        'https://court.test/api/v1/integration/people/cases/8/workspace' => Http::response(['message' => 'Not found.', 'error_code' => 'not_found'], 404),
        'https://court.test/api/v1/integration/people/cases/9/workspace' => Http::response(['message' => 'down'], 503),
    ]);

    $this->actingAs($user)->get(route('employee.court-cases.show', 8))->assertNotFound();
    $this->actingAs($user)->get(route('employee.court-cases.show', 9))
        ->assertRedirect(route('employee.court-cases'))->assertSessionHas('court_error', 'unavailable');
});

test('a message goes to court-ms, the summary is read again, and court-ms\'s refusals come back', function (): void {
    [$employee, $user] = courtPortalEmployee();
    cache()->put("court-ms:employee-summary:{$employee->id}:en", ['linked' => true], 300);
    Http::fake(['https://court.test/api/v1/integration/people/cases/7/messages' => Http::sequence()
        ->push(['data' => ['ok' => true, 'message' => 'Message sent.']])
        ->push(['message' => 'The given data was invalid.', 'errors' => ['body' => ['The body may not be greater than 5000 characters.']]], 422)
        ->push(['message' => 'Closed.', 'error_code' => 'refused'], 409)]);

    $this->actingAs($user)->from(route('employee.court-cases.show', 7))->post(route('employee.court-cases.messages.store', 7), ['body' => 'Attached now.'])
        ->assertRedirect(route('employee.court-cases.show', 7))->assertSessionHas('court_done', 'messageSent');
    Http::assertSent(fn (HttpRequest $request): bool => $request['employee_uuid'] === (string) $employee->id && $request['body'] === 'Attached now.');
    expect(cache()->get("court-ms:employee-summary:{$employee->id}:en"))->toBeNull();

    $this->actingAs($user)->from(route('employee.court-cases.show', 7))->post(route('employee.court-cases.messages.store', 7), ['body' => 'Again'])
        ->assertSessionHasErrors('body');
    $this->actingAs($user)->from(route('employee.court-cases.show', 7))->post(route('employee.court-cases.messages.store', 7), ['body' => 'Again'])
        ->assertSessionHas('court_error', 'refused');

    // Checked here first: nothing empty is sent.
    $this->actingAs($user)->post(route('employee.court-cases.messages.store', 7), ['body' => ''])->assertSessionHasErrors('body');
    Http::assertSentCount(3);
});

test('a document is uploaded as a file part and downloaded back with a safe name', function (): void {
    [, $user] = courtPortalEmployee();
    Http::fake([
        'https://court.test/api/v1/integration/people/cases/7/files' => Http::response(['data' => ['ok' => true]]),
        'https://court.test/api/v1/integration/people/cases/7/download' => Http::response('%PDF-1.4 receipt', 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="receipt CIV-00007.pdf"',
        ]),
    ]);

    $this->actingAs($user)->from(route('employee.court-cases.show', 7))->post(route('employee.court-cases.files.store', 7), [
        'label' => 'Decision', 'file' => UploadedFile::fake()->createWithContent('decision.pdf', '%PDF-1.4 decision'),
    ])->assertSessionHas('court_done', 'fileUploaded');
    Http::assertSent(fn (HttpRequest $request): bool => $request->isMultipart()
        && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'file' && $part['filename'] === 'decision.pdf'));

    $response = $this->actingAs($user)->get(route('employee.court-cases.download', ['case' => 7, 'kind' => 'receipt']));
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="receipt_CIV-00007.pdf"')
        ->and($response->getContent())->toStartWith('%PDF');
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/cases/7/download') && $request['kind'] === 'receipt');

    $this->actingAs($user)->get(route('employee.court-cases.download', ['case' => 7, 'kind' => 'audit']))->assertNotFound();
});

test('a reply to a respondent response is sent with its PDF, opened for changes and saved back to the response', function (): void {
    [, $user] = courtPortalEmployee();
    $page = ['case' => ['id' => 7, 'case_number' => 'CIV-00007-26'], 'response' => ['id' => 3, 'response_number' => 'R-3', 'respondent_name' => 'Abebe K', 'has_pdf' => true,
        'title' => 'Response', 'description_html' => '<p>We disagree.</p>', 'created_at' => null],
        'replies' => [['id' => 5, 'review_status' => 'awaiting_review', 'review_note' => null, 'excerpt' => 'My reply', 'created_at' => null, 'editable' => true]]];
    Http::fake([
        'https://court.test/api/v1/integration/people/cases/7/responses/3' => Http::response(['data' => $page]),
        'https://court.test/api/v1/integration/people/cases/7/responses/3/replies/5' => Http::response(['data' => ['reply' => ['id' => 5, 'description' => 'My full reply', 'editable' => true, 'has_pdf' => true]]]),
        'https://court.test/api/v1/integration/people/cases/7/responses/3/replies' => Http::response(['data' => ['ok' => true]]),
        'https://court.test/api/v1/integration/people/cases/7/responses/3/replies/5/update' => Http::response(['data' => ['ok' => true]]),
    ]);

    $this->actingAs($user)->get(route('employee.court-cases.responses.show', [7, 3]).'?edit=5')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Employee/CourtCaseResponse')
        ->where('page.response.response_number', 'R-3')
        ->where('editing.description', 'My full reply'));

    $this->actingAs($user)->post(route('employee.court-cases.replies.store', [7, 3]), ['description' => 'No PDF'])->assertSessionHasErrors('pdf');
    $this->actingAs($user)->from(route('employee.court-cases.responses.show', [7, 3]))->post(route('employee.court-cases.replies.store', [7, 3]), [
        'description' => 'My reply', 'pdf' => UploadedFile::fake()->create('reply.pdf', 10, 'application/pdf'),
    ])->assertSessionHas('court_done', 'replySent');

    $this->actingAs($user)->from(route('employee.court-cases.responses.show', [7, 3]).'?edit=5')->post(route('employee.court-cases.replies.update', [7, 3, 5]), ['description' => 'Changed'])
        ->assertRedirect(route('employee.court-cases.responses.show', [7, 3]))->assertSessionHas('court_done', 'replyUpdated');
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/replies/5/update') && $request['description'] === 'Changed');
});

test('a returned case is corrected with the values court-ms holds for what is not changed here', function (): void {
    [, $user] = courtPortalEmployee();
    $edit = ['case' => ['id' => 7, 'case_number' => 'CIV-00007-26', 'title' => 'Meron A Haile', 'case_type_id' => 2, 'filing_date' => '2026-10-20',
        'respondent_name' => 'Bureau of Finance', 'respondent_address' => '', 'description' => '<p>Old</p>', 'relief_requested' => '<p>Old relief</p>'],
        'editable' => true, 'types' => [], 'docs' => [], 'witnesses' => []];
    Http::fake([
        'https://court.test/api/v1/integration/people/cases/7/edit' => Http::response(['data' => $edit]),
        'https://court.test/api/v1/integration/people/cases/7/correct' => Http::response(['data' => ['ok' => true]]),
    ]);

    $this->actingAs($user)->get(route('employee.court-cases.correct', 7))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Employee/CourtCaseCorrect')->where('form.case.description', '<p>Old</p>'));

    $this->actingAs($user)->post(route('employee.court-cases.correct.store', 7), ['description' => '<p>New</p>', 'relief_requested' => '<p>Relief</p>'])
        ->assertSessionHasErrors(['certify_appeal', 'certify_evidence']);
    $this->actingAs($user)->post(route('employee.court-cases.correct.store', 7), [
        'description' => '<p>New</p>', 'relief_requested' => '<p>Relief</p>', 'certify_appeal' => '1', 'certify_evidence' => '1',
        'witnesses' => [['full_name' => 'Sara T', 'phone' => '0911000000'], ['full_name' => '']],
        'evidence_titles' => ['Decision'], 'evidence_files' => [UploadedFile::fake()->create('decision.pdf', 10, 'application/pdf')],
    ])->assertRedirect(route('employee.court-cases.show', 7))->assertSessionHas('court_done', 'caseCorrected');

    Http::assertSent(function (HttpRequest $request): bool {
        if (! str_ends_with($request->url(), '/cases/7/correct')) {
            return false;
        }
        $fields = collect($request->data())->mapWithKeys(fn (array $part): array => [$part['name'] => $part['contents']]);

        return $fields['title'] === 'Meron A Haile' && $fields['case_type_id'] === '2' && $fields['filing_date'] === '2026-10-20'
            && $fields['description'] === '<p>New</p>' && $fields['witnesses[0][full_name]'] === 'Sara T' && ! $fields->has('witnesses[1][full_name]')
            && $fields['evidence_titles[0]'] === 'Decision' && $fields->has('evidence_files[0]');
    });
});

test('a case that may no longer be corrected sends the employee back to it', function (): void {
    [, $user] = courtPortalEmployee();
    Http::fake(['https://court.test/api/v1/integration/people/cases/7/edit' => Http::response(['data' => ['case' => ['id' => 7], 'editable' => false]])]);

    $this->actingAs($user)->get(route('employee.court-cases.correct', 7))->assertRedirect(route('employee.court-cases.show', 7));
});

test('a notice, or all of them, is marked read in court-ms', function (): void {
    [$employee, $user] = courtPortalEmployee();
    Http::fake(['https://court.test/api/v1/integration/people/notices/read' => Http::response(['data' => ['ok' => true]])]);

    $this->actingAs($user)->from(route('employee.court-cases'))->post(route('employee.court-cases.notices.read'), ['id' => 'hearing:3'])
        ->assertRedirect(route('employee.court-cases'));
    $this->actingAs($user)->post(route('employee.court-cases.notices.read'), ['all' => true])->assertRedirect();
    $this->actingAs($user)->post(route('employee.court-cases.notices.read'), ['id' => 'not a key'])->assertSessionHasErrors('id');

    Http::assertSent(fn (HttpRequest $request): bool => $request['employee_uuid'] === (string) $employee->id && ($request->data()['id'] ?? null) === 'hearing:3');
    Http::assertSent(fn (HttpRequest $request): bool => ($request->data()['all'] ?? null) === true);
    Http::assertSentCount(2);
});
