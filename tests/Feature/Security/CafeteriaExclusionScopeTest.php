<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Models\Employee;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\GrievanceScenario;

use function Pest\Laravel\actingAs;

/*
 * Production risk register SEC-6 / SCL-3: cafeteria exclusions record leave
 * and suspension reasons, so they are organization-scoped like other HR data,
 * and the create form searches employees instead of listing all of them.
 */
beforeEach(function (): void {
    foreach (['view', 'create', 'update', 'end', 'archive'] as $action) {
        Permission::findOrCreate("cafeteria_employee_exclusions.{$action}", 'web');
    }
    $type = OrganizationType::query()->firstOrCreate(['code' => 'CEX'], ['name_en' => 'Exclusion test']);
    $this->own = GrievanceScenario::org($type, 'Own');
    $this->other = GrievanceScenario::org($type, 'Other');
    $this->ownEmployee = GrievanceScenario::employee($this->own, null);
    $this->otherEmployee = GrievanceScenario::employee($this->other, null);
    $this->ownExclusion = exclusionFor($this->ownEmployee);
    $this->otherExclusion = exclusionFor($this->otherEmployee);

    $this->officer = User::factory()->create();
    $this->officer->givePermissionTo(['cafeteria_employee_exclusions.view', 'cafeteria_employee_exclusions.create', 'cafeteria_employee_exclusions.end']);
    UserOrganizationScope::query()->create([
        'user_id' => $this->officer->id, 'organization_id' => $this->own->id,
        'scope_type' => OrganizationScopeType::Self, 'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);
    $this->officer = $this->officer->fresh();
});

function exclusionFor(Employee $employee): EmployeeCafeteriaExclusion
{
    return EmployeeCafeteriaExclusion::query()->create([
        'employee_id' => $employee->id, 'exclusion_type' => 'leave', 'status' => 'active',
        'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addWeek()->toDateString(),
        'reason_en' => 'Annual leave',
    ]);
}

test('a scoped officer lists only exclusions of their organizations', function (): void {
    actingAs($this->officer)
        ->get(route('cafeteria.employee-exclusions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('exclusions', 1)
            ->where('exclusions.0.id', $this->ownExclusion->id)
            ->where('active_count', 1));
});

test('the create form ships no employee list', function (): void {
    actingAs($this->officer)
        ->get(route('cafeteria.employee-exclusions.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('employees'));
});

test('a scoped officer cannot open, end or create an exclusion outside their scope', function (): void {
    actingAs($this->officer)->get(route('cafeteria.employee-exclusions.show', $this->otherExclusion))->assertForbidden();
    actingAs($this->officer)->post(route('cafeteria.employee-exclusions.end', $this->otherExclusion))->assertForbidden();

    actingAs($this->officer)->post(route('cafeteria.employee-exclusions.store'), [
        'employee_id' => $this->otherEmployee->id, 'exclusion_type' => 'leave',
        'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(3)->toDateString(),
        'reason_en' => 'Leave',
    ])->assertForbidden();

    expect(EmployeeCafeteriaExclusion::query()->where('employee_id', $this->otherEmployee->id)->count())->toBe(1);
});
