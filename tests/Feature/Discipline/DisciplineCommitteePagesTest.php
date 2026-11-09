<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Modules\Discipline\Contracts\Data\DisciplineRoles;
use Modules\Grievance\Contracts\Data\GrievanceRoles;
use Modules\Grievance\Domain\Models\GrievanceCommittee;
use Tests\Support\GrievanceScenario;

/*
 * ADR 0031: Discipline Management sets up its own (disciplinary) committees on
 * its own pages and permissions; Grievance Management no longer shows them.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $this->s = GrievanceScenario::build();
    $this->officer = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), [...DisciplineRoles::CASE_OFFICER_PERMISSIONS]);
    GrievanceScenario::scope($this->officer, $this->s->woreda);
});

afterEach(fn () => Carbon::setTestNow());

it('lets a discipline case officer set up a disciplinary committee from Discipline Management', function (): void {
    $this->actingAs($this->officer)->get(route('discipline.committees.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Grievances/Committees/Index')
            ->where('domain', 'discipline')->where('route_prefix', 'discipline.committees')->where('options.types', ['disciplinary']));
    $this->actingAs($this->officer)->get(route('discipline.committees.create'))->assertOk();

    $response = $this->actingAs($this->officer)->post(route('discipline.committees.store'), [
        'organization_id' => $this->s->woreda->id, 'name_en' => 'Woreda Disciplinary Committee', 'effective_from' => '2026-10-01',
    ]);
    $committee = GrievanceCommittee::query()->where('name_en', 'Woreda Disciplinary Committee')->sole();
    $response->assertRedirect(route('discipline.committees.show', $committee));
    expect($committee->committee_type->value)->toBe('disciplinary');

    $this->actingAs($this->officer)->get(route('discipline.committees.show', $committee))->assertOk();
    // A grievance committee is not reachable from Discipline Management.
    $this->actingAs($this->officer)->get(route('discipline.committees.show', $this->s->committee))->assertNotFound();
    // Without grievance rights the grievance board stays closed.
    $this->actingAs($this->officer)->get(route('grievances.committees.index'))->assertForbidden();
});

it('keeps disciplinary committees out of Grievance Management and under discipline permissions', function (): void {
    $this->actingAs($this->officer)->post(route('discipline.committees.store'), ['organization_id' => $this->s->woreda->id, 'name_en' => 'Woreda Disciplinary Committee']);
    $disciplinary = GrievanceCommittee::query()->where('name_en', 'Woreda Disciplinary Committee')->sole();

    $grievanceAdmin = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    GrievanceScenario::scope($grievanceAdmin, $this->s->woreda);
    $this->actingAs($grievanceAdmin)->get(route('grievances.committees.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('domain', 'grievance')
            ->where('committees.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($disciplinary->id) && collect($rows)->pluck('id')->contains($this->s->committee->id))
            ->where('options.types', fn ($types) => ! collect($types)->contains('disciplinary')));
    $this->actingAs($grievanceAdmin)->get(route('grievances.committees.show', $disciplinary))->assertNotFound();
    // Grievance committee rights do not reach a disciplinary committee.
    $this->actingAs($grievanceAdmin)->get(route('discipline.committees.index'))->assertForbidden();
    $this->actingAs($grievanceAdmin)->patch(route('discipline.committees.update', $disciplinary), ['name_en' => 'Renamed'])->assertForbidden();

    // The case officer edits it; only the decision authority (discipline.decide) may approve it.
    $this->actingAs($this->officer)->patch(route('discipline.committees.update', $disciplinary), ['name_en' => 'Woreda Discipline Committee'])->assertSessionHasNoErrors();
    expect($disciplinary->refresh()->name_en)->toBe('Woreda Discipline Committee');
    $this->actingAs($this->officer)->post(route('discipline.committees.approve', $disciplinary))->assertForbidden();
    $authority = GrievanceScenario::user(GrievanceScenario::employee($this->s->woreda, null), [...DisciplineRoles::DECISION_AUTHORITY_PERMISSIONS]);
    GrievanceScenario::scope($authority, $this->s->woreda);
    // Allowed to approve: refused only because nobody sits on the committee yet.
    $this->actingAs($authority)->post(route('discipline.committees.approve', $disciplinary))->assertSessionHasErrors();
});
