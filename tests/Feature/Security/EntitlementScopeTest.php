<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Models\Employee;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\GrievanceScenario;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    foreach (['entitlements.view', 'entitlements.manage', 'employees.viewAny'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $type = OrganizationType::query()->firstOrCreate(['code' => 'ENT'], ['name_en' => 'Entitlement test']);
    $this->own = GrievanceScenario::org($type, 'Own Office');
    $this->other = GrievanceScenario::org($type, 'Other Office');
    $this->ownEmployee = GrievanceScenario::employee($this->own, null);
    $this->otherEmployee = GrievanceScenario::employee($this->other, null);
    $this->serviceType = ServiceType::query()->create(['code' => 'SVC-'.Str::random(5), 'name_en' => 'Cafeteria', 'is_active' => true]);
});

function scopedEntitlementOfficer(Organization $org, array $permissions = ['entitlements.view', 'entitlements.manage']): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    UserOrganizationScope::query()->create([
        'user_id' => $user->id, 'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Self, 'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user->fresh();
}

function grantFor(Employee $employee, ServiceType $type): Entitlement
{
    return Entitlement::query()->create([
        'employee_id' => $employee->id, 'service_type_id' => $type->id, 'status' => 'active',
        'quota_limit' => 30, 'quota_used' => 0, 'effective_from' => now()->toDateString(),
    ]);
}

test('a scoped officer lists only entitlements of employees in their organizations', function (): void {
    $mine = grantFor($this->ownEmployee, $this->serviceType);
    grantFor($this->otherEmployee, $this->serviceType);

    actingAs(scopedEntitlementOfficer($this->own))
        ->get(route('entitlements.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Entitlements/Index')
            ->missing('employees')
            ->has('entitlements.data', 1)
            ->where('entitlements.data.0.id', $mine->id)
            ->missing('entitlements.data.0.employee.phone')
            ->missing('entitlements.data.0.employee.email'));
});

test('a scoped officer cannot grant an entitlement to an employee outside their scope', function (): void {
    actingAs(scopedEntitlementOfficer($this->own))
        ->post(route('entitlements.store'), [
            'employee_id' => $this->otherEmployee->id,
            'service_type_id' => $this->serviceType->id,
            'quota_limit' => 10,
        ])
        ->assertForbidden();

    expect(Entitlement::query()->where('employee_id', $this->otherEmployee->id)->exists())->toBeFalse();
});

test('the employee lookup returns only in-scope employees and no contact data', function (): void {
    $officer = scopedEntitlementOfficer($this->own);
    $term = mb_substr($this->ownEmployee->employee_number, 0, 6); // shared "EMP-" prefix

    $rows = actingAs($officer)
        ->getJson(route('lookup.employees', ['q' => $term]))
        ->assertOk()
        ->json('data');

    $ids = collect($rows)->pluck('id');
    expect($ids)->toContain($this->ownEmployee->id)
        ->and($ids)->not->toContain($this->otherEmployee->id)
        ->and($rows[0])->not->toHaveKeys(['phone', 'email', 'address', 'date_of_birth']);
});

test('the employee lookup needs at least two characters and a permitted user', function (): void {
    actingAs(scopedEntitlementOfficer($this->own))
        ->getJson(route('lookup.employees', ['q' => 'E']))
        ->assertOk()
        ->assertExactJson(['data' => []]);

    actingAs(User::factory()->create())
        ->getJson(route('lookup.employees', ['q' => 'EMP']))
        ->assertForbidden();
});
