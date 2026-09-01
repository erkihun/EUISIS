<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionMovement;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    config(['app.locale' => 'en']);
    app()->setLocale('en');

    foreach (['positions.viewAny', 'positions.view', 'positions.move'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $organizationType = OrganizationType::query()->create([
        'code' => 'POSITION-MOVE',
        'name_en' => 'Position Move Organization Type',
    ]);

    $this->organization = Organization::query()->create([
        'organization_type_id' => $organizationType->id,
        'code' => 'MOVE-ORG',
        'name_en' => 'Move Organization',
        'status' => 'active',
    ]);

    $this->otherOrganization = Organization::query()->create([
        'organization_type_id' => $organizationType->id,
        'code' => 'OTHER-ORG',
        'name_en' => 'Other Organization',
        'status' => 'active',
    ]);

    $this->currentUnit = createMovementUnit($this->organization, 'MOVE-U1', 'Current Unit');
    $this->targetUnit = createMovementUnit($this->organization, 'MOVE-U2', 'Target Unit');
    $this->inactiveUnit = createMovementUnit($this->organization, 'MOVE-U3', 'Inactive Unit', 'inactive');
    $this->otherOrganizationUnit = createMovementUnit($this->otherOrganization, 'OTHER-U1', 'Other Unit');

    $this->position = Position::query()->create([
        'organization_id' => $this->organization->id,
        'organization_unit_id' => $this->currentUnit->id,
        'job_position_code' => 'MOVE-POS-001',
        'title_en' => 'Movable Position',
        'is_active' => true,
    ]);
});

function createMovementUnit(
    Organization $organization,
    string $code,
    string $name,
    string $status = 'active',
): OrganizationUnit {
    return OrganizationUnit::query()->create([
        'organization_id' => $organization->id,
        'code' => $code,
        'name_en' => $name,
        'unit_type' => 'department',
        'status' => $status,
    ]);
}

function movementUser(array $permissions, ?Organization $scope = null): User
{
    $user = User::factory()->create();

    if ($scope === null) {
        $user->givePermissionTo($permissions);
    } else {
        $role = Role::findOrCreate('Organizational Admin', 'web');
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $user->organizationScopes()->create([
            'organization_id' => $scope->id,
            'scope_type' => 'self',
            'is_active' => true,
        ]);
    }

    return $user->fresh();
}

it('shows the Move action to an authorized user', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view', 'positions.move']);

    $this->actingAs($user)
        ->get(route('positions.index', ['organization_id' => $this->organization->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('positions.0.id', $this->position->id)
            ->where('positions.0.can.move', true));
});

it('hides the Move action from an unauthorized user', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view']);

    $this->actingAs($user)
        ->get(route('positions.index', ['organization_id' => $this->organization->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('positions.0.id', $this->position->id)
            ->where('positions.0.can.move', false));
});

it('lists only active target units in the same organization', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view', 'positions.move']);

    $this->actingAs($user)
        ->get(route('positions.move', $this->position))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $targetIds = collect($page->toArray()['props']['targetOrganizationUnits'])->pluck('id');

            expect($targetIds)->toContain($this->targetUnit->id)
                ->and($targetIds)->not->toContain($this->currentUnit->id)
                ->and($targetIds)->not->toContain($this->inactiveUnit->id)
                ->and($targetIds)->not->toContain($this->otherOrganizationUnit->id);
        });
});

it('moves a vacant position inside the same organization without changing its code', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view', 'positions.move']);

    $this->actingAs($user)
        ->post(route('positions.move.store', $this->position), [
            'target_organization_unit_id' => $this->targetUnit->id,
            'reason' => 'The team structure changed.',
        ])
        ->assertRedirect(route('positions.show', $this->position));

    $this->position->refresh();

    expect($this->position->organization_unit_id)->toBe($this->targetUnit->id)
        ->and($this->position->organization_id)->toBe($this->organization->id)
        ->and($this->position->job_position_code)->toBe('MOVE-POS-001');
});

it('rejects moving a position to another organization', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view', 'positions.move']);

    $this->actingAs($user)
        ->post(route('positions.move.store', $this->position), [
            'target_organization_unit_id' => $this->otherOrganizationUnit->id,
            'reason' => 'Invalid cross-organization move.',
        ])
        ->assertSessionHasErrors('target_organization_unit_id');

    expect($this->position->fresh()->organization_unit_id)->toBe($this->currentUnit->id);
});

it('prevents an organizational admin from moving a position outside scope', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view', 'positions.move'], $this->organization);
    $outsidePosition = Position::query()->create([
        'organization_id' => $this->otherOrganization->id,
        'organization_unit_id' => $this->otherOrganizationUnit->id,
        'job_position_code' => 'OTHER-POS-001',
        'title_en' => 'Outside Position',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('positions.move', $outsidePosition))
        ->assertForbidden();
});

it('rejects the current unit as the target', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view', 'positions.move']);

    $this->actingAs($user)
        ->post(route('positions.move.store', $this->position), [
            'target_organization_unit_id' => $this->currentUnit->id,
            'reason' => 'No actual movement.',
        ])
        ->assertSessionHasErrors('target_organization_unit_id');
});

it('rejects an inactive target unit', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view', 'positions.move']);

    $this->actingAs($user)
        ->post(route('positions.move.store', $this->position), [
            'target_organization_unit_id' => $this->inactiveUnit->id,
            'reason' => 'Inactive destination.',
        ])
        ->assertSessionHasErrors('target_organization_unit_id');
});

it('saves movement history and exposes it on position details', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view', 'positions.move']);

    $this->actingAs($user)->post(route('positions.move.store', $this->position), [
        'target_organization_unit_id' => $this->targetUnit->id,
        'reason' => 'Document the reorganization.',
    ]);

    $movement = PositionMovement::query()->sole();

    expect($movement->position_id)->toBe($this->position->id)
        ->and($movement->organization_id)->toBe($this->organization->id)
        ->and($movement->from_organization_unit_id)->toBe($this->currentUnit->id)
        ->and($movement->to_organization_unit_id)->toBe($this->targetUnit->id)
        ->and($movement->moved_by)->toBe($user->id)
        ->and($movement->reason)->toBe('Document the reorganization.');

    $this->actingAs($user)
        ->get(route('positions.show', $this->position))
        ->assertInertia(fn (Assert $page) => $page
            ->where('movementHistory.0.id', $movement->id)
            ->where('movementHistory.0.reason', 'Document the reorganization.'));
});

it('writes a position moved audit log', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view', 'positions.move']);

    $this->actingAs($user)->post(route('positions.move.store', $this->position), [
        'target_organization_unit_id' => $this->targetUnit->id,
        'reason' => 'Audit this move.',
    ]);

    $audit = AuditLog::query()
        ->where('event_type', AuditEventType::PositionMoved->value)
        ->where('auditable_id', $this->position->id)
        ->sole();

    expect($audit->actor_user_id)->toBe($user->id)
        ->and($audit->organization_id)->toBe($this->organization->id)
        ->and($audit->old_values['organization_unit_id'])->toBe($this->currentUnit->id)
        ->and($audit->new_values['organization_unit_id'])->toBe($this->targetUnit->id)
        ->and($audit->reason)->toBe('Audit this move.');
});

it('blocks moving an occupied position with a clear message', function (): void {
    $user = movementUser(['positions.viewAny', 'positions.view', 'positions.move']);
    $employee = Employee::query()->create([
        'employee_number' => 'MOVE-EMP-001',
        'first_name' => 'Occupied',
        'last_name' => 'Employee',
        'full_name' => 'Occupied Employee',
        'status' => 'active',
    ]);

    EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $this->organization->id,
        'organization_unit_id' => $this->currentUnit->id,
        'position_id' => $this->position->id,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);

    $this->actingAs($user)
        ->post(route('positions.move.store', $this->position), [
            'target_organization_unit_id' => $this->targetUnit->id,
            'reason' => 'Attempt to move an occupied position.',
        ])
        ->assertSessionHasErrors([
            'target_organization_unit_id' => 'This position is occupied and cannot be moved.',
        ]);

    expect($this->position->fresh()->organization_unit_id)->toBe($this->currentUnit->id)
        ->and(PositionMovement::query()->count())->toBe(0);
});
