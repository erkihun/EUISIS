<?php

declare(strict_types=1);

use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\GrievanceScenario;

use function Pest\Laravel\actingAs;

/*
 * Production risk register SCL-2: at 3M employees the employee pages must not
 * ship every unit and position as dropdown options. Options are sent for one
 * organization at a time (the page reloads them when the organization changes).
 */
beforeEach(function (): void {
    Permission::findOrCreate('employees.view', 'web');
    Permission::findOrCreate('employees.manage', 'web');
    $type = OrganizationType::query()->firstOrCreate(['code' => 'EFO'], ['name_en' => 'Employee form test']);
    $this->a = GrievanceScenario::org($type, 'A');
    $this->b = GrievanceScenario::org($type, 'B');
    $this->unitA = GrievanceScenario::unit($this->a, 'team', 'Unit A');
    $this->unitB = GrievanceScenario::unit($this->b, 'team', 'Unit B');
    foreach ([$this->a, $this->b] as $org) {
        Position::query()->create(['organization_id' => $org->id, 'job_position_code' => 'P-'.Str::upper(Str::random(6)), 'title_en' => 'Clerk', 'is_active' => true]);
    }
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo(['employees.view', 'employees.manage']);
});

it('sends no unit or position options on the index until an organization is chosen', function (): void {
    actingAs($this->admin)->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('organizationUnits', 0)->has('positions', 0));
});

it('sends only the chosen organization options on the index', function (): void {
    actingAs($this->admin)->get(route('employees.index', ['organization_id' => $this->a->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('organizationUnits', 1)->where('organizationUnits.0.id', $this->unitA->id)
            ->has('positions', 1)->where('positions.0.organization_id', $this->a->id));
});

it('sends only one organization options on the create form', function (): void {
    actingAs($this->admin)->get(route('employees.create', ['organization_id' => $this->b->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizationUnits', fn ($units) => collect($units)->pluck('organization_id')->unique()->values()->all() === [$this->b->id])
            ->where('positions', fn ($positions) => collect($positions)->pluck('organization_id')->unique()->values()->all() === [$this->b->id]));
});

it('does not send a position list to the edit page', function (): void {
    $employee = GrievanceScenario::employee($this->a, $this->unitA);

    actingAs($this->admin)->get(route('employees.edit', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('positions'));
});
