<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Actions\GrantExtension;
use Modules\ServiceMonitoring\Application\Actions\RequestCorrection;
use Modules\ServiceMonitoring\Application\Actions\RevokeExtension;
use Modules\ServiceMonitoring\Application\Notifications\SubmissionReturnedNotification;
use Modules\ServiceMonitoring\Application\Services\ComplianceService;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\SubmissionStateMachine;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;
use Modules\ServiceMonitoring\Domain\Models\MonitoringSetting;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Modules\ServiceMonitoring\Domain\Models\SubmissionHistory;
use Tests\Support\ServiceMonitoringScenario;

/*
 * Submission core (phase 2): the state machine (D9), "የለም" (D10),
 * attestation and the stamped copy (D11), the deadline and extensions (D6).
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->s = ServiceMonitoringScenario::build();
    $this->s->at(1);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('opens one draft per participant and period, snapshotting the form, and is idempotent', function (): void {
    $head = $this->s->head('wA1');
    $period = $this->s->period(1);

    $this->actingAs($head)->post(route('service-monitoring.submissions.start', [$period, $this->s->p['wA1']]))->assertRedirect();
    $this->actingAs($head)->post(route('service-monitoring.submissions.start', [$period, $this->s->p['wA1']]))->assertRedirect();

    $submission = Submission::query()->sole();
    expect($submission->status)->toBe(SubmissionStatus::Draft)
        ->and($submission->form_type->value)->toBe('B')
        ->and($submission->level->value)->toBe('woreda')
        ->and($submission->organization_id)->toBe($this->s->org['wA1']->id)
        ->and($submission->histories()->count())->toBe(1);
    expect($this->s->start('center')->form_type->value)->toBe('A');

    // Not before the period opens.
    $this->s->clock('2026-09-01');
    $this->actingAs($head)->post(route('service-monitoring.submissions.start', [$period, $this->s->p['wA1']]))->assertRedirect();
    $this->actingAs($this->s->head('wA2'))->post(route('service-monitoring.submissions.start', [$period, $this->s->p['wA2']]))->assertSessionHasErrors('period');
});

it('refuses submission until every section is complete or marked "የለም"', function (): void {
    $submission = $this->s->start('wA1');
    expect(fn () => $this->s->submit($submission))->toThrow(ValidationException::class);

    $this->s->complete($submission);
    expect($this->s->submit($submission)->status)->toBe(SubmissionStatus::Submitted);
});

it('requires a flagged section to be empty and Section II flows to be zero', function (): void {
    $submission = $this->s->complete($this->s->start('wA1'));
    $head = $this->s->head('wA1');

    // Section II "የለም" with non-zero flow figures is refused.
    $this->actingAs($head)->post(route('service-monitoring.submissions.nothing', $submission), ['section' => 2, 'nothing' => true])->assertSessionHasErrors('section_2');

    $zeroFlows = collect(FormDefinition::section2Flow())->mapWithKeys(fn ($f) => [$f => 0])->all();
    $this->s->complete($submission, $zeroFlows);
    $this->actingAs($head)->post(route('service-monitoring.submissions.nothing', $submission), ['section' => 2, 'nothing' => true])->assertSessionHasNoErrors();

    // Stock figures stay required under "የለም".
    $this->actingAs($head)->put(route('service-monitoring.submissions.section2', $submission), [
        'values' => [...$zeroFlows, 'led_count' => null],
    ])->assertSessionHasNoErrors();
    expect(fn () => $this->s->submit($submission))->toThrow(ValidationException::class);
});

it('needs the attest permission and the declaration, and snapshots the attester', function (): void {
    $submission = $this->s->complete($this->s->start('wA1'));
    $reporter = $this->s->reporter('wA1');

    $this->actingAs($reporter)->post(route('service-monitoring.submissions.submit', $submission), ['declaration' => '1'])->assertForbidden();

    $head = $this->s->head('wA1');
    $this->actingAs($head)->post(route('service-monitoring.submissions.submit', $submission), [])->assertSessionHasErrors('declaration');
    $this->actingAs($head)->post(route('service-monitoring.submissions.submit', $submission), ['declaration' => '1'])->assertSessionHasNoErrors();

    $submission->refresh();
    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->attested_by)->toBe($head->id)
        ->and($submission->attester_name_snapshot)->toBe($head->name)
        ->and($submission->attester_organization_snapshot)->toBe('Bole Woreda 1')
        ->and($submission->attested_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event_type', 'service_monitoring.submitted')->exists())->toBeTrue();
});

it('requires the stamped copy only when the setting says so, and sniffs uploads', function (): void {
    MonitoringSetting::query()->firstOrCreate([])->forceFill(['require_stamped_copy' => true])->save();
    $submission = $this->s->complete($this->s->start('wA1'));
    $head = $this->s->head('wA1');

    $this->actingAs($head)->post(route('service-monitoring.submissions.submit', $submission), ['declaration' => '1'])->assertSessionHasErrors('stamped_copy');

    // A text file renamed to .pdf is refused by content.
    $fake = UploadedFile::fake()->createWithContent('stamped.pdf', 'not really a pdf');
    $this->actingAs($head)->post(route('service-monitoring.submissions.stamped-copy', $submission), ['file' => $fake])->assertSessionHasErrors();

    $pdf = UploadedFile::fake()->createWithContent('stamped.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $this->actingAs($head)->post(route('service-monitoring.submissions.stamped-copy', $submission), ['file' => $pdf])->assertSessionHasNoErrors();
    $attachment = MonitoringAttachment::query()->sole();
    expect($attachment->mime)->toBe('application/pdf')
        ->and($attachment->sha256)->toHaveLength(64)
        ->and($attachment->path)->not->toContain('stamped');
    Storage::disk('local')->assertExists($attachment->path);

    $this->actingAs($head)->post(route('service-monitoring.submissions.submit', $submission), ['declaration' => '1'])->assertSessionHasNoErrors();
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Submitted);

    // Served only as an attachment, to those who may see the submission.
    $this->actingAs($head)->get(route('service-monitoring.attachments.download', $attachment))->assertOk()->assertHeader('content-disposition');
    $this->actingAs($this->s->head('wA2'))->get(route('service-monitoring.attachments.download', $attachment))->assertForbidden();
});

it('refuses a first submission after the deadline, allows it under an active extension and labels it late', function (): void {
    $submission = $this->s->complete($this->s->start('wA1'));
    $this->s->clock('2026-10-21', '08:00');

    expect(fn () => $this->s->submit($submission))->toThrow(ValidationException::class);

    $coordinator = $this->s->coordinator();
    // The reason must be at least 20 characters.
    $this->actingAs($coordinator)->post(route('service-monitoring.periods.extensions.store', $this->s->period(1)), [
        'participant_id' => $this->s->p['wA1']->id, 'due_at' => '2026-10-24 17:00', 'reason' => 'too short',
    ])->assertSessionHasErrors('reason');
    $this->actingAs($coordinator)->post(route('service-monitoring.periods.extensions.store', $this->s->period(1)), [
        'participant_id' => $this->s->p['wA1']->id, 'due_at' => '2026-10-24 17:00', 'reason' => 'Power outage in the woreda office all week.',
    ])->assertSessionHasNoErrors();

    $submitted = $this->s->submit($submission);
    expect($submitted->submitted_late)->toBeTrue()->and($submitted->extension_id)->not->toBeNull();
    expect(AuditLog::query()->where('event_type', 'service_monitoring.extension_granted')->exists())->toBeTrue();

    $compliance = app(ComplianceService::class)->forPeriod($this->s->period(1), [$this->s->p['wA1']]);
    expect($compliance[$this->s->p['wA1']->id]['status'])->toBe('late_extension');
    $this->s->accept($submitted);
    expect(app(ComplianceService::class)->forPeriod($this->s->period(1), [$this->s->p['wA1']])[$this->s->p['wA1']->id]['status'])->toBe('late_extension');
});

it('refuses submission after an extension is revoked or has expired', function (): void {
    $period = $this->s->period(1);
    $coordinator = $this->s->coordinator();
    $a1 = $this->s->complete($this->s->start('wA1'));
    $a2 = $this->s->complete($this->s->start('wA2'));
    $this->s->clock('2026-10-21', '08:00');

    $extension = app(GrantExtension::class)->execute($period, $this->s->p['wA1'], CarbonImmutable::parse('2026-10-24 17:00', 'Africa/Addis_Ababa'), 'Power outage in the woreda office all week.', $coordinator);
    app(RevokeExtension::class)->execute($extension, $coordinator, 'Granted to the wrong woreda by mistake.');
    expect(fn () => $this->s->submit($a1))->toThrow(ValidationException::class);

    app(GrantExtension::class)->execute($period, $this->s->p['wA2'], CarbonImmutable::parse('2026-10-22 17:00', 'Africa/Addis_Ababa'), 'Network outage at the woreda all week long.', $coordinator);
    $this->s->clock('2026-10-23');
    expect(fn () => $this->s->submit($a2))->toThrow(ValidationException::class);

    expect(array_column(app(ComplianceService::class)->forPeriod($period, [$this->s->p['wA1'], $this->s->p['wA2']]), 'status'))->toBe(['missing', 'missing']);
});

it('allows only the defined transitions', function (): void {
    $allowed = [
        ['draft', 'submitted'], ['returned', 'submitted'], ['submitted', 'returned'], ['submitted', 'accepted'], ['accepted', 'superseded'],
    ];
    foreach (SubmissionStatus::cases() as $from) {
        foreach (SubmissionStatus::cases() as $to) {
            expect(SubmissionStateMachine::allows($from, $to))->toBe(in_array([$from->value, $to->value], $allowed, true), "{$from->value} → {$to->value}");
        }
    }
});

it('returns with comments, notifies, accepts the resubmission and keeps an append-only history', function (): void {
    $submission = $this->s->submit($this->s->complete($this->s->start('wA1')));
    $reviewer = $this->s->reviewer('subA');
    $submitter = $this->s->head('wA1');

    $this->actingAs($reviewer)->post(route('service-monitoring.review.return', $submission), [
        'reason' => 'The waiting area capacity does not match the visit.', 'comments' => [['section' => 2, 'line_reference' => 'waiting_area_capacity', 'body' => 'Recount the seats.']],
    ])->assertSessionHasNoErrors();
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Returned)
        ->and($submission->reviewComments()->count())->toBe(1);
    Notification::assertSentTo($submitter, SubmissionReturnedNotification::class);
    Notification::assertNotSentTo($this->s->head('wA2'), SubmissionReturnedNotification::class);

    // The return reopens the report: resubmission after the deadline is allowed.
    $this->s->clock('2026-10-23');
    expect($this->s->submit($submission)->status)->toBe(SubmissionStatus::Submitted);
    $this->actingAs($reviewer)->post(route('service-monitoring.review.accept', $submission))->assertSessionHasNoErrors();
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Accepted);

    $history = SubmissionHistory::query()->where('submission_id', $submission->id)->orderBy('created_at')->pluck('to_status')->all();
    expect($history)->toBe(['draft', 'submitted', 'returned', 'submitted', 'accepted']);
    expect(fn () => SubmissionHistory::query()->first()->update(['reason' => 'rewritten']))->toThrow(LogicException::class);
});

it('keeps accepted and locked data immutable through every endpoint', function (): void {
    $submission = $this->s->accepted('wA1');
    $head = $this->s->head('wA1');

    $this->actingAs($head)->put(route('service-monitoring.submissions.section2', $submission), ['values' => ['led_count' => 99]])->assertForbidden();
    $this->actingAs($head)->post(route('service-monitoring.submissions.nothing', $submission), ['section' => 1, 'nothing' => false])->assertForbidden();
    $this->actingAs($head)->put(route('service-monitoring.submissions.section4', $submission), ['main_total' => 1])->assertForbidden();
    $this->actingAs($head)->post(route('service-monitoring.submissions.submit', $submission), ['declaration' => '1'])->assertForbidden();
    expect($submission->fresh()->institutionBuilding->led_count)->toBe(7);

    // A reviewer cannot act on an accepted or a locked report either.
    $this->actingAs($this->s->reviewer('subA'))->post(route('service-monitoring.review.return', $submission), ['reason' => 'Changing my mind about this one.'])->assertForbidden();
});

it('corrects an accepted report through a new revision that supersedes it on acceptance', function (): void {
    $original = $this->s->accepted('wA1', 1, ['led_count' => 3]);
    $head = $this->s->head('wA1');
    $reviewer = $this->s->reviewer('subA');

    $this->actingAs($head)->post(route('service-monitoring.corrections.store', $original), ['reason' => 'too short'])->assertSessionHasErrors('reason');
    $request = app(RequestCorrection::class)->execute($original, $head, 'The LED count was typed wrongly, it is five.');
    expect(fn () => app(RequestCorrection::class)->execute($original->fresh(), $head, 'A second request while the first is open.'))->toThrow(ValidationException::class);

    $this->actingAs($reviewer)->post(route('service-monitoring.corrections.decide', $request), ['approve' => true, 'note' => 'Agreed'])->assertSessionHasNoErrors();
    $revision = Submission::query()->where('revision_no', 2)->sole();
    expect($revision->status)->toBe(SubmissionStatus::Draft)
        ->and($revision->is_current)->toBeTrue()
        ->and($original->fresh()->is_current)->toBeFalse()
        ->and($original->fresh()->status)->toBe(SubmissionStatus::Accepted)
        ->and($revision->institutionBuilding->led_count)->toBe(3);

    // While n+1 is a draft, the accepted revision still counts.
    $report = fn () => app(ConsolidationService::class)->report($this->s->year, ReportRange::Quarter, 1, [$this->s->p['wA1']->id], false);
    expect($report()['rows'][0]['section2']['led_count'])->toBe(3);

    $this->actingAs($head)->put(route('service-monitoring.submissions.section2', $revision), ['values' => [...$revision->institutionBuilding->only(FormDefinition::section2Keys()), 'led_count' => 5]])
        ->assertSessionHasNoErrors();
    $this->s->accept($this->s->submit($revision));

    app(ConsolidationService::class)->forget();
    expect($original->fresh()->status)->toBe(SubmissionStatus::Superseded)
        ->and($revision->fresh()->status)->toBe(SubmissionStatus::Accepted)
        ->and($report()['rows'][0]['section2']['led_count'])->toBe(5)
        ->and(Submission::query()->where('status', 'accepted')->count())->toBe(1);
});

it('refuses a correction request on a locked period', function (): void {
    $original = $this->s->accepted('wA1');
    $this->s->period(1)->forceFill(['status' => 'locked'])->save();

    expect(fn () => app(RequestCorrection::class)->execute($original->fresh(), $this->s->head('wA1'), 'The LED count was typed wrongly, it is five.'))->toThrow(ValidationException::class);
    $this->actingAs($this->s->head('wA1'))->post(route('service-monitoring.corrections.store', $original), ['reason' => 'The LED count was typed wrongly, it is five.'])->assertForbidden();
});
