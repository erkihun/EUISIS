<?php

declare(strict_types=1);

use App\Models\CafeteriaSubsidyLedger;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Permission::findOrCreate('cafeteria_ledger.view', 'web');
    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo('cafeteria_ledger.view');
    $type = OrganizationType::query()->create(['code' => 'LEDGER', 'name_en' => 'Ledger']);
    $version = HierarchyVersion::query()->create(['version_name' => 'ledger-test', 'status' => 'published']);
    $this->people = [];
    foreach (['A', 'B'] as $code) {
        $org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => $code, 'name_en' => $code, 'status' => 'active']);
        $person = Employee::query()->create(['employee_number' => $code, 'first_name' => $code, 'last_name' => 'Employee', 'full_name' => $code.' Employee', 'status' => 'active']);
        $assignment = EmployeeAssignment::query()->create(['employee_id' => $person->id, 'organization_id' => $org->id, 'hierarchy_version_id' => $version->id, 'assignment_status' => 'active', 'effective_from' => '2026-01-01', 'is_current' => true]);
        $person->update(['current_assignment_id' => $assignment->id]);
        $this->people[] = $person;
        if ($code === 'A') {
            $this->actor->organizationScopes()->create(['organization_id' => $org->id, 'scope_type' => 'self', 'is_active' => true]);
        }
    }
});

function ledgerPageEntry(Employee $employee, array $overrides = []): CafeteriaSubsidyLedger
{
    return CafeteriaSubsidyLedger::query()->create(array_merge([
        'employee_id' => $employee->id, 'ledger_date' => '2026-09-01',
        'entry_type' => 'allocation', 'amount' => 10, 'balance_after' => 10, 'working_day' => true,
    ], $overrides));
}

it('scopes entries employee choices and all-page totals and supports pagination', function (): void {
    for ($i = 0; $i < 51; $i++) {
        ledgerPageEntry($this->people[0]);
    }
    ledgerPageEntry($this->people[1], ['amount' => 999]);
    $this->actingAs($this->actor)->get(route('cafeteria.ledger.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Cafeteria/Ledger/Index')
            ->has('entries', 50)->has('employees', 1)->where('meta.total', 51)
            ->where('summary.credits', 510)->where('summary.debits', 0)
            ->where('entries.0.employee.employee_number', 'A'));
    $this->get(route('cafeteria.ledger.index', ['page' => 2]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('meta.current_page', 2));
    $this->get(route('cafeteria.ledger.index', ['employee_id' => $this->people[1]->id]))->assertNotFound();
});

it('filters by date and type without changing the selected employee current balance', function (): void {
    ledgerPageEntry($this->people[0]);
    ledgerPageEntry($this->people[0], ['ledger_date' => '2026-09-02', 'entry_type' => 'extra_usage', 'amount' => -4, 'balance_after' => 6]);
    $this->actingAs($this->actor)->get(route('cafeteria.ledger.index', [
        'employee_id' => $this->people[0]->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-01', 'entry_type' => 'allocation',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('entries', 1)
        ->where('summary.net', 10)->where('balance', 6));
    $this->get(route('cafeteria.ledger.index', ['entry_type' => 'extra_usage']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('summary.debits', 4)->where('summary.net', -4));
});

it('rejects invalid ledger filters', function (): void {
    $this->actingAs($this->actor)->from(route('cafeteria.ledger.index'))->get(route('cafeteria.ledger.index', [
        'date_from' => '2026-09-10', 'date_to' => '2026-09-01', 'employee_id' => 'invalid', 'entry_type' => 'invalid',
    ]))->assertSessionHasErrors(['date_to', 'employee_id', 'entry_type']);
});

it('denies ledger access without permission', function (): void {
    $this->actingAs(User::factory()->create())->get(route('cafeteria.ledger.index'))->assertForbidden();
});
