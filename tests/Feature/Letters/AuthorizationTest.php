<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Letters\Application\Actions\ApproveLetter;
use Modules\Letters\Application\Actions\DispatchLetter;
use Modules\Letters\Application\Actions\RouteLetter;
use Modules\Letters\Application\Actions\SubmitForApproval;
use Modules\Letters\Domain\Models\LetterTemplate;
use Tests\Support\LettersScenario;

/*
 * Organization scope on every read and write (ADR 0037 §8, ADR 0003): an
 * institution never sees another's registry; a letter crosses only when it
 * is dispatched to the other institution; a routed person sees that letter
 * and nothing else; templates and numbering follow their owner.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->s = LettersScenario::build();
});

it('keeps one institution out of another institution\'s registry and letters', function (): void {
    $letter = $this->s->incoming('A');
    $registration = $letter->registrations->first();
    $registrarC = $this->s->registrar('C');

    $this->actingAs($registrarC)->get(route('letters.show', $letter))->assertForbidden();
    $this->actingAs($registrarC)->get(route('letters.pdf', $letter))->assertForbidden();
    $this->actingAs($registrarC)->post(route('letters.routings.store', $registration), ['to_unit_id' => $this->s->unitA->id, 'instruction' => 'x'])->assertForbidden();
    $this->actingAs($registrarC)->post(route('letters.registrations.close', $registration))->assertForbidden();
    $this->actingAs($registrarC)->post(route('letters.register.store'), ['organization_id' => $this->s->org['A']->id, 'external_party_name' => 'X', 'subject' => 'Y', 'letter_date' => '2026-11-01'])->assertForbidden();
    $this->actingAs($registrarC)->get(route('letters.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Index', false)->where('rows.data', []));
    $this->actingAs($registrarC)->get(route('letters.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Dashboard', false)->where('counts.open', 0)->where('recent', []));

    // The right institution sees it.
    $this->actingAs($this->s->registrar('A'))->get(route('letters.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Index', false)->has('rows.data', 1)->where('rows.data.0.id', $letter->id));
});

it('shows a letter to the recipient institution only once it is dispatched to it', function (): void {
    $officer = $this->s->officer('A');
    $signatory = $this->s->signatory('A');
    $registrarB = $this->s->registrar('B');
    $letter = $this->s->outgoing('A', 'B', $officer);

    $this->actingAs($registrarB)->get(route('letters.show', $letter))->assertForbidden();
    $this->actingAs($registrarB)->get(route('letters.index', ['tab' => 'inbox']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rows.data', []));

    app(SubmitForApproval::class)->execute($letter, $officer);
    app(ApproveLetter::class)->execute($letter->fresh(), $signatory);
    app(DispatchLetter::class)->execute($letter->fresh(), $signatory);

    $this->actingAs($registrarB)->get(route('letters.show', $letter))->assertOk();
    $this->actingAs($registrarB)->get(route('letters.index', ['tab' => 'inbox']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.registration_status', 'pending_receipt'));
    // C still sees nothing.
    $this->actingAs($this->s->registrar('C'))->get(route('letters.show', $letter))->assertForbidden();
});

it('lets a routed person open that letter and act on their step, and nothing else', function (): void {
    $letter = $this->s->incoming('A');
    $other = $this->s->incoming('A');
    $registrar = $this->s->registrar('A');
    $handler = $this->s->handler('A');

    $this->actingAs($handler)->get(route('letters.show', $letter))->assertForbidden();
    $routing = app(RouteLetter::class)->execute($letter->registrations->first(), ['to_user_id' => $handler->id, 'instruction' => 'Act'], $registrar);
    $this->s->forget();

    $this->actingAs($handler)->get(route('letters.show', $letter))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('letter.registrations.0.routings.0.can_act', true)->where('letter.registrations.0.can.route', false));
    $this->actingAs($handler)->get(route('letters.show', $other))->assertForbidden();
    $this->actingAs($handler)->get(route('letters.index', ['tab' => 'mine']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1));
    $this->actingAs($handler)->post(route('letters.routings.store', $letter->registrations->first()), ['to_user_id' => $registrar->id, 'instruction' => 'back'])->assertForbidden();

    // Another handler of the same institution cannot touch this step.
    $this->actingAs($this->s->handler('A'))->post(route('letters.routings.acknowledge', $routing))->assertForbidden();
    $this->actingAs($handler)->post(route('letters.routings.acknowledge', $routing))->assertSessionHasNoErrors();
});

it('lets only the owner\'s administrator edit an institution template and only a city-wide steward edit a platform one', function (): void {
    $steward = $this->s->steward();
    $adminA = $this->s->administrator('A');
    $adminC = $this->s->administrator('C');

    $this->actingAs($adminA)->post(route('letters.templates.store'), ['owner_organization_id' => null, 'code' => 'P1', 'title_en' => 'Platform'])->assertForbidden();
    $this->actingAs($adminA)->post(route('letters.templates.store'), ['owner_organization_id' => $this->s->org['C']->id, 'code' => 'C1', 'title_en' => 'Theirs'])->assertForbidden();
    $this->actingAs($adminA)->post(route('letters.templates.store'), ['owner_organization_id' => $this->s->org['A']->id, 'code' => 'A1', 'title_en' => 'Ours'])->assertRedirect();
    $this->actingAs($steward)->post(route('letters.templates.store'), ['owner_organization_id' => null, 'code' => 'P1', 'title_en' => 'Platform'])->assertRedirect();

    $ours = LetterTemplate::query()->where('code', 'A1')->firstOrFail();
    $platform = LetterTemplate::query()->where('code', 'P1')->firstOrFail();

    $this->actingAs($adminC)->get(route('letters.templates.show', $ours))->assertForbidden();
    $this->actingAs($adminC)->get(route('letters.templates.show', $platform))->assertOk();
    $this->actingAs($adminC)->put(route('letters.templates.update', $platform), ['code' => 'P1', 'title_en' => 'Hijack'])->assertForbidden();
    // The steward holds manage_platform only, so an institution's template is not theirs.
    $this->actingAs($steward)->put(route('letters.templates.update', $ours), ['code' => 'A1', 'title_en' => 'Hijack'])->assertForbidden();
    $this->actingAs($adminA)->put(route('letters.templates.update', $ours), ['code' => 'A1', 'title_en' => 'Ours, renamed'])->assertRedirect();
    expect($ours->fresh()->title_en)->toBe('Ours, renamed');
});

it('lets an institution administrator set only its own numbering and needs city-wide reach for the platform default', function (): void {
    $adminA = $this->s->administrator('A');

    $this->actingAs($adminA)->put(route('letters.numbering.save'), ['organization_id' => $this->s->org['C']->id, 'direction' => 'incoming', 'pattern' => '{SEQ}'])->assertForbidden();
    $this->actingAs($adminA)->put(route('letters.numbering.save'), ['organization_id' => null, 'direction' => 'incoming', 'pattern' => '{SEQ}'])->assertForbidden();
    $this->actingAs($adminA)->put(route('letters.numbering.save'), ['organization_id' => $this->s->org['A']->id, 'direction' => 'incoming', 'pattern' => 'A/{SEQ}'])->assertSessionHasErrors('pattern');
    $this->actingAs($adminA)->put(route('letters.numbering.save'), ['organization_id' => $this->s->org['A']->id, 'direction' => 'incoming', 'language' => 'am', 'pattern' => 'A/{SEQ}'])->assertSessionHasNoErrors();
    $this->actingAs($adminA)->get(route('letters.numbering.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Numbering', false)->has('rules', 1)->where('platform', false));
});

it('answers 404 on every route while the module flag is off', function (): void {
    config()->set('features.letters', false);
    $letter = $this->s->incoming('A');
    $registrar = $this->s->registrar('A');

    $this->actingAs($registrar)->get(route('letters.dashboard'))->assertNotFound();
    $this->actingAs($registrar)->get(route('letters.show', $letter))->assertNotFound();
    $this->actingAs($registrar)->post(route('letters.register.store'), [])->assertNotFound();
});
