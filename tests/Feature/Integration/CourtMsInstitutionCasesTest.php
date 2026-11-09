<?php

declare(strict_types=1);

use App\Models\CourtCaseOfficer;
use App\Models\InstitutionCourtCase;
use App\Notifications\InstitutionCourtCaseNotification;
use App\Services\CourtMs\CourtMsStatusSync;
use App\Services\CourtMs\InstitutionCourtCaseInbox;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Modules\Discipline\Domain\Models\DisciplinaryCase;
use Modules\Discipline\Domain\Models\DisciplinaryCharge;
use Modules\Discipline\Domain\Models\DisciplinaryDecision;
use Modules\Grievance\Contracts\Data\GrievanceRoles;
use Tests\Support\GrievanceScenario;

/*
| Flow 7 and appeals (ADR 0013 #14, docs/integration/court-ms.md): every case filed
| at the administrative tribunal against an institution here reaches, by itself,
| the officer the institution named to follow its court cases; once a case is
| closed, a party asks for its record to take to the appellate court, and the
| record released by the court's registry is downloaded here as one PDF.
*/

const COURT_APPEAL_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

beforeEach(function (): void {
    config([
        'court_ms.enabled' => true, 'court_ms.url' => 'https://court.test', 'court_ms.token' => 'cms_test-token',
        'court_ms.employee_portal' => true,
    ]);
    $this->s = GrievanceScenario::build();
    $this->officer = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, $this->s->directorate), GrievanceRoles::TRIBUNAL_PERMISSIONS);
    GrievanceScenario::scope($this->officer, $this->s->bureau);
});

/** A court-ms row for a new case against an institution. */
function institutionCaseRow(int $id, string $organization): array
{
    return [
        'cursor' => $id, 'case_id' => $id, 'case_number' => "ADM/000{$id}/26", 'organization_uuid' => $organization, 'respondent' => 'Bureau',
        'applicants' => ['Meron A Haile'], 'case_type' => 'Administrative', 'filed_at' => '2026-10-20', 'status' => 'pending',
        'review_status' => 'awaiting_review', 'source' => 'lawyer',
    ];
}

function institutionPollFake(array $rows): void
{
    Http::fake([
        'court.test/api/v1/integration/referrals/updates*' => Http::response(['data' => [], 'next_cursor' => 0, 'has_more' => false]),
        'court.test/api/v1/integration/letters/updates*' => Http::response(['data' => [], 'next_cursor' => 0, 'has_more' => false]),
        'court.test/api/v1/integration/institutions/cases/new' => Http::sequence()
            ->push(['data' => $rows, 'next_cursor' => (int) collect($rows)->max('cursor'), 'has_more' => false])
            ->push(['data' => [], 'next_cursor' => (int) collect($rows)->max('cursor'), 'has_more' => false]),
    ]);
}

test('the poll registers each new case against an institution and gives it to the institution\'s court case officer', function (): void {
    Notification::fake();
    CourtCaseOfficer::query()->create(['organization_id' => $this->s->bureau->id, 'user_id' => $this->officer->id]);
    // The woreda names nobody: its tribunal officers are told so that one takes the case.
    $woredaOfficer = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::TRIBUNAL_PERMISSIONS);
    GrievanceScenario::scope($woredaOfficer, $this->s->woreda);
    institutionPollFake([institutionCaseRow(41, $this->s->bureau->id), institutionCaseRow(42, $this->s->woreda->id), institutionCaseRow(43, (string) str()->uuid())]);

    $this->artisan('court-ms:poll')->expectsOutputToContain('court-ms cases against institutions: 3 read, 2 registered.')->assertSuccessful();
    $this->artisan('court-ms:poll')->expectsOutputToContain('court-ms cases against institutions: 0 read, 0 registered.')->assertSuccessful();

    $bureauCase = InstitutionCourtCase::query()->where('court_case_id', 41)->sole();
    expect($bureauCase->officer_user_id)->toBe($this->officer->id)->and($bureauCase->applicants)->toBe(['Meron A Haile'])
        ->and(InstitutionCourtCase::query()->where('court_case_id', 42)->value('officer_user_id'))->toBeNull()
        ->and(InstitutionCourtCase::query()->count())->toBe(2)
        ->and(app(CourtMsStatusSync::class)->state(InstitutionCourtCaseInbox::CURSOR_KEY))->toBe('43');
    Notification::assertSentTo($this->officer, InstitutionCourtCaseNotification::class, fn (InstitutionCourtCaseNotification $n) => $n->kind === 'assigned' && $n->caseNumber === 'ADM/00041/26');
    Notification::assertSentTo($woredaOfficer, InstitutionCourtCaseNotification::class, fn (InstitutionCourtCaseNotification $n) => $n->kind === 'unassigned');
    Notification::assertNotSentTo($woredaOfficer, InstitutionCourtCaseNotification::class, fn (InstitutionCourtCaseNotification $n) => $n->caseNumber === 'ADM/00041/26');
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/institutions/cases/new') && (int) ($request->data()['since'] ?? -1) === 43);
});

test('naming the institution\'s officer gives them the cases waiting without one, and the officers page shows who follows which institution', function (): void {
    Notification::fake();
    $waiting = InstitutionCourtCase::query()->create(['court_case_id' => 51, 'organization_id' => $this->s->bureau->id, 'case_number' => 'ADM/00051/26', 'received_at' => now()]);

    $this->actingAs($this->officer)->get(route('tribunal-cases.institution-cases.case-officers'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('AdministrativeTribunal/CourtCaseOfficers')
            ->where('organizations', fn ($rows) => ($row = collect($rows)->firstWhere('id', $this->s->bureau->id)) !== null && $row['can_manage'] && collect($row['candidates'])->pluck('id')->contains($this->officer->id)));
    // Someone who cannot act for the institution is not offered as its officer.
    $this->actingAs($this->officer)->post(route('tribunal-cases.institution-cases.case-officers.store'), ['organization_id' => $this->s->bureau->id, 'user_id' => $this->s->outsider->id])
        ->assertSessionHasErrors('user_id');
    $this->actingAs($this->officer)->post(route('tribunal-cases.institution-cases.case-officers.store'), ['organization_id' => $this->s->bureau->id, 'user_id' => $this->officer->id])
        ->assertSessionHasNoErrors();

    expect(CourtCaseOfficer::query()->where('organization_id', $this->s->bureau->id)->value('user_id'))->toBe($this->officer->id)
        ->and($waiting->refresh()->officer_user_id)->toBe($this->officer->id);
    // An institution outside the officer's scope is not theirs to set.
    $this->actingAs($this->officer)->post(route('tribunal-cases.institution-cases.case-officers.store'), ['organization_id' => $this->s->woreda->id, 'user_id' => $this->officer->id])->assertForbidden();
});

test('the officer follows a case from the court, reassigns it, asks to appeal and downloads the released record', function (): void {
    Notification::fake();
    $case = InstitutionCourtCase::query()->create([
        'court_case_id' => 41, 'organization_id' => $this->s->bureau->id, 'case_number' => 'ADM/00041/26', 'applicants' => ['Meron A Haile'],
        'officer_user_id' => $this->officer->id, 'received_at' => now(),
    ]);
    $colleague = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, $this->s->directorate), GrievanceRoles::TRIBUNAL_PERMISSIONS);
    GrievanceScenario::scope($colleague, $this->s->bureau);
    Http::fake([
        'court.test/api/v1/integration/institutions/cases/41' => Http::response(['data' => [
            'case' => ['id' => 41, 'case_number' => 'ADM/00041/26', 'case_type' => 'Administrative', 'status' => 'closed', 'review_status' => 'accepted', 'filed_at' => '2026-10-20', 'appealed_at' => null, 'appeal_outcome' => null, 'merged_into' => null],
            'applicants' => [['name' => 'Meron A Haile', 'role' => 'lead']],
            'hearings' => [['at' => '2026-10-22T09:00:00+03:00', 'type' => 'Main', 'location' => 'Room 2']],
            'decision' => ['title' => 'Judgment', 'decided_at' => '2026-10-27'],
            'appeal' => ['appealable' => true, 'requests' => [], 'released' => true],
        ]]),
        'court.test/api/v1/integration/institutions/cases/41/appeal' => Http::response(['data' => ['ok' => true, 'appeal_number' => 'APL-2026-0001', 'status' => 'submitted']], 201),
        'court.test/api/v1/integration/institutions/cases/41/appeal-record' => Http::response(COURT_APPEAL_PDF, 200, ['Content-Type' => 'application/pdf']),
    ]);

    $this->actingAs($this->officer)->get(route('tribunal-cases.institution-cases.index', ['show' => 'mine']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('AdministrativeTribunal/InstitutionCases')->where('cases.data.0.case_number', 'ADM/00041/26'));
    $this->actingAs($this->s->complainant)->get(route('tribunal-cases.institution-cases.index'))->assertForbidden();

    $this->actingAs($this->officer)->get(route('tribunal-cases.institution-cases.show', $case))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('AdministrativeTribunal/InstitutionCaseShow')
            ->where('court.case.status', 'closed')->where('court.appeal.appealable', true)->where('can.manage', true)
            ->where('officers', fn ($officers) => collect($officers)->pluck('id')->contains($colleague->id)));

    $this->actingAs($this->officer)->post(route('tribunal-cases.institution-cases.assign', $case), ['officer_user_id' => $colleague->id])->assertSessionHasNoErrors();
    expect($case->refresh()->officer_user_id)->toBe($colleague->id);
    Notification::assertSentTo($colleague, InstitutionCourtCaseNotification::class, fn (InstitutionCourtCaseNotification $n) => $n->kind === 'assigned');

    $this->actingAs($colleague)->post(route('tribunal-cases.institution-cases.appeal', $case), ['grounds' => 'The court misread the regulation.'])->assertSessionHasNoErrors();
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/institutions/cases/41/appeal')
        && $request->data()['organization_uuid'] === $this->s->bureau->id && $request->data()['grounds'] === 'The court misread the regulation.');

    $download = $this->actingAs($colleague)->get(route('tribunal-cases.institution-cases.appeal-record', $case))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($download->getContent())->toBe(COURT_APPEAL_PDF);
});

test('an employee asks to appeal from My Portal and downloads the released record, and a discipline case goes with a filing', function (): void {
    $employee = $this->s->complainant->employee;
    Http::fake([
        'court.test/api/v1/integration/case-types' => Http::response(['data' => [['id' => 3, 'name' => 'Administrative', 'prefix' => 'ADM']]]),
        'court.test/api/v1/integration/people/lawyers' => Http::response(['data' => []]),
        'court.test/api/v1/integration/people/cases' => Http::response(['data' => ['case_id' => 9, 'case_number' => 'ADM/00009/26', 'review_status' => 'awaiting_review', 'status' => 'pending']], 201),
        'court.test/api/v1/integration/people/cases/9/appeal' => Http::response(['data' => ['ok' => true, 'appeal_number' => 'APL-2026-0002', 'status' => 'submitted']], 201),
        'court.test/api/v1/integration/people/cases/9/download' => Http::response(COURT_APPEAL_PDF, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="appeal-record-ADM-00009-26.pdf"']),
    ]);

    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.appeal.store', 9), ['grounds' => 'Wrong in law.'])
        ->assertRedirect()->assertSessionHas('court_done', 'appealRequested');
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/people/cases/9/appeal')
        && $request->data()['employee_uuid'] === (string) $employee->id && $request->data()['grounds'] === 'Wrong in law.');

    $this->actingAs($this->s->complainant)->get(route('employee.court-cases.download', ['case' => 9, 'kind' => 'appeal_record']))->assertOk();
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/people/cases/9/download') && $request->data()['kind'] === 'appeal_record');

    // A decided discipline case of the employee's own travels with the filing; another employee's is refused.
    $discipline = DisciplinaryCase::query()->forceCreate([
        'case_number' => 'DC-2026-7', 'accused_employee_id' => $employee->id, 'organization_id' => $this->s->woreda->id,
        'track' => 'expedited', 'severity' => 'minor', 'status' => 'closed', 'summary' => 'Absence from duty', 'created_by' => $this->s->chair->id,
        'charged_at' => now()->subMonth(), 'decided_at' => now()->subWeek(), 'delivered_at' => now()->subWeek(),
    ]);
    DisciplinaryCharge::query()->forceCreate(['case_id' => $discipline->id, 'sequence' => 1, 'title' => 'Unexcused absence', 'particulars' => 'Three days in October.', 'occurred_on' => now()->subMonths(2)->toDateString(), 'violated_provision' => 'Art. 68(1)', 'occurred_place' => 'Woreda office', 'evidence_list' => 'Attendance sheet']);
    DisciplinaryDecision::query()->forceCreate(['case_id' => $discipline->id, 'action' => 'approve', 'finding' => 'responsible', 'penalty' => 'written_warning',
        'reason' => 'The absence is proved.', 'decided_by' => $this->s->chair->id, 'decided_at' => now()->subWeek(), 'delivered_at' => now()->subWeek()]);
    $other = DisciplinaryCase::query()->forceCreate([
        'case_number' => 'DC-2026-8', 'accused_employee_id' => $this->s->chair->employee_id, 'organization_id' => $this->s->woreda->id,
        'track' => 'expedited', 'severity' => 'minor', 'status' => 'closed', 'summary' => 'Not theirs', 'created_by' => $this->s->chair->id, 'delivered_at' => now(),
    ]);

    $this->actingAs($this->s->complainant)->get(route('employee.court-cases.file'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('disciplines', fn ($rows) => collect($rows)->pluck('id')->all() === [(string) $discipline->id]));
    $form = fn (array $ids): array => ['filing_id' => (string) str()->uuid(), 'case_type_id' => 3, 'description' => 'The penalty was unlawful.', 'organization_id' => $this->s->bureau->id, 'discipline_ids' => $ids];
    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.file.store'), $form([(string) $other->id]))->assertSessionHasErrors('discipline_ids');
    $this->actingAs($this->s->complainant)->post(route('employee.court-cases.file.store'), $form([(string) $discipline->id]))->assertSessionHas('court_filed', 'ADM/00009/26');

    Http::assertSent(function (HttpRequest $request) use ($discipline): bool {
        if (! str_ends_with($request->url(), '/people/cases')) {
            return false;
        }
        $fields = collect($request->data())->filter(fn ($part) => ! isset($part['filename']))->mapWithKeys(fn ($part) => [$part['name'] => (string) $part['contents']]);
        $sent = json_decode($fields['disciplines'] ?? '[]', true);

        return count($sent) === 1 && $sent[0]['uuid'] === (string) $discipline->id && $sent[0]['reference'] === 'DC-2026-7'
            && $sent[0]['subject'] === 'Unexcused absence — Art. 68(1)' && $sent[0]['decisions'][0]['text'] === 'The absence is proved.';
    });
});
