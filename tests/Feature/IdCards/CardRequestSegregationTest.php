<?php

declare(strict_types=1);

use App\Actions\IdCards\ApproveCardRequestAction;
use App\Actions\IdCards\SubmitCardRequestAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardRequestStatus;
use App\Enums\EmployeeStatus;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * Go-live UAT F3: the ID Card Officer requests a card and a different person,
 * the ID Card Approver, approves it. The officer used to get 403 on submit
 * (the controller checked employees.view, which the role does not hold), and
 * could approve requests, including their own, through cards.manage.
 * Uses the shipped roles, so a change to their grants is caught here too.
 */

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->officer = User::factory()->create()->assignRole('ID Card Officer');
    $this->approver = User::factory()->create()->assignRole('ID Card Approver');
    $this->employee = segregationEmployee();
});

function segregationEmployee(): Employee
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'SEG-T'], ['name_en' => 'Segregation type']);
    $org = Organization::query()->firstOrCreate(['code' => 'SEG-ORG'], ['organization_type_id' => $type->id, 'name_en' => 'Segregation org', 'status' => 'active']);
    $version = HierarchyVersion::query()->firstOrCreate(['version_name' => 'seg-v1'], ['status' => 'published']);
    $employee = Employee::query()->create([
        'employee_number' => 'SEG-'.uniqid(), 'first_name' => 'Seg', 'last_name' => 'Regation',
        'full_name' => 'Seg Regation', 'status' => EmployeeStatus::Active,
    ]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $org->id, 'hierarchy_version_id' => $version->id,
        'assignment_status' => AssignmentStatus::Active, 'effective_from' => now()->toDateString(), 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

it('lets the ID Card Officer submit a card request without employee registry access', function (): void {
    expect($this->officer->can('employees.view'))->toBeFalse();

    $this->actingAs($this->officer)
        ->post(route('card-requests.store'), ['employee_id' => $this->employee->id])
        ->assertRedirect(route('card-requests.index'))
        ->assertSessionHasNoErrors();

    expect(CardRequest::query()->where('employee_id', $this->employee->id)->where('requested_by', $this->officer->id)->exists())->toBeTrue();
});

it('does not let the ID Card Officer approve a request', function (): void {
    $request = app(SubmitCardRequestAction::class)->execute($this->employee, $this->officer);

    $this->actingAs($this->officer)
        ->post(route('card-requests.approve', $request))
        ->assertForbidden();

    $this->actingAs($this->officer)
        ->get(route('card-requests.show', $request))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.approve', false));

    expect($request->fresh()->status)->toBe(CardRequestStatus::Submitted);
});

it('lets the ID Card Approver approve a request someone else submitted', function (): void {
    $request = app(SubmitCardRequestAction::class)->execute($this->employee, $this->officer);

    $this->actingAs($this->approver)
        ->get(route('card-requests.show', $request))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.approve', true));

    $this->actingAs($this->approver)
        ->post(route('card-requests.approve', $request))
        ->assertRedirect();

    expect($request->fresh())
        ->status->toBe(CardRequestStatus::Approved)
        ->approved_by->toBe($this->approver->id);
});

it('refuses approval by the person who submitted the request', function (): void {
    $both = User::factory()->create()->assignRole(['ID Card Officer', 'ID Card Approver']);
    $request = app(SubmitCardRequestAction::class)->execute($this->employee, $both);

    $this->actingAs($both)
        ->get(route('card-requests.show', $request))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.approve', false));

    $this->actingAs($both)
        ->post(route('card-requests.approve', $request))
        ->assertForbidden();

    // The action enforces the same rule for any caller that skips the policy.
    expect(fn () => app(ApproveCardRequestAction::class)->execute($request->fresh(), $both))
        ->toThrow(DomainException::class, 'someone other than the person who submitted it');
    expect($request->fresh()->status)->toBe(CardRequestStatus::Submitted);
});

it('holds a Super Admin to the same rule', function (): void {
    $admin = User::factory()->create()->assignRole('Super Admin');
    $request = app(SubmitCardRequestAction::class)->execute($this->employee, $admin);

    expect($admin->can('approve', $request))->toBeFalse()
        ->and($admin->can('view', $request))->toBeTrue();

    $this->actingAs($admin)
        ->post(route('card-requests.approve', $request))
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(CardRequestStatus::Submitted);
});
