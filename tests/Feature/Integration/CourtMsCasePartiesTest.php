<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\GrievanceScenario;

/*
| Several parties to a court case (ADR 0013 #13, docs/integration/court-ms.md):
| an employee files with colleagues as co-applicants and through a lawyer from
| the court's register; each co-applicant, and the client of a lawyer who filed,
| confirms in their own My Portal; an employee applies to intervene in another
| employee's case, and only the judge of the case decides.
*/

const COURT_PARTIES_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

beforeEach(function (): void {
    config([
        'court_ms.enabled' => true, 'court_ms.url' => 'https://court.test', 'court_ms.token' => 'cms_test-token',
        'court_ms.employee_portal' => true,
    ]);
    $this->s = GrievanceScenario::build();
});

function courtPartiesFake(array $extra = []): void
{
    Http::fake([
        ...$extra,
        'court.test/api/v1/integration/case-types' => Http::response(['data' => [['id' => 3, 'name' => 'Administrative', 'prefix' => 'ADM']]]),
        'court.test/api/v1/integration/people/lawyers' => Http::response(['data' => [['name' => 'Dawit K Tadesse', 'license_number' => 'LIC-1001']]]),
        'court.test/api/v1/integration/people/cases' => Http::response(['data' => ['case_id' => 9, 'case_number' => 'ADM/00009/26', 'review_status' => 'awaiting_review', 'status' => 'pending']], 201),
    ]);
}

function courtPartiesPdf(string $name = 'document.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, COURT_PARTIES_PDF);
}

test('the filing form offers the court\'s register of lawyers', function (): void {
    courtPartiesFake();

    $this->actingAs($this->s->complainant)->get(route('employee.court-cases.file'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Employee/CourtCaseFile')
            ->where('lawyers', [['name' => 'Dawit K Tadesse', 'license_number' => 'LIC-1001']]));
});

test('a case is filed with colleagues as co-applicants and a lawyer with the power of attorney', function (): void {
    courtPartiesFake();
    $colleague = $this->s->chair->employee;

    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.file.store'), [
        'filing_id' => (string) str()->uuid(), 'case_type_id' => 3, 'description' => 'The transfer was unlawful.',
        'organization_id' => $this->s->bureau->id,
        'co_applicants' => [$colleague->employee_number],
        'lawyer_license_number' => 'LIC-1001',
        'power_of_attorney' => courtPartiesPdf('poa.pdf'),
    ])->assertRedirect(route('employee.court-cases'))->assertSessionHas('court_filed', 'ADM/00009/26');

    Http::assertSent(function (HttpRequest $request) use ($colleague): bool {
        if (! str_ends_with($request->url(), '/people/cases')) {
            return false;
        }
        $fields = collect($request->data())->filter(fn ($part) => ! isset($part['filename']))->mapWithKeys(fn ($part) => [$part['name'] => (string) $part['contents']]);
        $files = collect($request->data())->filter(fn ($part) => isset($part['filename']))->keyBy('name');

        // The colleague is named by their EUISIS id, never by the number typed.
        return $fields['co_applicants[0][uuid]'] === (string) $colleague->id
            && $fields['co_applicants[0][name]'] === $colleague->fullNameEn()
            && $fields['lawyer_license_number'] === 'LIC-1001'
            && $files->has('power_of_attorney')
            && str_starts_with((string) $files['power_of_attorney']['contents'], '%PDF');
    });
});

test('an unknown colleague, oneself, a lawyer outside the register or a missing power of attorney is refused before court-ms is called', function (): void {
    courtPartiesFake();
    $own = $this->s->complainant->employee->employee_number;
    $form = fn (array $overrides): array => [
        'filing_id' => (string) str()->uuid(), 'case_type_id' => 3, 'description' => 'D', 'organization_id' => $this->s->bureau->id, ...$overrides,
    ];

    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.file.store'), $form(['co_applicants' => ['NO-SUCH-1']]))
        ->assertSessionHasErrors(['co_applicants.0' => __('employee-portal.court.colleague_not_found', ['number' => 'NO-SUCH-1'])]);
    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.file.store'), $form(['co_applicants' => [$own]]))
        ->assertSessionHasErrors(['co_applicants.0' => __('employee-portal.court.colleague_is_you')]);
    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.file.store'), $form(['lawyer_license_number' => 'LIC-9999', 'power_of_attorney' => courtPartiesPdf()]))
        ->assertSessionHasErrors('lawyer_license_number');
    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.file.store'), $form(['lawyer_license_number' => 'LIC-1001']))
        ->assertSessionHasErrors('power_of_attorney');

    Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/people/cases'));
});

test('the form checks a colleague by number and shows their name only', function (): void {
    $colleague = $this->s->chair->employee;

    $this->actingAs($this->s->complainant)->postJson(route('employee.court-cases.colleague'), ['employee_number' => $colleague->employee_number])
        ->assertOk()->assertExactJson(['data' => ['employee_number' => $colleague->employee_number, 'name' => $colleague->fullNameEn()]]);
    $this->actingAs($this->s->complainant)->postJson(route('employee.court-cases.colleague'), ['employee_number' => 'NO-SUCH-1'])->assertNotFound();
    $this->actingAs($this->s->complainant)->postJson(route('employee.court-cases.colleague'), ['employee_number' => $this->s->complainant->employee->employee_number])
        ->assertNotFound()->assertJsonPath('message', __('employee-portal.court.colleague_is_you'));
});

test('invitations are listed, and joining opens the case while declining returns to the list', function (): void {
    $invitation = ['id' => 41, 'case_id' => 9, 'case_number' => 'ADM/00009/26', 'case_type' => 'Administrative', 'respondent' => 'Bureau of Finance',
        'role' => 'co_applicant', 'status' => 'invited', 'invited_by' => 'Meron A Haile', 'lawyer' => null, 'decision_note' => null, 'decided_at' => null, 'created_at' => now()->toIso8601String()];
    courtPartiesFake([
        'court.test/api/v1/integration/people/court-summary' => Http::response(['data' => ['linked' => false]]),
        'court.test/api/v1/integration/people/parties' => Http::response(['data' => ['invitations' => [$invitation], 'interventions' => []]]),
        'court.test/api/v1/integration/people/parties/41/respond' => Http::sequence()
            ->push(['data' => ['ok' => true, 'case_id' => 9, 'status' => 'active']])
            ->push(['data' => ['ok' => true, 'case_id' => 9, 'status' => 'declined']])
            ->push(['message' => 'This invitation has already been answered.', 'errors' => ['invitation' => ['This invitation has already been answered.']]], 422),
    ]);
    $user = $this->s->complainant;

    // Even before the employee has a court account: answering it opens one.
    $this->actingAs($user)->get(route('employee.court-cases'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'not_linked')->where('parties.invitations.0.id', 41));

    $this->actingAs($user)->post(route('employee.court-cases.invitations.respond', 41), ['accept' => true])
        ->assertRedirect(route('employee.court-cases.show', 9))->assertSessionHas('court_done', 'invitationAccepted');
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/parties/41/respond')
        && $request['accept'] === true && $request['employee']['uuid'] === (string) $user->employee_id);

    $this->actingAs($user)->post(route('employee.court-cases.invitations.respond', 41), ['accept' => false])
        ->assertRedirect(route('employee.court-cases'))->assertSessionHas('court_done', 'invitationDeclined');
    $this->actingAs($user)->from(route('employee.court-cases'))->post(route('employee.court-cases.invitations.respond', 41), ['accept' => true])
        ->assertSessionHasErrors('invitation');
});

test('an employee applies to intervene with their grounds and a PDF; court-ms refusals come back on the form', function (): void {
    courtPartiesFake([
        'court.test/api/v1/integration/people/interventions' => Http::sequence()
            ->push(['data' => ['ok' => true, 'id' => 52, 'status' => 'requested']], 201)
            ->push(['message' => 'No case has this number.', 'error_code' => 'unknown_case', 'errors' => ['case_number' => ['No case has this number.']]], 422),
    ]);
    $user = $this->s->complainant;

    $this->actingAs($user)->get(route('employee.court-cases.intervene', ['case' => 'ADM/00009/26']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Employee/CourtCaseIntervene')->where('caseNumber', 'ADM/00009/26'));

    $this->actingAs($user)->post(route('employee.court-cases.intervene.store'), [
        'case_number' => 'ADM/00009/26', 'statement' => 'The same transfer moved me too.', 'document' => courtPartiesPdf('order.pdf'),
    ])->assertRedirect(route('employee.court-cases'))->assertSessionHas('court_done', 'interventionSent');
    Http::assertSent(function (HttpRequest $request) use ($user): bool {
        if (! str_ends_with($request->url(), '/people/interventions')) {
            return false;
        }
        $parts = collect($request->data())->keyBy('name');

        return (string) $parts['case_number']['contents'] === 'ADM/00009/26'
            && (string) $parts['employee[uuid]']['contents'] === (string) $user->employee_id
            && isset($parts['document']['filename']);
    });

    $this->actingAs($user)->from(route('employee.court-cases.intervene'))->post(route('employee.court-cases.intervene.store'), ['case_number' => 'NO-SUCH-1', 'statement' => 'x'])
        ->assertRedirect(route('employee.court-cases.intervene'))->assertSessionHasErrors(['case_number' => 'No case has this number.']);
});
