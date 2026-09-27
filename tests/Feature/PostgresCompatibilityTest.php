<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/*
 * Pages that failed when the app first ran on PostgreSQL (2026-09-26). The
 * suite runs on SQLite, which — like MySQL — treats `uuid_column = ''` as "no
 * match"; PostgreSQL rejects it as invalid input. So these requests must
 * never reach the database with an empty or non-uuid key, which is what the
 * assertions below pin down on any engine.
 */
beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    Role::findOrCreate('Super Admin', 'web');
    $this->admin = User::factory()->create(['status' => 'active', 'must_change_password' => false])->assignRole('Super Admin');
});

it('renders the new cafeteria exclusion page with employee names', function (): void {
    // It selected first_name_en / last_name_en, columns no migration ever created.
    Employee::query()->create([
        'employee_number' => 'EXC-1', 'first_name' => 'Hana', 'last_name' => 'Girma',
        'full_name' => 'Hana Girma', 'status' => EmployeeStatus::Active,
    ]);

    $this->actingAs($this->admin)->get(route('cafeteria.employee-exclusions.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('employees.0.name', 'Hana Girma')->where('employees.0.number', 'EXC-1'));
});

it('answers the scan day endpoints without a cafeteria with a validation error', function (string $route): void {
    $this->actingAs($this->admin)->getJson(route($route))->assertUnprocessable()->assertJsonValidationErrors('provider_id');
    $this->actingAs($this->admin)->getJson(route($route, ['provider_id' => 'not-a-uuid']))->assertUnprocessable();
})->with(['cafeteria.scan.today', 'cafeteria.scan.calendar']);

it('answers the performance unit and position lookups without an organization with a validation error', function (string $route): void {
    $this->actingAs($this->admin)->getJson(route($route))->assertUnprocessable()->assertJsonValidationErrors('organization_id');
})->with(['performance.lookups.units', 'performance.lookups.positions']);

it('opens the performance dashboard before any cycle exists', function (): void {
    $this->actingAs($this->admin)->get(route('performance.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Performance/Dashboard')->where('cycleId', ''));
});
