<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Letters\Application\Actions\ApproveLetter;
use Modules\Letters\Application\Actions\RouteLetter;
use Modules\Letters\Application\Actions\SubmitForApproval;
use Modules\Letters\Application\Mail\LetterDispatchedMail;
use Modules\Letters\Application\Notifications\LetterNotification;
use Modules\Letters\Application\Services\LetterStandards;
use Modules\Letters\Domain\Models\LetterFileClass;
use Tests\Support\LettersScenario;

/*
 * The third cut (docs/letters-research.md §9): reply-time standards with
 * default due dates, interim-reply reminders and escalation; the file
 * classification scheme; email dispatch outside the platform; and the
 * letterhead's footer, contacts placement and stamp.
 */

beforeEach(function (): void {
    Notification::fake();
    Mail::fake();
    Storage::fake('local');
    $this->s = LettersScenario::build();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('gives letters a due date from the institution standard by priority, in working days', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-12 09:00', 'Africa/Addis_Ababa')); // a Thursday
    $admin = $this->s->administrator('A');
    $this->actingAs($admin)->put(route('letters.standards.update'), ['organization_id' => $this->s->org['A']->id, 'reply_days_normal' => 5, 'reply_days_urgent' => 2, 'reply_days_very_urgent' => 1, 'interim_reply_days' => 1, 'escalation_after_days' => 1])->assertSessionHasNoErrors();

    $normal = $this->s->incoming('A', $admin, ['priority' => 'normal', 'due_on' => null]);
    $urgent = $this->s->incoming('A', $admin, ['priority' => 'urgent', 'due_on' => null]);
    $given = $this->s->incoming('A', $admin, ['priority' => 'urgent', 'due_on' => '2026-12-01']);

    // Thursday + 5 working days = next Thursday; + 2 working days = Monday (the weekend is skipped).
    expect($normal->registrations->first()->due_on->toDateString())->toBe('2026-11-19')
        ->and($urgent->registrations->first()->due_on->toDateString())->toBe('2026-11-16')
        ->and($given->registrations->first()->due_on->toDateString())->toBe('2026-12-01');

    // Institution B has no standard: the built-in defaults (10 working days for normal).
    $b = $this->s->incoming('B', null, ['due_on' => null, 'priority' => 'normal']);
    expect($b->registrations->first()->due_on->toDateString())->toBe('2026-11-26')
        ->and(app(LetterStandards::class)->for($this->s->org['B']->id)['source'])->toBe('default');

    $this->actingAs($this->s->officer('A'))->put(route('letters.standards.update'), ['organization_id' => $this->s->org['A']->id, 'reply_days_normal' => 5, 'reply_days_urgent' => 2, 'reply_days_very_urgent' => 1, 'interim_reply_days' => 1, 'escalation_after_days' => 1])->assertForbidden();
    $this->actingAs($admin)->get(route('letters.standards.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Standards', false)->where('effective.reply_days_normal', 5)->where('effective.source', 'institution'));
});

it('asks for an interim reply before the due date and escalates to the signatories once overdue past the threshold', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-12 09:00', 'Africa/Addis_Ababa'));
    $registrar = $this->s->registrar('A');
    $signatory = $this->s->signatory('A');
    $handler = $this->s->handler('A');
    $letter = $this->s->incoming('A', $registrar, ['due_on' => '2026-11-14']);
    $registration = $letter->registrations->first();
    app(RouteLetter::class)->execute($registration, ['to_user_id' => $handler->id, 'instruction' => 'Answer'], $registrar);
    Notification::fake();

    // Two days before: the holder is asked for an interim reply; nobody is escalated yet.
    Artisan::call('letters:send-reminders');
    Notification::assertSentTo($handler, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::INTERIM_REPLY_DUE);
    Notification::assertNotSentTo($signatory, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::ESCALATED);
    expect($registration->fresh()->interim_reminded_at)->not->toBeNull();

    // Overdue by three days (threshold two): the signatory is told once.
    Carbon::setTestNow(Carbon::parse('2026-11-17 09:00', 'Africa/Addis_Ababa'));
    Notification::fake();
    Artisan::call('letters:send-reminders');
    expect(Artisan::output())->toMatch('/escalated\s+1\R/');
    Notification::assertSentTo($signatory, LetterNotification::class, fn (LetterNotification $n): bool => $n->kind === LetterNotification::ESCALATED);
    expect($registration->fresh()->escalated_at)->not->toBeNull();
    Artisan::call('letters:send-reminders');
    expect(Artisan::output())->toMatch('/escalated\s+0\R/');

    $this->actingAs($registrar)->get(route('letters.show', $letter))->assertOk()->assertInertia(fn (Assert $page) => $page->where('letter.registrations.0.escalated_at', fn ($v) => $v !== null));
});

it('keeps a file classification scheme per institution and files letters under it', function (): void {
    $admin = $this->s->administrator('A');
    $steward = $this->s->steward();

    $this->actingAs($steward)->post(route('letters.file-classes.store'), ['organization_id' => null, 'code' => 'GEN', 'title_en' => 'General'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('letters.file-classes.store'), ['organization_id' => $this->s->org['A']->id, 'code' => 'hr', 'title_am' => 'የሰው ሀብት', 'title_en' => 'Human resources'])->assertSessionHasNoErrors();
    $hr = LetterFileClass::query()->where('code', 'HR')->firstOrFail();
    $this->actingAs($admin)->post(route('letters.file-classes.store'), ['organization_id' => $this->s->org['A']->id, 'code' => 'HR/12', 'title_en' => 'Staffing returns', 'parent_id' => $hr->id])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('letters.file-classes.store'), ['organization_id' => $this->s->org['A']->id, 'code' => 'HR', 'title_en' => 'Duplicate'])->assertSessionHasErrors('code');
    $this->actingAs($admin)->post(route('letters.file-classes.store'), ['organization_id' => null, 'code' => 'X', 'title_en' => 'Platform'])->assertForbidden();

    $standards = app(LetterStandards::class);
    expect(collect($standards->fileClassesFor($this->s->org['A']->id))->pluck('code')->all())->toBe(['HR', 'HR/12'])
        ->and(collect($standards->fileClassesFor($this->s->org['B']->id))->pluck('code')->all())->toBe(['GEN']);

    $sub = LetterFileClass::query()->where('code', 'HR/12')->firstOrFail();
    $letter = $this->s->incoming('A', $admin, ['file_class_id' => $sub->id]);
    $this->actingAs($admin)->get(route('letters.index', ['file_class_id' => $sub->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.file_class.code', 'HR/12')->has('file_classes', 2));
    $this->actingAs($admin)->get(route('letters.index', ['file_class_id' => $hr->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data', []));

    // A class of another scheme is refused on a letter.
    $gen = LetterFileClass::query()->where('code', 'GEN')->firstOrFail();
    $c = LetterFileClass::query()->create(['organization_id' => $this->s->org['C']->id, 'code' => 'C1', 'active' => true]);
    $this->actingAs($admin)->post(route('letters.register.store'), ['organization_id' => $this->s->org['A']->id, 'external_party_name' => 'X', 'subject' => 'Y', 'letter_date' => '2026-11-01', 'file_class_id' => $c->id])->assertSessionHasErrors('file_class_id');
    $this->actingAs($admin)->post(route('letters.register.store'), ['organization_id' => $this->s->org['A']->id, 'external_party_name' => 'X', 'subject' => 'Y', 'letter_date' => '2026-11-01', 'file_class_id' => $gen->id])->assertSessionHasNoErrors();
    expect($letter->fresh()->fileClass->code)->toBe('HR/12');
});

it('dispatches a letter by email to a party outside the platform with the PDF and the verification code', function (): void {
    $signatory = $this->s->signatory('A');
    $letter = $this->s->outgoing('A', null, $signatory, ['external_party_name' => 'Ministry of Finance', 'external_party_email' => 'registry@mofed.example.gov.et', 'language' => 'en']);
    app(SubmitForApproval::class)->execute($letter, $signatory);
    app(ApproveLetter::class)->execute($letter->fresh(), $signatory);

    $this->actingAs($signatory)->post(route('letters.dispatch', $letter), ['mode' => 'email', 'email' => ''])->assertSessionHasErrors('email');
    $this->actingAs($signatory)->post(route('letters.dispatch', $letter), ['mode' => 'email', 'email' => 'Registry@MoFED.example.gov.et'])->assertSessionHasNoErrors();

    $letter = $letter->fresh(['registrations']);
    expect($letter->dispatch_mode->value)->toBe('email')->and($letter->dispatch_reference)->toBe('registry@mofed.example.gov.et')
        ->and($letter->events()->where('type', 'emailed')->exists())->toBeTrue();
    Mail::assertQueued(LetterDispatchedMail::class, function (LetterDispatchedMail $mail) use ($letter): bool {
        return $mail->hasTo('registry@mofed.example.gov.et') && $mail->number === $letter->referenceNumber() && $mail->verificationCode === $letter->verification_code
            && str_contains($mail->verifyUrl, $letter->verification_code) && count($mail->attachments()) === 1;
    });

    // Without an address anywhere, email dispatch is refused and nothing changes.
    $other = $this->s->outgoing('A', null, $signatory, ['external_party_name' => 'Someone']);
    app(SubmitForApproval::class)->execute($other, $signatory);
    app(ApproveLetter::class)->execute($other->fresh(), $signatory);
    $this->actingAs($signatory)->post(route('letters.dispatch', $other), ['mode' => 'email'])->assertSessionHasErrors('email');
    expect($other->fresh()->status->value)->toBe('approved');
});
