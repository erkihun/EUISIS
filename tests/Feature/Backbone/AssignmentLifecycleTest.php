<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\OccupancyStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmploymentStatusHistory;
use App\Models\Integration\IntegrationEvent;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionOccupancy;
use App\Models\User;
use App\Services\Positions\PositionCapacityService;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;

/*
 * ADR 0002, decision 2: an assignment ends without a transfer only when the
 * employment ends, and resumes only in the employee's last position while it
 * has a free seat. History is kept: nothing is deleted or rewritten.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-21 09:00'));
    $type = OrganizationType::query()->create(['code' => 'AL-T', 'name_en' => 'Lifecycle type']);
    $this->organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'AL-ORG', 'name_en' => 'Lifecycle bureau', 'status' => 'active']);
    $this->position = Position::query()->create(['organization_id' => $this->organization->id, 'job_position_code' => 'AL-P', 'title_en' => 'Officer', 'is_active' => true]);
    [$this->employee, $this->assignment] = lifecycleEmployee($this->organization, $this->position, 'AL-E1');

    // The seat is also on the establishment ledger, as a completed transfer leaves it.
    $establishment = PositionEstablishment::query()->create(['establishment_number' => 'AL-EST', 'organization_id' => $this->organization->id, 'position_id' => $this->position->id, 'approved_slots' => 1, 'effective_from' => '2025-01-01', 'status' => 'approved']);
    app(PositionCapacityService::class)->recordOccupancy($establishment, $this->assignment, Carbon::parse('2025-03-01'));

    foreach (['employees.view', 'employees.update', 'employees.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->hr = User::factory()->create();
    $this->hr->givePermissionTo(['employees.view', 'employees.update', 'employees.manage']);
});

/** @return array{0: Employee, 1: EmployeeAssignment} */
function lifecycleEmployee(Organization $organization, Position $position, string $number): array
{
    $employee = Employee::query()->create(['employee_number' => $number, 'first_name' => 'Life', 'last_name' => 'Cycle', 'full_name' => 'Life Cycle', 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $organization->id, 'position_id' => $position->id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2025-03-01',
    ]);
    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();
    EmploymentStatusHistory::query()->create(['employee_id' => $employee->id, 'status' => EmployeeStatus::Active, 'effective_from' => '2025-03-01']);

    return [$employee->fresh(), $assignment];
}

/** Days later the idle-timeout policy has ended the old session; start a fresh one. */
function signInAgain($test): void
{
    $test->flushSession();
    $test->actingAs($test->hr);
}

function changeEmploymentStatus($test, Employee $employee, string $status)
{
    return $test->patch(route('employees.update', $employee), [
        'first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'status' => $status,
    ]);
}

it('ends the placement and frees the seat when employment ends', function (): void {
    $this->actingAs($this->hr);
    changeEmploymentStatus($this, $this->employee, 'terminated')->assertSessionHasNoErrors()->assertRedirect();

    $assignment = $this->assignment->fresh();
    expect($assignment->assignment_status)->toBe(AssignmentStatus::Closed)
        ->and($assignment->effective_to->toDateString())->toBe('2026-10-21')
        // Still the latest record and still what the employee points at: their organization stays known.
        ->and($assignment->is_current)->toBeTrue()
        ->and($this->employee->fresh()->current_assignment_id)->toBe($assignment->id)
        ->and(PositionOccupancy::query()->where('employee_assignment_id', $assignment->id)->value('status'))->toBe(OccupancyStatus::Released)
        ->and(app(PositionCapacityService::class)->hasFreeSlot($this->position->id))->toBeTrue()
        ->and(EmploymentStatusHistory::query()->where('employee_id', $this->employee->id)->orderBy('effective_from')->pluck('status')->map->value->all())->toBe(['active', 'terminated'])
        ->and(AuditLog::query()->where('event_type', 'assignment_changed')->where('auditable_id', $assignment->id)->exists())->toBeTrue()
        ->and(IntegrationEvent::query()->where('event_type', 'assignment.closed')->where('aggregate_uuid', $assignment->id)->count())->toBe(1)
        ->and(IntegrationEvent::query()->where('event_type', 'position.vacated')->where('aggregate_uuid', $this->position->id)->count())->toBe(1);
});

it('reopens the same placement when a separation is undone on the day it was recorded', function (): void {
    $this->actingAs($this->hr);
    changeEmploymentStatus($this, $this->employee, 'terminated')->assertSessionHasNoErrors();
    changeEmploymentStatus($this, $this->employee->fresh(), 'active')->assertSessionHasNoErrors();

    $assignment = $this->assignment->fresh();
    expect($assignment->assignment_status)->toBe(AssignmentStatus::Active)
        ->and($assignment->effective_to)->toBeNull()
        ->and(EmployeeAssignment::query()->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and($this->employee->fresh()->status)->toBe(EmployeeStatus::Active);
});

it('starts a new placement in the last position when employment resumes later, keeping the ended one', function (): void {
    $this->actingAs($this->hr);
    changeEmploymentStatus($this, $this->employee, 'retired')->assertSessionHasNoErrors();

    $this->travelTo(Carbon::parse('2026-11-02 09:00'));
    signInAgain($this);
    changeEmploymentStatus($this, $this->employee->fresh(), 'active')->assertSessionHasNoErrors();

    $old = $this->assignment->fresh();
    $new = EmployeeAssignment::query()->where('employee_id', $this->employee->id)->where('is_current', true)->sole();
    expect($old->is_current)->toBeFalse()
        ->and($old->assignment_status)->toBe(AssignmentStatus::Closed)
        ->and($old->effective_to->toDateString())->toBe('2026-10-21')
        ->and($new->id)->not->toBe($old->id)
        ->and($new->position_id)->toBe($this->position->id)
        ->and($new->effective_from->toDateString())->toBe('2026-11-02')
        ->and($this->employee->fresh()->current_assignment_id)->toBe($new->id)
        // The ended assignment was closed once; stepping aside later is not a second closure.
        ->and(IntegrationEvent::query()->where('event_type', 'assignment.closed')->where('aggregate_uuid', $old->id)->count())->toBe(1);
});

it('refuses to resume employment when someone else holds the seat', function (): void {
    $this->actingAs($this->hr);
    changeEmploymentStatus($this, $this->employee, 'terminated')->assertSessionHasNoErrors();
    lifecycleEmployee($this->organization, $this->position, 'AL-E2');

    changeEmploymentStatus($this, $this->employee->fresh(), 'active')->assertSessionHasErrors(['status' => __('backbone.assignment_position_full')]);

    // Nothing was saved: the employee is still separated and their placement still ended.
    expect($this->employee->fresh()->status)->toBe(EmployeeStatus::Terminated)
        ->and($this->assignment->fresh()->assignment_status)->toBe(AssignmentStatus::Closed);
});

it('keeps the placement of a suspended employee, and changes nothing between two separation statuses', function (): void {
    $this->actingAs($this->hr);
    changeEmploymentStatus($this, $this->employee, 'suspended')->assertSessionHasNoErrors();
    expect($this->assignment->fresh()->assignment_status)->toBe(AssignmentStatus::Active);

    changeEmploymentStatus($this, $this->employee->fresh(), 'retired')->assertSessionHasNoErrors();
    $closedOn = $this->assignment->fresh()->effective_to->toDateString();
    $this->travelTo(Carbon::parse('2027-01-05 09:00'));
    signInAgain($this);
    changeEmploymentStatus($this, $this->employee->fresh(), 'deceased')->assertSessionHasNoErrors();

    expect($this->assignment->fresh()->effective_to->toDateString())->toBe($closedOn)
        ->and(EmploymentStatusHistory::query()->where('employee_id', $this->employee->id)->count())->toBe(4);
});

it('reports separations recorded before this rule whose placement is still open', function (): void {
    // A status written straight to the table, as older separations were, leaves the seat taken.
    Employee::query()->whereKey($this->employee->id)->update(['status' => EmployeeStatus::Retired->value]);

    $this->artisan('backbone:audit-integrity')->expectsOutputToContain('Separated employees whose placement is still open')->assertSuccessful();
    $this->artisan('backbone:audit-integrity', ['--strict' => true])->assertFailed();
});
