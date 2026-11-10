<?php

declare(strict_types=1);

use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Letters\Domain\Enums\RoutingStatus;
use Modules\Letters\Domain\Models\LetterRegistryOffice;
use Modules\Letters\Domain\Models\LetterRouting;
use Tests\Support\LettersScenario;

/*
 * The registry office and the forwarding chain (ADR 0037 §14): a unit of the
 * structure is designated as the institution's registry, forwarding starts
 * from it, and the person a letter was forwarded to passes it on.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->s = LettersScenario::build();
});

/** Places the user's employee in a unit of the structure. */
function placeIn(User $user, OrganizationUnit $unit): User
{
    $user->employee->currentAssignment->update(['organization_unit_id' => $unit->id]);

    return $user->fresh();
}

it('designates a unit of the structure as the registry office and shows who works there', function (): void {
    $admin = $this->s->administrator('A');
    $org = $this->s->org['A']->id;
    $unit = $this->s->unitA;
    $registrar = placeIn($this->s->registrar('A'), $unit);
    $handler = placeIn($this->s->handler('A'), $unit);

    // Nothing designated yet: the unit is offered, no office is listed.
    $this->actingAs($admin)->get(route('letters.registry-offices.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/RegistryOffices')->where('selected_organization_id', $org)->has('offices', 0)->where('units.0.id', $unit->id));

    $this->actingAs($admin)->post(route('letters.registry-offices.store'), ['organization_id' => $org, 'organization_unit_id' => $unit->id, 'note' => 'Main registry'])->assertSessionHasNoErrors();
    $office = LetterRegistryOffice::query()->firstOrFail();
    expect($office->organization_unit_id)->toBe($unit->id)->and($office->status)->toBe('active');

    // The staff come from the structure, with what each can do; the unit is no longer offered.
    $this->actingAs($admin)->get(route('letters.registry-offices.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('units', 0)->where('offices.0.unit.id', $unit->id)->where('offices.0.note', 'Main registry')->where('offices.0.problems', [])
        ->has('offices.0.staff', 2)
        ->where('offices.0.staff', fn ($staff) => collect($staff)->firstWhere('id', $registrar->id)['can_register'] === true
            && collect($staff)->firstWhere('id', $registrar->id)['can_route'] === true
            && collect($staff)->firstWhere('id', $handler->id)['can_register'] === false));

    // The same unit twice, another institution's unit, and other people are refused.
    $this->actingAs($admin)->post(route('letters.registry-offices.store'), ['organization_id' => $org, 'organization_unit_id' => $unit->id])->assertSessionHasErrors('organization_unit_id');
    $this->actingAs($admin)->post(route('letters.registry-offices.store'), ['organization_id' => $this->s->org['C']->id, 'organization_unit_id' => $unit->id])->assertSessionHasErrors('organization_unit_id');
    $this->actingAs($this->s->registrar('A'))->get(route('letters.registry-offices.index'))->assertForbidden();
    $this->actingAs($this->s->administrator('C'))->put(route('letters.registry-offices.update', $office), ['status' => 'inactive'])->assertForbidden();
    $this->actingAs($this->s->administrator('C'))->delete(route('letters.registry-offices.destroy', $office))->assertForbidden();

    // A registry nobody can work in says so.
    $registrar->employee->currentAssignment->update(['organization_unit_id' => null]);
    $this->actingAs($admin)->get(route('letters.registry-offices.index'))->assertInertia(fn (Assert $page) => $page->where('offices.0.problems', ['no_registrar', 'no_router']));

    $this->actingAs($admin)->put(route('letters.registry-offices.update', $office), ['status' => 'inactive', 'note' => null])->assertSessionHasNoErrors();
    expect($office->fresh()->status)->toBe('inactive');
    $this->actingAs($admin)->delete(route('letters.registry-offices.destroy', $office))->assertSessionHasNoErrors();
    expect(LetterRegistryOffice::query()->count())->toBe(0)->and(OrganizationUnit::query()->whereKey($unit->id)->exists())->toBeTrue();
});

it('starts forwarding from the registry office and lets the person a letter was forwarded to pass it on', function (): void {
    $org = $this->s->org['A']->id;
    $registry = $this->s->unitA;
    $team = OrganizationUnit::query()->create(['organization_id' => $org, 'unit_type' => 'team', 'code' => 'LT-A-TEAM', 'name_en' => 'Staffing Team', 'name_am' => 'Staffing Team', 'status' => 'active']);
    LetterRegistryOffice::query()->create(['organization_id' => $org, 'organization_unit_id' => $registry->id, 'status' => 'active']);
    $registrar = $this->s->registrar('A');
    $head = placeIn($this->s->handler('A'), $team);
    $officer = $this->s->handler('A');
    $stranger = $this->s->handler('A');

    $letter = $this->s->incoming('A', $registrar);
    $registration = $letter->registrations->first();

    // The registry desk forwards to the head: the step comes from the registry office.
    $this->actingAs($registrar)->post(route('letters.routings.store', $registration), ['to_user_id' => $head->id, 'instruction' => 'For your action'])->assertSessionHasNoErrors();
    $first = LetterRouting::query()->where('registration_id', $registration->id)->firstOrFail();
    expect($first->from_unit_id)->toBe($registry->id)->and($first->parent_id)->toBeNull()->and($first->status)->toBe(RoutingStatus::Pending);

    // Only the person it was forwarded to passes it on; the registry desk route stays closed to them.
    $this->actingAs($stranger)->post(route('letters.routings.forward', $first), ['to_user_id' => $officer->id, 'instruction' => 'x'])->assertForbidden();
    $this->actingAs($head)->post(route('letters.routings.store', $registration), ['to_user_id' => $officer->id, 'instruction' => 'x'])->assertForbidden();
    $this->actingAs($head)->post(route('letters.routings.forward', $first), ['instruction' => 'no addressee'])->assertSessionHasErrors('to_user_id');

    $this->actingAs($head)->post(route('letters.routings.forward', $first), ['to_user_id' => $officer->id, 'instruction' => 'Prepare the return', 'due_on' => '2026-11-18'])->assertSessionHasNoErrors();
    $second = LetterRouting::query()->where('parent_id', $first->id)->firstOrFail();
    expect($second->step)->toBe(2)->and($second->from_user_id)->toBe($head->id)->and($second->from_unit_id)->toBe($team->id)
        ->and($first->fresh()->status)->toBe(RoutingStatus::Acknowledged)
        ->and($registration->fresh()->status->value)->toBe('in_progress');

    // The letter page shows the chain and offers "pass on" to each addressee for their own step only.
    $this->actingAs($officer)->get(route('letters.show', $letter))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('letter.registrations.0.routings.0.from_unit.id', $registry->id)->where('letter.registrations.0.routings.0.can_forward', false)
        ->where('letter.registrations.0.routings.1.parent_step', 1)->where('letter.registrations.0.routings.1.from_unit.id', $team->id)
        ->where('letter.registrations.0.routings.1.can_forward', true)
        ->has('routing_lookups.'.$org.'.people'));

    // A finished step cannot be passed on.
    $this->actingAs($officer)->post(route('letters.routings.complete', $second), ['outcome' => 'Return prepared'])->assertSessionHasNoErrors();
    $this->actingAs($officer)->post(route('letters.routings.forward', $second), ['to_user_id' => $head->id, 'instruction' => 'again'])->assertForbidden();
});
