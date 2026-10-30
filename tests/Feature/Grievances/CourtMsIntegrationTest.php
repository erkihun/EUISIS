<?php

declare(strict_types=1);

use App\Jobs\Grievances\SendCourtMsReferral;
use App\Models\AdministrativeTribunalCase;
use App\Models\User;
use App\Services\CourtMs\CourtMsException;
use App\Services\CourtMs\CourtMsReferrals;
use App\Services\CourtMs\CourtMsStatusSync;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Modules\Grievance\Application\Services\GrievanceCaseService;
use Modules\Grievance\Application\Services\GrievanceEscalationService;
use Modules\Grievance\Contracts\Data\GrievanceRoles;
use Modules\Grievance\Domain\Models\Grievance;
use Modules\Grievance\Domain\Models\GrievanceCaseEvent;
use Modules\Grievance\Domain\Models\GrievanceEvidence;
use Modules\Grievance\Domain\Models\GrievanceExternalAuthority;
use Tests\Support\GrievanceScenario;

// court-ms integration, EUISIS side (ADR 0013, docs/integration/court-ms.md).

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    Storage::fake('local');
    config(['court_ms.enabled' => true, 'court_ms.url' => 'https://court.test', 'court_ms.token' => 'cms_test-token']);
    $this->s = GrievanceScenario::build();
});

afterEach(fn () => Carbon::setTestNow());

/** A grievance filed with a PDF, accepted, and referred to the tribunal; returns its tribunal case and an officer who handles it. */
function courtMsReferredCase(object $test): array
{
    $test->actingAs($test->s->complainant)->post(route('employee.grievances.store'), [
        'subject' => 'Transfer decision contested',
        'description' => 'The committee upheld the transfer.',
        'category_id' => $test->s->category->id,
        'submit' => true,
        'files' => [UploadedFile::fake()->create('committee-decision.pdf', 20, 'application/pdf')],
        'file_types' => ['document'],
        ...GrievanceScenario::application(),
    ])->assertRedirect();
    $grievance = Grievance::query()->latest('created_at')->firstOrFail();
    app(GrievanceCaseService::class)->intakeAccept($grievance, $test->s->intake, null);

    $tribunal = GrievanceExternalAuthority::query()->create([
        'code' => 'TRB-'.str()->upper(str()->random(6)), 'name_en' => 'Administrative Tribunal',
        'organization_id' => $test->s->bureau->id, 'is_administrative_tribunal' => true, 'is_active' => true,
    ]);
    $route = GrievanceScenario::route('committee', $test->s->committee->id, 'external_authority', $tribunal->id, 'referred');
    app(GrievanceEscalationService::class)->refer($grievance->refresh(), $test->s->chair, 'Tribunal referral.', $route->id);

    $officer = GrievanceScenario::user(GrievanceScenario::employee($test->s->woreda, null), GrievanceRoles::TRIBUNAL_PERMISSIONS);
    GrievanceScenario::scope($officer, $test->s->woreda);

    return [AdministrativeTribunalCase::query()->where('grievance_id', $grievance->id)->sole(), $officer, $grievance->refresh()];
}

function courtMsFakeCourt(int $referralStatus = 201): void
{
    Http::fake([
        'court.test/api/v1/integration/case-types' => Http::response(['data' => [['id' => 3, 'name' => 'Administrative', 'prefix' => 'ADM']]]),
        'court.test/api/v1/integration/referrals' => $referralStatus < 300
            ? Http::response(['data' => ['referral_id' => 'x', 'case_id' => 41, 'case_number' => 'ADM/00007/26', 'code' => '12345', 'review_status' => 'awaiting_review', 'status' => 'pending']], $referralStatus)
            : Http::response(['message' => 'The given data was invalid.', 'errors' => ['case_type_id' => ['The selected case type id is invalid.']]], $referralStatus),
    ]);
}

function courtMsForm(array $evidenceIds = []): array
{
    return [
        'case_type_id' => 3, 'title' => 'Transfer decision contested', 'summary' => 'The committee upheld the transfer; the employee appeals.',
        'respondent_name' => 'Woreda Administration', 'complainant_phone' => '0911000000', 'evidence_ids' => $evidenceIds,
    ];
}

it('offers the referral form with court-ms case types and the grievance PDFs, and queues one referral', function (): void {
    courtMsFakeCourt();
    Bus::fake([SendCourtMsReferral::class]);
    [$case, $officer, $grievance] = courtMsReferredCase($this);
    $pdf = GrievanceEvidence::query()->where('grievance_id', $grievance->id)->sole();

    $this->actingAs($officer)->get(route('tribunal-cases.show', $case))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('AdministrativeTribunal/Show')
            ->where('can.refer', true)
            ->where('courtMs.enabled', true)
            ->where('courtMs.case_types.0.id', 3)
            ->where('courtMs.evidence.0.id', $pdf->id)
            ->where('courtMs.defaults.title', 'Transfer decision contested'));

    $this->actingAs($officer)->post(route('tribunal-cases.referral', $case), courtMsForm([$pdf->id]))
        ->assertRedirect(route('tribunal-cases.show', $case));
    $case->refresh();
    expect($case->referral_state)->toBe('queued')
        ->and($case->referred_by_user_id)->toBe($officer->id)
        ->and($case->referral_payload['evidence_ids'])->toBe([$pdf->id]);
    Bus::assertDispatched(SendCourtMsReferral::class, fn ($job) => $job->tribunalCaseId === $case->id);
    expect(GrievanceCaseEvent::query()->where('grievance_id', $grievance->id)->where('event', 'court_ms_referral_queued')->exists())->toBeTrue();

    // A queued referral cannot be sent a second time.
    $this->actingAs($officer)->post(route('tribunal-cases.referral', $case), courtMsForm())->assertSessionHasErrors('referral');
    Bus::assertDispatchedTimes(SendCourtMsReferral::class, 1);
});

it('sends the referral with the grievance UUID as its key and records the court-ms case number', function (): void {
    courtMsFakeCourt();
    Bus::fake([SendCourtMsReferral::class]);
    [$case, $officer, $grievance] = courtMsReferredCase($this);
    $pdf = GrievanceEvidence::query()->where('grievance_id', $grievance->id)->sole();
    app(CourtMsReferrals::class)->queue($case, $officer, courtMsForm([$pdf->id]));

    app(CourtMsReferrals::class)->send($case->refresh());

    $case->refresh();
    expect($case->referral_state)->toBe('sent')
        ->and($case->court_case_number)->toBe('ADM/00007/26')
        ->and($case->court_case_id)->toBe(41)
        ->and($case->court_review_status)->toBe('awaiting_review')
        ->and($case->referral_attempts)->toBe(1)
        ->and(GrievanceCaseEvent::query()->where('grievance_id', $grievance->id)->where('event', 'court_ms_referred')->exists())->toBeTrue();

    Http::assertSent(function (HttpRequest $request) use ($grievance): bool {
        if (! str_ends_with($request->url(), '/api/v1/integration/referrals')) {
            return false;
        }
        $parts = collect($request->data())->keyBy('name');

        return $request->isMultipart()
            && $request->hasHeader('Authorization', 'Bearer cms_test-token')
            && $parts['referral_id']['contents'] === $grievance->id
            && $parts['complainant[employee_uuid]']['contents'] === $grievance->employee_id
            && $parts['respondent[organization_uuid]']['contents'] === $grievance->organization_id
            && $parts['document_titles[0]']['contents'] !== ''
            && $parts->has('documents[0]');
    });
});

it('marks a refused referral failed for a person to correct, and keeps retrying while court-ms is down', function (): void {
    // First answer: the content is refused; second: court-ms is down.
    Http::fake(['court.test/api/v1/integration/referrals' => Http::sequence()
        ->push(['message' => 'The given data was invalid.', 'errors' => ['case_type_id' => ['The selected case type id is invalid.']]], 422)
        ->push(['message' => 'Service unavailable'], 503)]);
    Bus::fake([SendCourtMsReferral::class]);
    [$case, $officer] = courtMsReferredCase($this);
    $referrals = app(CourtMsReferrals::class);
    $referrals->queue($case, $officer, courtMsForm());

    expect(fn () => $referrals->send($case->refresh()))->toThrow(CourtMsException::class);
    expect($case->refresh()->referral_state)->toBe('failed')->and($case->referral_error)->toContain('422');

    // Corrected and sent again: allowed once it failed.
    $this->actingAs($officer)->post(route('tribunal-cases.referral', $case), courtMsForm())->assertRedirect();
    expect(fn () => $referrals->send($case->refresh()))->toThrow(CourtMsException::class);
    expect($case->refresh()->referral_state)->toBe('queued')->and($case->referral_attempts)->toBe(1);
});

it('follows the court case: intake review, hearing and decision reach the tribunal register and the grievance history', function (): void {
    [$case, , $grievance] = courtMsReferredCase($this);
    $case->forceFill(['referral_state' => 'sent', 'court_case_number' => 'ADM/00007/26', 'court_review_status' => 'awaiting_review', 'court_status' => 'pending'])->save();

    Http::fake([
        // The poll also reads court letters (Flow 5) and new cases against institutions (Flow 7); none here.
        'court.test/api/v1/integration/letters/updates*' => Http::response(['data' => [], 'next_cursor' => 0, 'has_more' => false]),
        'court.test/api/v1/integration/institutions/cases/new' => Http::response(['data' => [], 'next_cursor' => 0, 'has_more' => false]),
        'court.test/api/v1/integration/referrals/updates?since=0*' => Http::response(['data' => [[
            'cursor' => 5, 'referral_id' => $grievance->id, 'case_number' => 'ADM/00007/26', 'review_status' => 'accepted', 'review_reason' => null,
            'status' => 'active', 'hearing_at' => '2026-10-20 09:30:00', 'decision_date' => null, 'decision_summary' => null,
            'changed' => ['review_status', 'status', 'hearing_at'],
        ]], 'next_cursor' => 5, 'has_more' => false]),
        'court.test/api/v1/integration/referrals/updates?since=5*' => Http::response(['data' => [[
            'cursor' => 6, 'referral_id' => $grievance->id, 'case_number' => 'ADM/00007/26', 'review_status' => 'accepted', 'review_reason' => null,
            'status' => 'closed', 'hearing_at' => '2026-10-20 09:30:00', 'decision_date' => '2026-10-27', 'decision_summary' => 'The transfer is set aside.',
            'changed' => ['status', 'decision_date', 'decision_summary'],
        ]], 'next_cursor' => 6, 'has_more' => false]),
    ]);

    $this->artisan('court-ms:poll')->expectsOutputToContain('1 read, 1 applied')->assertSuccessful();
    $case->refresh();
    expect($case->status)->toBe('hearing')
        ->and($case->hearing_date->toDateString())->toBe('2026-10-20')
        ->and($case->court_review_status)->toBe('accepted')
        ->and(app(CourtMsStatusSync::class)->state(CourtMsStatusSync::CURSOR_KEY))->toBe('5');

    $this->artisan('court-ms:poll')->assertSuccessful();
    $case->refresh();
    expect($case->status)->toBe('closed')
        ->and($case->decision_date->toDateString())->toBe('2026-10-27')
        ->and($case->decision_summary)->toBe('The transfer is set aside.')
        ->and($case->court_status)->toBe('closed');

    $events = GrievanceCaseEvent::query()->where('grievance_id', $grievance->id)->pluck('event')->all();
    expect($events)->toContain('court_ms_review_changed', 'court_ms_hearing_scheduled', 'court_ms_decision_issued', 'court_ms_case_closed');
});

it('refuses a referral while the connection is off, and the poll does nothing', function (): void {
    config(['court_ms.enabled' => false]);
    Http::fake();
    [$case, $officer] = courtMsReferredCase($this);

    $this->actingAs($officer)->post(route('tribunal-cases.referral', $case), courtMsForm())->assertStatus(409);
    $this->artisan('court-ms:poll')->expectsOutputToContain('integration is off')->assertSuccessful();
    Http::assertNothingSent();
    expect($case->refresh()->referral_state)->toBeNull();
});

it('turns the stored grievance HTML into plain text for the tribunal summary', function (): void {
    expect(CourtMsReferrals::plainText('<p>First line<br>second &amp; more</p><p>Next paragraph</p>'))
        ->toBe("First line\nsecond & more\n\nNext paragraph");
});

it('keeps people without the tribunal grant out of the referral', function (): void {
    courtMsFakeCourt();
    [$case] = courtMsReferredCase($this);
    $outsider = User::factory()->create(['status' => 'active']);

    $this->actingAs($outsider)->post(route('tribunal-cases.referral', $case), courtMsForm())->assertForbidden();
    expect($case->refresh()->referral_state)->toBeNull();
});
