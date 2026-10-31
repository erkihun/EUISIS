<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\IntegrationClient;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Services\AppealRecordBundle;
use Modules\Court\Application\Services\AppealRecords;
use Modules\Court\Application\Services\PdfNormalizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\StreamReader;

/*
| Appeals to the appellate court (EUISIS ADR 0013 #14): a party to a closed case
| asks for its record, the registry releases it (the case is marked as appealed),
| the party downloads one PDF from the EUISIS proceeding to the decision with the
| documents as annexes, and the appellate outcome scores the panel's judges.
| Flow 7: cases filed against EUISIS institutions are read for their officer.
*/

const APPEAL_ORG = '04c0ffee-0000-7000-8000-000000000001';

function appealToken(array $abilities = IntegrationClient::DEFAULT_ABILITIES): string
{
    return (new IntegrationClient(['name' => 'EUISIS-'.Str::random(6), 'abilities' => $abilities, 'allowed_ips' => []]))->issueToken();
}

/** @return array<string, string> */
function appealEmployee(): array
{
    return [
        'uuid' => (string) Str::uuid(), 'national_id' => (string) random_int(1000000000000000, 9999999999999999),
        'first_name' => 'Meron', 'middle_name' => 'A', 'last_name' => 'Haile', 'gender' => 'female',
        'email' => 'appeal-'.Str::lower(Str::random(6)).'@example.com', 'phone' => '09'.random_int(10000000, 99999999),
    ];
}

function appealStaff(array $permissions): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $role = Role::query()->create(['name' => 'Appeals '.Str::random(6)]);
    $role->permissions()->sync(collect($permissions)->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name], ['label' => Str::headline($name)])->id));
    $user->roles()->sync([$role->id]);

    return $user;
}

function appealPdf(string $text = 'Annex'): string
{
    return Pdf::loadHTML('<p>'.$text.'</p>')->output();
}

function appealPages(string $pdf): int
{
    return (new Fpdi)->setSourceFile(StreamReader::createByString($pdf));
}

/**
 * A one-page PDF as Word or a scanner saves it (PDF 1.5+): the catalog, page tree and page
 * kept in a compressed object stream, found through a cross-reference stream.
 */
function appealObjectStreamPdf(): string
{
    $content = 'BT /F1 12 Tf 20 100 Td (Scanned letter) Tj ET';
    $inner = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Contents 4 0 R /Resources << >> >>'];
    $body = '';
    $header = '';
    foreach ($inner as $i => $object) {
        $header .= ($i + 1).' '.strlen($body).' ';
        $body .= $object."\n";
    }
    $packed = gzcompress($header.$body);
    $pdf = "%PDF-1.7\n";
    $pdf .= "4 0 obj\n<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream\nendobj\n";
    $pdf .= "5 0 obj\n<< /Type /ObjStm /N 3 /First ".strlen($header).' /Filter /FlateDecode /Length '.strlen($packed)." >>\nstream\n{$packed}\nendstream\nendobj\n";
    $xref = strlen($pdf);
    $pdf .= "6 0 obj\n<< /Type /XRef /Size 7 /Root 1 0 R /W [1 4 2] /Length 0 >>\nstream\n\nendstream\nendobj\n";

    return $pdf."startxref\n{$xref}\n%%EOF\n";
}

/** A case filed from EUISIS with a grievance and a discipline case; returns [case id, the three panel judges]. */
function appealCase(object $test, string $token, array $employee): array
{
    $proceeding = fn (string $reference, string $subject) => [[
        'uuid' => (string) Str::uuid(), 'reference' => $reference, 'subject' => $subject, 'status' => 'closed',
        'description' => 'Recorded in EUISIS.', 'submitted_at' => '2026-09-01T09:00:00+03:00', 'closed_at' => '2026-09-20T09:00:00+03:00',
        'responses' => [['body' => 'Letter to the employee.', 'at' => '2026-09-10']],
        'decisions' => [['number' => 'D-1', 'type' => 'Upheld', 'text' => 'The measure stands.', 'at' => '2026-09-20']],
    ]];
    $caseId = (int) $test->withToken($token)->postJson('/court/api/v1/integration/people/cases', [
        'employee' => $employee, 'filing_id' => (string) Str::uuid(),
        'case_type_id' => DB::table('court_case_types')->insertGetId(['name' => 'Labour '.Str::random(4), 'prefix' => 'LAB', 'created_at' => now(), 'updated_at' => now()]),
        'description' => 'Dismissed without a hearing.', 'relief_requested' => 'Reinstatement.',
        'respondent' => ['organization_uuid' => APPEAL_ORG, 'name' => 'Bureau of Finance'],
        'grievances' => json_encode($proceeding('GRV-1', 'Transfer')),
        'disciplines' => json_encode($proceeding('DSC-7', 'Absence charge')),
    ])->assertCreated()->json('data.case_id');

    $judges = collect(range(1, 3))->map(fn () => User::factory()->create(['status' => 'active', 'user_type' => 'judge']));
    DB::table('court_cases')->where('id', $caseId)->update(['review_status' => 'accepted']);

    return [$caseId, $judges];
}

/** Closes the case with a published decision of that panel. */
function appealDecide(int $caseId, $judges): void
{
    DB::table('court_cases')->where('id', $caseId)->update(['status' => 'closed']);
    DB::table('court_case_status_logs')->insert(['case_id' => $caseId, 'from_status' => 'active', 'to_status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);
    Decision::query()->create([
        'court_case_id' => $caseId, 'case_number' => DB::table('court_cases')->where('id', $caseId)->value('case_number'),
        'decision_date' => now()->toDateString(), 'name' => 'Judgment', 'decision_content' => '<p>The claim is dismissed.</p>',
        'panel_judges' => $judges->map(fn (User $judge) => ['admin_user_id' => $judge->id, 'admin_user_name' => $judge->name])->values()->all(),
        'status' => 'published', 'approved_at' => now(),
    ]);
}

beforeEach(function (): void {
    Storage::fake('court_private');
    Mail::fake();
});

test('a party asks to appeal a closed case, the registry releases the record, and the case is marked as appealed', function (): void {
    $token = appealToken();
    $employee = appealEmployee();
    [$caseId, $judges] = appealCase($this, $token, $employee);
    $workspace = fn () => $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/workspace", ['employee_uuid' => $employee['uuid']]);

    // An open case cannot be appealed yet.
    expect($workspace()->assertOk()->json('data.appeal.appealable'))->toBeFalse();
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/appeal", ['employee_uuid' => $employee['uuid'], 'grounds' => 'Wrong in law.'])->assertUnprocessable();

    appealDecide($caseId, $judges);
    expect($workspace()->json('data.appeal.appealable'))->toBeTrue();
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/appeal", ['employee_uuid' => $employee['uuid'], 'grounds' => 'Wrong in law.'])
        ->assertCreated()->assertJsonPath('data.status', 'submitted');
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/appeal", ['employee_uuid' => $employee['uuid'], 'grounds' => 'Again.'])->assertUnprocessable();
    // Until the registry releases it, the record is not the party's to download.
    $this->withToken($token)->postJson("/court/api/v1/integration/people/cases/{$caseId}/download", ['employee_uuid' => $employee['uuid'], 'kind' => 'appeal_record'])->assertForbidden();

    $appeal = DB::table('court_appeals')->where('court_case_id', $caseId)->sole();
    expect($appeal->appellant_type)->toBe('applicant')->and($appeal->requested_via)->toBe('euisis_employee')
        ->and(json_decode($appeal->judge_user_ids, true))->toBe($judges->pluck('id')->all());

    $registrar = appealStaff(['cases.view', 'cases.review', 'appeals.view', 'appeals.decide']);
    $this->actingAs($registrar)->get(route('court.recordes.show', $caseId))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Records/Show')
            ->where('appeal.appealable', true)->where('appeal.rows.0.status', 'submitted')->where('appeal.can.release', true)
            ->where('origins', fn ($origins) => collect($origins)->pluck('kind')->sort()->values()->all() === ['discipline', 'grievance'])
            ->where('parties.0.role', 'lead'));
    $this->actingAs($registrar)->from(route('court.recordes.show', $caseId))->post(route('court.appeals.decide', $appeal->id), ['decision' => 'rejected'])->assertSessionHasErrors('decision_notes');
    $this->actingAs($registrar)->post(route('court.appeals.decide', $appeal->id), ['decision' => 'approved'])->assertRedirect()->assertSessionHasNoErrors();

    $case = DB::table('court_cases')->where('id', $caseId)->first();
    expect($case->appealed_at)->not->toBeNull()
        ->and(DB::table('court_appeals')->where('id', $appeal->id)->value('status'))->toBe('approved');
    expect($workspace()->json('data.appeal.released'))->toBeTrue();
    $download = $this->withToken($token)->post("/court/api/v1/integration/people/cases/{$caseId}/download", ['employee_uuid' => $employee['uuid'], 'kind' => 'appeal_record'])
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with((string) $download->getContent(), '%PDF'))->toBeTrue();

    // The register finds it among the appealed cases.
    $this->actingAs($registrar)->get(route('court.recordes.index', ['appeal' => 'appealed']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('cases.data.0.id', $caseId)->where('cases.data.0.appealed_at', fn ($value) => $value !== null));
});

test('the appeal record merges the documents as annexes and lists the ones it cannot read', function (): void {
    $token = appealToken();
    [$caseId, $judges] = appealCase($this, $token, appealEmployee());
    appealDecide($caseId, $judges);
    $bundle = app(AppealRecordBundle::class);
    $without = appealPages($bundle->build(CourtCase::query()->findOrFail($caseId)));

    Storage::disk('court_private')->put('cases/annex.pdf', appealPdf('The transfer letter'));
    Storage::disk('court_private')->put('cases/broken.pdf', "%PDF-1.4\nnot a real document");
    DB::table('court_case_evidences')->insert([
        ['case_id' => $caseId, 'type' => 'document', 'title' => 'Transfer letter', 'path' => 'cases/annex.pdf', 'file_path' => 'cases/annex.pdf', 'mime' => 'application/pdf', 'created_at' => now(), 'updated_at' => now()],
        ['case_id' => $caseId, 'type' => 'document', 'title' => 'Damaged scan', 'path' => 'cases/broken.pdf', 'file_path' => 'cases/broken.pdf', 'mime' => 'application/pdf', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // One page more: the readable annex; the damaged one is listed as provided separately.
    expect(appealPages($bundle->build(CourtCase::query()->findOrFail($caseId))))->toBe($without + 1);

    // A PDF kept in compressed object streams, which the free FPDI parser cannot read, is rewritten plain and merged.
    $scan = appealObjectStreamPdf();
    expect(fn () => appealPages($scan))->toThrow(CrossReferenceException::class)
        ->and(appealPages((string) PdfNormalizer::normalize($scan)))->toBe(1)
        ->and(PdfNormalizer::normalize('%PDF-1.4 /Encrypt 9 0 R'))->toBeNull();
    Storage::disk('court_private')->put('cases/scan.pdf', $scan);
    DB::table('court_case_evidences')->insert(['case_id' => $caseId, 'type' => 'document', 'title' => 'Scanned letter', 'path' => 'cases/scan.pdf', 'file_path' => 'cases/scan.pdf', 'mime' => 'application/pdf', 'created_at' => now(), 'updated_at' => now()]);
    expect(appealPages($bundle->build(CourtCase::query()->findOrFail($caseId))))->toBe($without + 2);
});

test('the appellate outcome is recorded and scores the judges of the panel in their evaluation', function (): void {
    $token = appealToken();
    $employee = appealEmployee();
    [$caseId, $judges] = appealCase($this, $token, $employee);
    appealDecide($caseId, $judges);
    $registrar = appealStaff(['cases.view', 'cases.review', 'appeals.view', 'appeals.create', 'appeals.decide']);

    // The registry records the institution's request on its behalf.
    $this->actingAs($registrar)->post(route('court.appeals.store'), ['court_case_id' => $caseId, 'appellant_type' => 'respondent', 'appellant_name' => 'Bureau of Finance', 'grounds' => 'The court erred.'])
        ->assertRedirect();
    $appeal = DB::table('court_appeals')->where('court_case_id', $caseId)->sole();
    $this->actingAs($registrar)->from(route('court.recordes.show', $caseId))->post(route('court.appeals.outcome', $appeal->id), ['outcome' => 'reversed', 'outcome_date' => now()->toDateString()])
        ->assertSessionHasErrors('decision');
    $this->actingAs($registrar)->post(route('court.appeals.decide', $appeal->id), ['decision' => 'approved'])->assertSessionHasNoErrors();
    $this->actingAs($registrar)->post(route('court.appeals.outcome', $appeal->id), [
        'outcome' => 'reversed', 'outcome_date' => now()->toDateString(), 'appellate_court' => 'Appellate Bench', 'appellate_case_number' => 'APP-9',
        'document' => UploadedFile::fake()->createWithContent('appellate.pdf', appealPdf('Reversed')),
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(DB::table('court_cases')->where('id', $caseId)->value('appeal_outcome'))->toBe('reversed')
        ->and(DB::table('court_appeals')->where('id', $appeal->id)->value('status'))->toBe('closed');
    $this->actingAs($registrar)->get(route('court.appeals.outcome-document', $appeal->id))->assertOk();

    $period = ['start' => now()->startOfMonth()->toDateString(), 'end' => now()->endOfMonth()->toDateString()];
    expect(AppealRecords::judgeRecord($judges[0]->id, $period['start'], $period['end']))->toBe(['total' => 1, 'confirmed' => 0, 'modified' => 0, 'reversed' => 1, 'score' => 0])
        ->and(AppealRecords::judgeRecord(User::factory()->create()->id, $period['start'], $period['end'])['score'])->toBeNull();

    // The criterion is worked out on save; whatever the form sends for it is ignored.
    $criterion = DB::table('court_performance_evaluation_criteria')->where('source', 'appeal_outcomes')->sole();
    $other = DB::table('court_performance_evaluation_criteria')->whereNull('source')->where('active', true)->first();
    $evaluator = appealStaff(['performance-evaluations.view', 'performance-evaluations.create']);
    $this->actingAs($evaluator)->getJson(route('court.performance-evaluations.appeal-record', ['user' => $judges[1]->id, ...$period]))
        ->assertOk()->assertJsonPath('data.reversed', 1)->assertJsonPath('data.score', 0);
    $this->actingAs($evaluator)->post(route('court.performance-evaluations.store'), [
        'evaluated_user_id' => $judges[1]->id, 'period_type' => 'monthly', 'period_start' => $period['start'], 'period_end' => $period['end'], 'action' => 'submitted',
        'scores' => [['criterion_id' => $other->id, 'score' => 10], ['criterion_id' => $criterion->id, 'score' => 10]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $evaluation = DB::table('court_performance_evaluations')->where('evaluated_user_id', $judges[1]->id)->sole();
    expect((int) DB::table('court_performance_evaluation_scores')->where('evaluation_id', $evaluation->id)->where('criterion_id', $criterion->id)->value('score'))->toBe(0)
        ->and((float) $evaluation->overall_score)->toBe(round(100 * $other->weight / ($other->weight + $criterion->weight), 2));
});

test('institutions read the cases filed against them, follow one, ask to appeal and download the released record', function (): void {
    $token = appealToken();
    [$caseId, $judges] = appealCase($this, $token, appealEmployee());
    $institution = fn (string $path, array $body = []) => $this->withToken($token)->postJson("/api/v1/integration/institutions/{$path}", $body);

    $feed = $institution('cases/new', ['since' => 0])->assertOk()->json();
    $row = collect($feed['data'])->firstWhere('case_id', $caseId);
    expect($row['organization_uuid'])->toBe(APPEAL_ORG)->and($row['applicants'])->toBe(['Meron A Haile'])
        ->and($feed['next_cursor'])->toBeGreaterThanOrEqual($caseId);
    expect($institution('cases/new', ['since' => $feed['next_cursor']])->json('data'))->toBe([]);

    $institution("cases/{$caseId}", ['organization_uuid' => (string) Str::uuid()])->assertNotFound();
    expect($institution("cases/{$caseId}", ['organization_uuid' => APPEAL_ORG])->assertOk()->json('data.appeal.appealable'))->toBeFalse();

    appealDecide($caseId, $judges);
    $institution("cases/{$caseId}/appeal", ['organization_uuid' => APPEAL_ORG, 'grounds' => 'The court erred.'])->assertCreated();
    expect(DB::table('court_appeals')->where('court_case_id', $caseId)->value('appellant_name'))->toBe('Bureau of Finance');
    $institution("cases/{$caseId}/appeal-record", ['organization_uuid' => APPEAL_ORG])->assertForbidden();

    $registrar = appealStaff(['cases.view', 'appeals.view', 'appeals.decide']);
    $this->actingAs($registrar)->post(route('court.appeals.decide', DB::table('court_appeals')->where('court_case_id', $caseId)->value('id')), ['decision' => 'approved'])->assertSessionHasNoErrors();
    $this->withToken($token)->post("/court/api/v1/integration/institutions/cases/{$caseId}/appeal-record", ['organization_uuid' => APPEAL_ORG])
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // A token without the institution abilities reads none of it.
    $this->withToken(appealToken([IntegrationClient::ABILITY_PEOPLE_READ]))->postJson('/court/api/v1/integration/institutions/cases/new', ['since' => 0])->assertForbidden();
});
