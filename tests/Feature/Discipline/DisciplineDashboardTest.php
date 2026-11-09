<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Discipline\Domain\Models\DisciplinaryCase;
use Tests\Support\DisciplineScenario;
use Tests\Support\GrievanceScenario;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    Storage::fake('local');
    $this->s = DisciplineScenario::build();
});

afterEach(fn () => Carbon::setTestNow());

/** A filed (charged) case, which starts the statutory clock. */
function dashboardChargedCase(object $test): DisciplinaryCase
{
    $test->actingAs($test->s->officer)->post(route('discipline.cases.store'), [
        'employee_number' => $test->s->accusedEmployee()->employee_number, 'track' => 'formal', 'severity' => 'serious', 'summary' => 'Absence',
    ])->assertRedirect();
    $case = DisciplinaryCase::query()->latest('created_at')->firstOrFail();
    $test->post(route('discipline.cases.charges.store', $case), [
        'title' => 'Absence', 'particulars' => 'Absent five days.', 'occurred_on' => '2026-09-01',
        'occurred_place' => 'Records Team', 'violated_provision' => 'Art. 95', 'evidence_list' => 'Attendance sheet',
    ])->assertSessionHasNoErrors();
    $test->post(route('discipline.cases.panel', $case), ['committee_id' => $test->s->committee->id])->assertSessionHasNoErrors();
    $test->post(route('discipline.cases.file', $case))->assertSessionHasNoErrors();

    return $case->refresh();
}

it('shows open cases by stage and the nearest statutory deadline, then flags it once missed', function (): void {
    $case = dashboardChargedCase($this);
    $deadline = $case->deadlines()->whereNull('completed_at')->whereNull('superseded_at')->orderBy('due_at')->firstOrFail();

    $this->actingAs($this->s->officer)->get('/discipline')->assertRedirect('/discipline/dashboard');
    $this->actingAs($this->s->officer)->get(route('discipline.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Discipline/Dashboard')
            ->where('figures.open', 1)->where('figures.overdue', 0)
            ->where('stages.charged', 1)->where('stages.draft', 0)
            ->where('queue.0.id', $case->id)->where('queue.0.next_deadline.id', $deadline->id)
            ->where('can.committees', true));

    // Past the deadline: counted as missed and listed under "needs attention".
    Carbon::setTestNow($deadline->due_at->copy()->addDay());
    $this->actingAs($this->s->officer)->get(route('discipline.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('figures.overdue', 1)->where('figures.due_soon', 0)
            ->where('attention.0.id', $case->id)->where('attention.0.next_deadline.overdue', true));
});

it('counts only the cases the viewer may see and stays closed to others', function (): void {
    dashboardChargedCase($this);

    // Another institution's officer sees none of it.
    $this->actingAs($this->s->outsider)->get(route('discipline.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('figures.open', 0)->where('queue', []));
    // A committee member not seated on the case sees the page, but not the case.
    $this->actingAs($this->s->bystander)->get(route('discipline.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('figures.open', 0));
    $nobody = GrievanceScenario::user(GrievanceScenario::employee($this->s->institution, null), ['dashboard.view']);
    $this->actingAs($nobody)->get(route('discipline.dashboard'))->assertForbidden();
});
