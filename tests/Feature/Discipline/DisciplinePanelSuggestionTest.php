<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Discipline\Domain\Models\DisciplinaryCase;
use Tests\Support\DisciplineScenario;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    Storage::fake('local');
    $this->s = DisciplineScenario::build();
});

afterEach(fn () => Carbon::setTestNow());

it('pre-selects the committee serving the accused\'s work unit, else the all-staff one', function (): void {
    $this->actingAs($this->s->officer)->post(route('discipline.cases.store'), [
        'employee_number' => $this->s->accusedEmployee()->employee_number, 'track' => 'formal', 'severity' => 'serious', 'summary' => 'Absence',
    ])->assertRedirect();
    $case = DisciplinaryCase::query()->latest('created_at')->firstOrFail();
    expect($case->organization_unit_id)->not->toBeNull();

    // Only the all-staff committee: it is suggested.
    $this->actingAs($this->s->officer)->get(route('discipline.cases.show', $case))
        ->assertInertia(fn (Assert $page) => $page->where('committees.0.id', $this->s->committee->id)
            ->where('committees.0.suggested', true)->where('committees.0.unit', null));

    // A complete committee for the accused's own work unit takes over the suggestion.
    $unitCommittee = $this->s->committee->replicate()->fill(['name_en' => 'Unit Disciplinary Committee', 'organization_unit_id' => $case->organization_unit_id]);
    $unitCommittee->save();
    foreach ($this->s->committee->members as $member) {
        $member->replicate()->fill(['committee_id' => $unitCommittee->id])->save();
    }
    $this->actingAs($this->s->officer)->get(route('discipline.cases.show', $case))
        ->assertInertia(fn (Assert $page) => $page->where('committees.0.id', $unitCommittee->id)
            ->where('committees.0.suggested', true)->where('committees.1.suggested', false)
            ->where('committees.0.unit', fn ($unit) => is_string($unit) && $unit !== ''));

    // Still the officer's choice: the all-staff committee can be seated instead.
    $this->post(route('discipline.cases.panel', $case), ['committee_id' => $this->s->committee->id])->assertSessionHasNoErrors();
    expect($case->refresh()->committee_id)->toBe($this->s->committee->id);
});
