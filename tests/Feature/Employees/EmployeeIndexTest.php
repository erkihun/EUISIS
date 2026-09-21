<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function registryUser(bool $manage = false, ?Organization $organization = null): User
{
    $user = User::factory()->create();
    foreach ($manage ? ['employees.view', 'employees.manage'] : ['employees.view'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    if ($organization) {
        $user->organizationScopes()->create(['organization_id' => $organization->id, 'scope_type' => 'self', 'is_active' => true]);
    }

    return $user;
}

function registryEmployee(Organization $organization, string $number): Employee
{
    $employee = Employee::query()->create(['employee_number' => $number, 'first_name' => 'ሙከራ', 'last_name' => 'ሠራተኛ', 'full_name' => 'ሙከራ ሠራተኛ', 'name_en' => 'Registry Example', 'email' => strtolower($number).'@example.test', 'status' => 'active']);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $organization->id, 'assignment_status' => 'active', 'effective_from' => now()->toDateString(), 'is_current' => true]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee;
}

beforeEach(function (): void {
    $type = \App\Models\OrganizationType::query()->create(['code' => 'REG', 'name_en' => 'Registry type']);
    $this->own = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'REG-A', 'name_en' => 'Registry A', 'status' => 'active']);
    $this->outside = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'REG-B', 'name_en' => 'Registry B', 'status' => 'active']);
    $this->employee = registryEmployee($this->own, 'REG-001');
    registryEmployee($this->outside, 'REG-002');
});

test('registry capabilities match the existing manage permission', function (bool $manage): void {
    $this->actingAs(registryUser($manage))->get(route('employees.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Employees/Index')->where('can.create', $manage)->where('can.update', $manage));
})->with([false, true]);

test('registry searches trimmed email and English employee names', function (string $search, int $count): void {
    $this->actingAs(registryUser())->get(route('employees.index', ['search' => $search]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.search', trim($search))->has('employees', $count)->where('employees_pagination.total', $count));
})->with([[' reg-001@example.test ', 1], [' Registry Example ', 2], [' REG-001 ', 1], ['ሙከራ', 2]]);

test('registry counts rows and filter options stay within organization scope', function (): void {
    foreach ([$this->own, $this->outside] as $organization) {
        $unit = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'code' => $organization->code.'-U', 'name_en' => 'Registry unit', 'unit_type' => 'department', 'status' => 'active']);
        Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => $organization->code.'-P', 'title_en' => 'Registry position', 'is_active' => true]);
    }
    $this->actingAs(registryUser(false, $this->own))->get(route('employees.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('employees', 1)->where('employees.0.id', $this->employee->id)
            ->where('employees_pagination.total', 1)->has('organizations', 1)->has('organizationUnits', 1)->has('positions', 1)
            ->where('filters.organization_id', $this->own->id)->missing('organizationStructure'));
    $this->get(route('employees.index', ['organization_id' => $this->outside->id]))->assertForbidden();
});

test('read-only registry users cannot open employee edit or create', function (): void {
    $this->actingAs(registryUser())->get(route('employees.edit', $this->employee))->assertForbidden();
    $this->get(route('employees.create'))->assertForbidden();
});

test('registry pagination stays bounded and preserves search', function (): void {
    for ($index = 3; $index <= 53; $index++) registryEmployee($this->own, 'REG-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
    $this->actingAs(registryUser())->get(route('employees.index', ['search' => 'REG-', 'page' => 2]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('employees', 3)->where('employees_pagination.per_page', 50)
            ->where('employees_pagination.current_page', 2)->where('employees_pagination.total', 53)->where('filters.search', 'REG-'));
});

test('registry sorts on a requested whitelisted column', function (): void {
    $this->actingAs(registryUser())->get(route('employees.index', ['sort' => 'employee_number', 'direction' => 'desc']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sort.column', 'employee_number')->where('sort.direction', 'desc')
            ->where('employees.0.employee_number', 'REG-002'));
});

/* The sort column reaches orderBy() as a raw identifier, so anything off the
 * whitelist must fall back rather than be passed through. */
test('registry ignores a sort column that is not whitelisted', function (string $sort): void {
    $this->actingAs(registryUser())->get(route('employees.index', ['sort' => $sort, 'direction' => 'sideways']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sort.column', 'full_name')->where('sort.direction', 'asc'));
})->with(['password', 'employees.id) --', '']);
