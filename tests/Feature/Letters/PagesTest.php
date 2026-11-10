<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Letters\Application\Actions\ApproveLetter;
use Modules\Letters\Application\Actions\DispatchLetter;
use Modules\Letters\Application\Actions\SubmitForApproval;
use Tests\Support\LettersScenario;

/*
 * Every page of the module renders its component with the props the screen
 * reads, and the PDF comes back as a PDF.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->s = LettersScenario::build();
});

it('renders the desk, the registry tabs, the register and compose pages', function (): void {
    $admin = $this->s->administrator('A');
    $this->s->platformTemplate();
    $incoming = $this->s->incoming('A', $admin);
    $draft = $this->s->outgoing('A', 'B', $admin);

    $this->actingAs($admin)->get(route('letters.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Dashboard', false)->where('counts.open', 1)->where('counts.drafts', 1)->has('recent', 1)->where('can.register', true));

    $this->actingAs($admin)->get(route('letters.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Index', false)->has('rows.data', 1)->where('rows.data.0.number', $incoming->referenceNumber())->where('rows.data.0.counterpart.name_en', 'Federal Civil Service Commission'));
    $this->actingAs($admin)->get(route('letters.index', ['tab' => 'drafts']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.id', $draft->id)->where('rows.data.0.status', 'draft'));
    $this->actingAs($admin)->get(route('letters.index', ['q' => 'FCSC']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 1));
    $this->actingAs($admin)->get(route('letters.index', ['q' => 'nothing-like-this']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data', []));

    $this->actingAs($admin)->get(route('letters.register'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Register', false)->where('selected_organization_id', $this->s->org['A']->id)->has('units', 1)->has('all_organizations', 3));
    $this->actingAs($admin)->get(route('letters.compose'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Compose', false)->where('mode', 'create')->has('templates', 1)->where('templates.0.code', 'TRANSMITTAL')->has('people'));
    $this->actingAs($admin)->get(route('letters.edit', $draft))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Compose', false)->where('mode', 'edit')->where('letter.id', $draft->id));
    $this->actingAs($admin)->get(route('letters.lookups.organization', $this->s->org['A']->id))->assertOk()->assertJsonStructure(['units', 'people', 'templates']);
    $this->actingAs($admin)->get(route('letters.lookups.organization', $this->s->org['C']->id))->assertForbidden();
});

it('renders the letter page with filled placeholders, the registry entries and the actions the viewer may take', function (): void {
    $officer = $this->s->officer('A');
    $signatory = $this->s->signatory('A');
    $letter = $this->s->outgoing('A', 'B', $officer, ['signatory_user_id' => $signatory->id, 'language' => 'en']);

    $this->actingAs($officer)->get(route('letters.show', $letter))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Show', false)
            ->where('letter.body', '<p>Dear Bole Sub-city, please find the return attached. Ref .</p>')
            ->where('letter.can.submit', true)->where('letter.can.approve', false)->where('letter.can.dispatch', false)->where('letter.can.pdf', true)
            ->where('letter.registrations', []));

    app(SubmitForApproval::class)->execute($letter, $officer);
    app(ApproveLetter::class)->execute($letter->fresh(), $signatory);
    app(DispatchLetter::class)->execute($letter->fresh(), $signatory);
    $this->s->forget();

    $this->actingAs($signatory)->get(route('letters.show', $letter))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('letter.status', 'dispatched')->has('letter.registrations', 2)->has('letter.attachments', 1)->where('letter.attachments.0.kind', 'rendered')
            ->where('letter.can.update', false)->where('letter.can.cancel', false)->has('letter.events'));
    expect($letter->fresh(['registrations'])->referenceNumber())->not->toBeNull();

    $this->actingAs($signatory)->get(route('letters.pdf', $letter))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('renders the template, numbering and report pages', function (): void {
    $admin = $this->s->administrator('A');
    $template = $this->s->platformTemplate();
    $this->s->incoming('A', $admin);

    $this->actingAs($admin)->get(route('letters.templates.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Templates/Index', false)->has('templates.data', 1)->where('templates.data.0.platform', true)->where('can.create', true));
    $this->actingAs($admin)->get(route('letters.templates.show', $template))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Templates/Show', false)->has('template.versions', 1)->where('can.update', false));
    $this->actingAs($admin)->get(route('letters.templates.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Templates/Form', false)->where('owners.platform', false)->has('owners.organizations', 1));
    $this->actingAs($admin)->get(route('letters.templates.edit', $template))->assertForbidden();

    $this->actingAs($admin)->get(route('letters.numbering.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Numbering', false)->where('rules', [])->has('tokens', 6));
    $this->actingAs($admin)->post(route('letters.numbering.preview'), ['organization_id' => $this->s->org['A']->id, 'direction' => 'incoming', 'pattern' => 'X/{SEQ}', 'padding' => 2])->assertOk()->assertJson(['preview' => 'X/02']);
    $this->actingAs($admin)->post(route('letters.numbering.preview'), ['organization_id' => $this->s->org['A']->id, 'direction' => 'incoming', 'language' => 'en', 'pattern' => 'X/{LANG}/{SEQ}', 'padding' => 2])->assertOk()->assertJson(['preview' => 'X/EN/01']);

    $this->actingAs($admin)->get(route('letters.reports'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Reports', false)->where('totals.by_direction.incoming', 1)->has('by_institution', 1)
            ->where('by_institution.0.incoming', 1)->where('by_institution.0.open', 1)->where('by_institution.0.closed', 0)->has('by_month', 1)->where('by_month.0.incoming', 1));
    $this->actingAs($this->s->officer('A'))->get(route('letters.reports'))->assertForbidden();
});

it('opens the pages when a filter arrives as a list instead of a single value', function (): void {
    $admin = $this->s->administrator('A');
    $this->s->incoming('A', $admin);

    // `?q[]=x` used to reach a string conversion and answer 500.
    $this->actingAs($admin)->get('/letters/registry?q[]=x&tab[]=inbox&organization_id[]=1')->assertOk();
    $this->actingAs($admin)->get('/letters/reports?from[]=2026-01-01')->assertOk();
    $this->actingAs($admin)->get('/letters/compose?direction[]=internal')->assertOk();
    $this->actingAs($admin)->get('/letters/letterheads?organization_id[]=x')->assertOk();
    $this->actingAs($admin)->get('/letters/registry/export?q[]=x')->assertOk();
});
