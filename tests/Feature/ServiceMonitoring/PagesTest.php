<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Tests\Support\ServiceMonitoringScenario;

/*
 * Every page of the module renders a real Inertia page with its props for a
 * user who may open it.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->s = ServiceMonitoringScenario::build();
    $this->s->at(1);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('renders the coordinator pages', function (): void {
    $coordinator = $this->s->coordinator();
    $this->s->accepted('wA1');

    $pages = [
        ['service-monitoring.dashboard', [], 'ServiceMonitoring/Dashboard'],
        ['service-monitoring.periods.index', [], 'ServiceMonitoring/Periods/Index'],
        ['service-monitoring.periods.show', [$this->s->period(1)], 'ServiceMonitoring/Periods/Show'],
        ['service-monitoring.participants.index', [], 'ServiceMonitoring/Participants/Index'],
        ['service-monitoring.submissions.index', [], 'ServiceMonitoring/Submissions/Index'],
        ['service-monitoring.review.queue', [], 'ServiceMonitoring/Review/Queue'],
        ['service-monitoring.consolidation.sub-city', [], 'ServiceMonitoring/Consolidation/SubCity'],
        ['service-monitoring.consolidation.city', [], 'ServiceMonitoring/Consolidation/City'],
        ['service-monitoring.clarifications.index', [], 'ServiceMonitoring/Clarifications/Index'],
        ['service-monitoring.visits.index', [], 'ServiceMonitoring/Visits/Index'],
        ['service-monitoring.action-items.index', [], 'ServiceMonitoring/ActionItems/Index'],
        ['service-monitoring.reports.index', [], 'ServiceMonitoring/Reports/Index'],
        ['service-monitoring.settings.edit', [], 'ServiceMonitoring/Settings'],
    ];
    foreach ($pages as [$name, $params, $component]) {
        $this->actingAs($coordinator)->get(route($name, $params))->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
    }

    $this->actingAs($coordinator)->get(route('service-monitoring.consolidation.city', ['range' => 'annual', 'quarter' => 1]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('report.range', 'annual')->has('forms', 2)->has('forms.0.sections.section_1'));
});

it('renders the institution, verifier and support pages', function (): void {
    $head = $this->s->head('wA1');
    $submission = $this->s->start('wA1');

    $this->actingAs($head)->get(route('service-monitoring.submissions.edit', $submission))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Submissions/Edit')
            ->where('definition.form_type', 'B')->where('can.submit', true)->has('section2.prefill')->has('definition.section_2.fields', 13));
    $this->actingAs($head)->get(route('service-monitoring.submissions.show', $submission))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Submissions/Show')->has('form.sections.summary'));

    $verifier = $this->s->verifier();
    $this->actingAs($verifier)->get(route('service-monitoring.verification.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Verification/Index'));

    $clarification = Clarification::factory()->create(['participant_id' => $this->s->p['wA1']->id, 'opened_by' => $head->id]);
    $this->actingAs($head)->get(route('service-monitoring.clarifications.show', $clarification))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Clarifications/Show'));

    $visit = MonitoringVisit::factory()->create(['participant_id' => $this->s->p['wA1']->id]);
    $this->actingAs($head)->get(route('service-monitoring.visits.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Visits/Show')->where('can.update', false));

    // A submitted report redirects from the entry screen to the read-only one.
    $this->s->submit($this->s->complete($submission));
    $this->actingAs($head)->get(route('service-monitoring.submissions.edit', $submission))->assertRedirect(route('service-monitoring.submissions.show', $submission));
});

it('renders the sub-city consolidation with its woredas for the reviewer', function (): void {
    $reviewer = $this->s->reviewer('subA');
    $this->s->accepted('wA1');

    $this->actingAs($reviewer)->get(route('service-monitoring.consolidation.sub-city'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Consolidation/SubCity')
            ->where('selection.participant_id', $this->s->p['subA']->id)->has('woredas', 2)->has('forms', 1));
});
