<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\OrganizationUnit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Letters\Application\Actions\ComposeLetter;
use Modules\Letters\Application\Notifications\LetterNotification;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterRouting;
use Tests\Support\LettersScenario;

/*
 * The letter's life (ADR 0037): registering what arrives, composing →
 * signature → dispatch, the recipient institution receiving and routing,
 * closing, replies, cancellation, and immutability after dispatch.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->s = LettersScenario::build();
});

it('registers an incoming letter with the institution number, a scan, an event and an audit row', function (): void {
    $registrar = $this->s->registrar('A');

    $response = $this->actingAs($registrar)->post(route('letters.register.store'), [
        'organization_id' => $this->s->org['A']->id, 'unit_id' => $this->s->unitA->id,
        'external_party_name' => 'Federal Civil Service Commission', 'external_reference' => 'FCSC/1', 'subject' => 'Staffing return', 'letter_date' => '2026-11-01', 'received_on' => '2026-11-03',
        'files' => [UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")],
    ]);

    $letter = Letter::query()->firstOrFail();
    $response->assertRedirect(route('letters.show', $letter));
    expect($letter->status->value)->toBe('received')->and($letter->direction->value)->toBe('incoming')->and($letter->referenceNumber())->toStartWith('LT-A/0001/')
        ->and($letter->attachments()->where('kind', 'scan')->count())->toBe(1)
        ->and($letter->events()->pluck('type')->map(fn ($t) => $t->value)->all())->toContain('registered', 'number_assigned', 'attachment_added')
        ->and(AuditLog::query()->where('event_type', 'letters.registered')->where('organization_id', $this->s->org['A']->id)->exists())->toBeTrue();
});

it('takes a letter from draft through signature and dispatch to the recipient institution, which receives, routes and closes it', function (): void {
    $officer = $this->s->officer('A');
    $signatory = $this->s->signatory('A');
    $registrarA = $this->s->registrar('A');
    $registrarB = $this->s->registrar('B');
    $handlerB = $this->s->handler('B');

    $letter = $this->s->outgoing('A', 'B', $officer, ['signatory_user_id' => $signatory->id]);
    expect($letter->status->value)->toBe('draft')->and($letter->registrations)->toHaveCount(0);

    // Submit: the signatory is told.
    $this->actingAs($officer)->post(route('letters.submit', $letter))->assertSessionHasNoErrors();
    expect($letter->fresh()->status->value)->toBe('pending_approval');
    Notification::assertSentTo($signatory, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::AWAITING_APPROVAL);

    // The officer cannot sign; the registrar cannot either.
    $this->actingAs($officer)->post(route('letters.approve', $letter))->assertForbidden();
    $this->actingAs($registrarA)->post(route('letters.approve', $letter))->assertForbidden();
    $this->actingAs($signatory)->post(route('letters.approve', $letter))->assertSessionHasNoErrors();
    expect($letter->fresh()->status->value)->toBe('approved')->and($letter->fresh()->approved_by)->toBe($signatory->id);

    // Dispatch by the registry desk: own number, frozen PDF, the recipient's entry awaiting receipt.
    $this->actingAs($registrarA)->post(route('letters.dispatch', $letter))->assertSessionHasNoErrors();
    $letter = $letter->fresh(['registrations', 'attachments']);
    expect($letter->status->value)->toBe('dispatched')->and($letter->referenceNumber())->toStartWith('LT-A/0001/')
        ->and($letter->attachments->where('kind.value', 'rendered'))->toHaveCount(1)
        ->and($letter->registrations)->toHaveCount(2);
    $theirs = $letter->registrations->firstWhere('organization_id', $this->s->org['B']->id);
    expect($theirs->status->value)->toBe('pending_receipt')->and($theirs->registry_number)->toBeNull();
    Notification::assertSentTo($registrarB, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::AWAITING_RECEIPT);

    // Nothing changes after dispatch.
    $this->actingAs($officer)->put(route('letters.update', $letter), ['subject' => 'Changed', 'body' => '<p>x</p>'])->assertForbidden();
    $this->actingAs($officer)->post(route('letters.cancel', $letter), ['reason' => 'late'])->assertForbidden();
    expect($letter->fresh()->subject)->toBe('Transmittal of the staffing return');

    // B receives: its own incoming number.
    $this->actingAs($registrarA)->post(route('letters.registrations.receive', $theirs))->assertForbidden();
    $this->actingAs($registrarB)->post(route('letters.registrations.receive', $theirs), ['received_on' => '2026-11-06', 'due_on' => '2026-11-30'])->assertSessionHasNoErrors();
    $theirs = $theirs->fresh();
    expect($theirs->status->value)->toBe('registered')->and($theirs->registry_number)->toStartWith('LT-B/0001/');

    // B routes to a person; the person acknowledges and completes; B closes.
    $this->actingAs($registrarB)->post(route('letters.routings.store', $theirs), ['to_user_id' => $handlerB->id, 'instruction' => 'Prepare the return', 'due_on' => '2026-11-25'])->assertSessionHasNoErrors();
    $routing = LetterRouting::query()->where('registration_id', $theirs->id)->firstOrFail();
    expect($theirs->fresh()->status->value)->toBe('routed');
    Notification::assertSentTo($handlerB, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::ROUTED);

    $this->actingAs($registrarB)->post(route('letters.registrations.close', $theirs))->assertSessionHasErrors('closing_note');

    $this->actingAs($handlerB)->get(route('letters.show', $letter))->assertOk();
    $this->actingAs($handlerB)->post(route('letters.routings.acknowledge', $routing))->assertSessionHasNoErrors();
    expect($theirs->fresh()->status->value)->toBe('in_progress');
    $this->actingAs($handlerB)->post(route('letters.routings.complete', $routing), ['outcome' => 'Return prepared and filed.'])->assertSessionHasNoErrors();
    expect($routing->fresh()->status->value)->toBe('completed');

    $this->actingAs($registrarB)->post(route('letters.registrations.close', $theirs), ['closing_note' => 'Done'])->assertSessionHasNoErrors();
    expect($theirs->fresh()->status->value)->toBe('closed');

    expect(AuditLog::query()->whereIn('event_type', ['letters.submitted', 'letters.approved', 'letters.dispatched', 'letters.received', 'letters.routed', 'letters.routing_completed', 'letters.closed'])->count())->toBe(7);
});

it('refuses to route to someone outside the institution or to a unit of another institution', function (): void {
    $letter = $this->s->incoming('A');
    $registration = $letter->registrations->first();
    $registrarA = $this->s->registrar('A');
    $outsider = $this->s->handler('C');
    $unitB = OrganizationUnit::query()->create(['organization_id' => $this->s->org['B']->id, 'unit_type' => 'directorate', 'code' => 'LT-B-X', 'name_en' => 'B unit', 'status' => 'active']);

    $this->actingAs($registrarA)->post(route('letters.routings.store', $registration), ['to_user_id' => $outsider->id, 'instruction' => 'x'])->assertSessionHasErrors('to_user_id');
    $this->actingAs($registrarA)->post(route('letters.routings.store', $registration), ['to_unit_id' => $unitB->id, 'instruction' => 'x'])->assertSessionHasErrors('to_unit_id');
    $this->actingAs($registrarA)->post(route('letters.routings.store', $registration), ['instruction' => 'x'])->assertSessionHasErrors('to_user_id');
    $this->actingAs($registrarA)->post(route('letters.routings.store', $registration), ['to_unit_id' => $this->s->unitA->id, 'instruction' => 'File it'])->assertSessionHasNoErrors();
});

it('returns a draft to its author, lets the author resubmit, and cancels with a reason', function (): void {
    $officer = $this->s->officer('A');
    $signatory = $this->s->signatory('A');
    $letter = $this->s->outgoing('A', 'B', $officer);

    $this->actingAs($officer)->post(route('letters.submit', $letter));
    $this->actingAs($signatory)->post(route('letters.return', $letter), ['reason' => 'Wrong date'])->assertSessionHasNoErrors();
    expect($letter->fresh()->status->value)->toBe('draft');
    Notification::assertSentTo($officer, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::RETURNED);

    $this->actingAs($officer)->put(route('letters.update', $letter), ['subject' => 'Transmittal (corrected)', 'body' => '<p>Corrected.</p>', 'recipient_organization_id' => $this->s->org['B']->id, 'letter_date' => '2026-11-06'])->assertSessionHasNoErrors();
    expect($letter->fresh()->subject)->toBe('Transmittal (corrected)');

    $this->actingAs($officer)->post(route('letters.cancel', $letter), ['reason' => 'No longer needed'])->assertSessionHasNoErrors();
    expect($letter->fresh()->status->value)->toBe('cancelled');
    $this->actingAs($officer)->post(route('letters.submit', $letter))->assertForbidden();
});

it('needs a recipient and a body before a letter goes for signature', function (): void {
    $officer = $this->s->officer('A');
    $letter = $this->s->outgoing('A', null, $officer, ['external_party_name' => null, 'body' => '']);

    $this->actingAs($officer)->post(route('letters.submit', $letter))->assertSessionHasErrors(['recipient', 'body']);
    expect($letter->fresh()->status->value)->toBe('draft');
});

it('starts a reply from the receiving institution that references the original', function (): void {
    $letter = $this->s->incoming('A');
    $officer = $this->s->officer('A');

    $response = $this->actingAs($officer)->post(route('letters.reply', $letter), ['organization_id' => $this->s->org['A']->id]);
    $reply = Letter::query()->where('in_reply_to_letter_id', $letter->id)->firstOrFail();
    $response->assertRedirect(route('letters.edit', $reply));
    expect($reply->direction->value)->toBe('outgoing')->and($reply->external_party_name)->toBe('Federal Civil Service Commission')->and($reply->subject)->toStartWith('Re: ')
        ->and($letter->events()->where('type', 'reply_created')->exists())->toBeTrue();

    // A draft cannot be replied to; an unrelated institution cannot reply.
    $this->actingAs($officer)->post(route('letters.reply', $reply), ['organization_id' => $this->s->org['A']->id])->assertForbidden();
    $this->actingAs($this->s->officer('C'))->post(route('letters.reply', $letter), ['organization_id' => $this->s->org['C']->id])->assertForbidden();
});

it('dispatches an internal memo inside one institution with one registry entry and no receipt step', function (): void {
    $signatory = $this->s->signatory('A');
    $letter = app(ComposeLetter::class)->execute([
        'direction' => 'internal', 'organization_id' => $this->s->org['A']->id, 'unit_id' => $this->s->unitA->id, 'subject' => 'Office hours', 'body' => '<p>From Monday.</p>', 'letter_date' => '2026-11-05',
    ], $signatory);

    $this->actingAs($signatory)->post(route('letters.submit', $letter))->assertSessionHasNoErrors();
    $this->actingAs($signatory)->post(route('letters.approve', $letter))->assertSessionHasNoErrors();
    $this->actingAs($signatory)->post(route('letters.dispatch', $letter))->assertSessionHasNoErrors();

    $letter = $letter->fresh(['registrations']);
    expect($letter->registrations)->toHaveCount(1)->and($letter->registrations->first()->direction->value)->toBe('internal')->and($letter->registrations->first()->status->value)->toBe('registered')
        ->and(LetterRegistration::query()->where('status', 'pending_receipt')->exists())->toBeFalse();
});

it('serves attachments only through the policy, as downloads, and audits them', function (): void {
    $letter = $this->s->incoming('A');
    $registrar = $this->s->registrar('A');
    $this->actingAs($registrar)->post(route('letters.attachments.store', $letter), ['file' => UploadedFile::fake()->createWithContent('annex.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"), 'kind' => 'annex'])->assertSessionHasNoErrors();
    $attachment = $letter->attachments()->firstOrFail();

    $this->actingAs($this->s->registrar('C'))->get(route('letters.attachments.download', $attachment))->assertForbidden();
    $this->actingAs($registrar)->get(route('letters.attachments.download', $attachment))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(AuditLog::query()->where('event_type', 'letters.attachment_downloaded')->exists())->toBeTrue();

    // A disguised file is refused by content.
    $this->actingAs($registrar)->post(route('letters.attachments.store', $letter), ['file' => UploadedFile::fake()->createWithContent('evil.pdf', '<?php echo 1;'), 'kind' => 'annex'])->assertSessionHasErrors('file');
});
