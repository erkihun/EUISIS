<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Letters\Application\Actions\ApproveLetter;
use Modules\Letters\Application\Actions\DispatchLetter;
use Modules\Letters\Application\Actions\RouteLetter;
use Modules\Letters\Application\Actions\SubmitForApproval;
use Modules\Letters\Application\Notifications\LetterNotification;
use Modules\Letters\Domain\Models\LetterRegistration;
use Modules\Letters\Domain\Models\LetterRouting;
use Tests\Support\LettersScenario;

/*
 * The second cut (docs/letters-research.md): security classification, copy
 * recipients, the dispatch register with acknowledgement of receipt, file
 * codes, the printed letter's verification, reminders and the CSV export.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->s = LettersScenario::build();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows a confidential letter\'s registry entry to everyone in scope but its contents only to handlers and confidential readers', function (): void {
    $registrar = $this->s->registrar('A');
    $officer = $this->s->officer('A');
    $handler = $this->s->handler('A');
    $letter = $this->s->incoming('A', $registrar, ['classification' => 'confidential', 'subject' => 'Disciplinary referral of an employee']);
    $this->actingAs($registrar)->post(route('letters.attachments.store', $letter), ['file' => UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"), 'kind' => 'scan']);
    $attachment = $letter->attachments()->firstOrFail();

    // The officer sees the entry, not the text, the party or the file.
    $this->actingAs($officer)->get(route('letters.show', $letter))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('letter.contents_hidden', true)->where('letter.subject', null)->where('letter.external_party_name', null)->where('letter.attachments', [])->where('letter.classification', 'confidential'));
    $this->actingAs($officer)->get(route('letters.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.subject', null)->where('rows.data.0.counterpart', null)->where('rows.data.0.number', $letter->referenceNumber()));
    $this->actingAs($officer)->get(route('letters.attachments.download', $attachment))->assertForbidden();

    // The registrar holds letters.view_confidential; the routed handler reads what was forwarded.
    $this->actingAs($registrar)->get(route('letters.show', $letter))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('letter.contents_hidden', false)->where('letter.subject', 'Disciplinary referral of an employee')->has('letter.attachments', 1));
    $this->actingAs($registrar)->get(route('letters.attachments.download', $attachment))->assertOk();
    app(RouteLetter::class)->execute($letter->registrations->first(), ['to_user_id' => $handler->id, 'instruction' => 'Act'], $registrar);
    $this->s->forget();
    $this->actingAs($handler)->get(route('letters.show', $letter))->assertOk()->assertInertia(fn (Assert $page) => $page->where('letter.contents_hidden', false));
});

it('gives every copy recipient in the system its own registry entry at dispatch and records how the letter left', function (): void {
    $signatory = $this->s->signatory('A');
    $registrarA = $this->s->registrar('A');
    $registrarC = $this->s->registrar('C');
    $letter = $this->s->outgoing('A', 'B', $signatory, [
        'copies' => [['organization_id' => $this->s->org['C']->id], ['external_name' => 'Ministry of Finance', 'external_address' => 'Addis Ababa'], ['organization_id' => $this->s->org['B']->id]],
        'file_code' => 'HR/12',
    ]);
    expect($letter->copies()->count())->toBe(2); // the primary recipient is not repeated as a copy

    app(SubmitForApproval::class)->execute($letter, $signatory);
    app(ApproveLetter::class)->execute($letter->fresh(), $signatory);
    $this->actingAs($registrarA)->post(route('letters.dispatch', $letter), ['mode' => 'courier', 'reference' => 'DHL 778899', 'note' => 'Two annexes'])->assertSessionHasNoErrors();

    $letter = $letter->fresh(['registrations', 'copies']);
    expect($letter->dispatch_mode->value)->toBe('courier')->and($letter->dispatch_reference)->toBe('DHL 778899')->and($letter->verification_code)->toHaveLength(12)
        ->and($letter->registrations)->toHaveCount(3)
        ->and($letter->registrations->where('organization_id', $this->s->org['C']->id)->first()->status->value)->toBe('pending_receipt');
    Notification::assertSentTo($registrarC, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::AWAITING_RECEIPT);

    // Proof of delivery is the sender's desk's to record, once, on its own entry.
    $own = $letter->registrations->firstWhere('organization_id', $this->s->org['A']->id);
    $this->actingAs($this->s->registrar('B'))->post(route('letters.registrations.acknowledge', $own), ['acknowledged_on' => '2026-11-12'])->assertForbidden();
    $this->actingAs($registrarA)->post(route('letters.registrations.acknowledge', $own), ['acknowledged_on' => '2026-11-12', 'acknowledgement_note' => 'Signed copy returned'])->assertSessionHasNoErrors();
    expect($own->fresh()->acknowledged_at?->toDateString())->toBe('2026-11-12');
    $this->actingAs($registrarA)->post(route('letters.registrations.acknowledge', $own), [])->assertForbidden();

    // The sender's page lists the copies; the file code is searchable.
    $this->actingAs($registrarA)->get(route('letters.show', $letter))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('letter.copies', 2)->where('letter.dispatch_mode', 'courier')->where('letter.file_code', 'HR/12')->where('letter.registrations.0.acknowledged_at', fn ($v) => $v !== null));
    $this->actingAs($registrarA)->get(route('letters.index', ['q' => 'HR/12']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 1));
});

it('verifies a dispatched letter publicly by its code without revealing the text', function (): void {
    $signatory = $this->s->signatory('A');
    $letter = $this->s->outgoing('A', null, $signatory, ['subject' => 'Secret subject line', 'language' => 'en']);
    expect($letter->verification_code)->toBeNull();
    $this->get(route('letters.verify', ['code' => 'ABCDEFGHJKLM']))->assertOk()->assertSee(__('letters.verify.not_found', [], 'am'));

    app(SubmitForApproval::class)->execute($letter, $signatory);
    app(ApproveLetter::class)->execute($letter->fresh(), $signatory);
    $letter = app(DispatchLetter::class)->execute($letter->fresh(), $signatory, ['mode' => 'hand']);

    $this->get(route('letters.verify', ['code' => strtolower($letter->verification_code)]))->assertOk()
        ->assertSee(__('letters.verify.genuine', [], 'en'))->assertSee($letter->referenceNumber())->assertSee('Trade Bureau')->assertDontSee('Secret subject line');
    $this->get(route('letters.verify', ['code' => $letter->verification_code, 'lang' => 'am']))->assertOk()->assertSee(__('letters.verify.genuine', [], 'am'));

    // The frozen PDF carries the code.
    $pdf = $letter->attachments()->where('kind', 'rendered')->firstOrFail();
    expect(Storage::disk('local')->exists($pdf->path))->toBeTrue();
});

it('reminds addressees of routing steps due soon or overdue, registrars of letters awaiting receipt, and never twice a day', function (): void {
    $registrar = $this->s->registrar('A');
    $handler = $this->s->handler('A');
    $letter = $this->s->incoming('A', $registrar);
    $registration = $letter->registrations->first();
    $soon = app(RouteLetter::class)->execute($registration, ['to_user_id' => $handler->id, 'instruction' => 'Soon', 'due_on' => now()->addDay()->toDateString()], $registrar);
    $late = app(RouteLetter::class)->execute($registration, ['to_user_id' => $handler->id, 'instruction' => 'Late', 'due_on' => now()->subDays(3)->toDateString()], $registrar);
    $far = app(RouteLetter::class)->execute($registration, ['to_user_id' => $handler->id, 'instruction' => 'Far', 'due_on' => now()->addDays(10)->toDateString()], $registrar);
    $pending = LetterRegistration::query()->create(['letter_id' => $this->s->outgoing('A', 'B')->id, 'organization_id' => $this->s->org['B']->id, 'direction' => 'incoming', 'status' => 'pending_receipt']);
    LetterRegistration::query()->whereKey($pending->id)->update(['created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
    $registrarB = $this->s->registrar('B');
    Notification::fake();

    Artisan::call('letters:send-reminders');
    $output = Artisan::output();
    expect($output)->toContain('routing_due          1')->toContain('routing_overdue      1')->toContain('awaiting_receipt     1');
    Notification::assertSentTo($handler, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::ROUTING_DUE);
    Notification::assertSentTo($handler, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::ROUTING_OVERDUE);
    Notification::assertSentTo($registrarB, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::AWAITING_RECEIPT);
    expect(LetterRouting::query()->whereKey($soon->id)->value('reminded_at'))->not->toBeNull()
        ->and(LetterRouting::query()->whereKey($far->id)->value('reminded_at'))->toBeNull()
        ->and(LetterRouting::query()->whereKey($late->id)->value('reminded_at'))->not->toBeNull();

    Artisan::call('letters:send-reminders');
    expect(Artisan::output())->toContain('routing_due          0')->toContain('awaiting_receipt     0');

    // The next day both open steps are chased again: the one now due today, and the overdue one.
    Carbon::setTestNow(now()->addDay());
    Artisan::call('letters:send-reminders', ['--dry-run' => true]);
    expect(Artisan::output())->toContain('routing_due          1')->toContain('routing_overdue      1');
});

it('exports the registry as CSV with the page filters, masking what the exporter may not read', function (): void {
    $registrar = $this->s->registrar('A');
    $this->s->incoming('A', $registrar, ['subject' => 'Open subject', 'file_code' => 'F-1']);
    $this->s->incoming('A', $registrar, ['subject' => 'Hidden subject', 'classification' => 'secret']);
    $exporter = $this->s->user(['letters.view', 'letters.export'], ['A']);

    $this->actingAs($this->s->officer('A'))->get(route('letters.export'))->assertForbidden();

    $response = $this->actingAs($exporter)->get(route('letters.export', ['direction' => 'incoming']));
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $csv = $response->streamedContent();
    expect($csv)->toContain('Open subject')->toContain('F-1')->not->toContain('Hidden subject')->toContain(__('letters.enums.classification.secret', [], 'en'));
    expect(substr_count($csv, "\n"))->toBe(3); // header + two rows

    $registrarCsv = $this->actingAs($registrar)->get(route('letters.export'))->streamedContent();
    expect($registrarCsv)->toContain('Hidden subject');
    $this->actingAs($registrar)->get(route('letters.export', ['q' => 'Open']))->assertOk();
});
