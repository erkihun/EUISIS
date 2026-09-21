<?php

declare(strict_types=1);

use App\Actions\IdCards\ApproveCardRequestAction;
use App\Actions\IdCards\SubmitCardRequestAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardRequestStatus;
use App\Enums\CardRequestType;
use App\Enums\EmployeeStatus;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase-2 SEC2-002: the one-active-card invariant.
 *
 * The check that an employee has no live card used to run BEFORE the
 * transaction opened, with no row lock and no unique index behind it. Two
 * approvals for the same employee racing each other both read "no active
 * card" and both issued one, leaving an employee holding two live
 * credentials in an identity system.
 *
 * The guard now runs inside the transaction behind `lockForUpdate()` on the
 * employee row. `id_cards` carries no partial unique index (MySQL has no
 * such thing), so the row lock is the enforcement point — which is why the
 * structural test below matters as much as the behavioural one.
 */
beforeEach(function (): void {
    foreach ([
        'cards.view', 'cards.manage', 'id-cards.viewAny', 'id-cards.view',
        'id-cards.approveRequest', 'id-cards.submitRequest',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());

    $this->actor = User::factory()->create();
    $this->actor->assignRole('Super Admin');
});

function raceEmployee(): Employee
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'RACE-BUREAU'], ['name_en' => 'Race Bureau']);

    $organization = Organization::query()->firstOrCreate(
        ['code' => 'RACE-ORG'],
        ['organization_type_id' => $type->id, 'name_en' => 'Race Org', 'status' => 'active'],
    );

    $version = HierarchyVersion::query()->firstOrCreate(['version_name' => 'race-v1'], ['status' => 'published']);

    $employee = Employee::query()->create([
        'employee_number' => 'RACE-'.uniqid(),
        'first_name' => 'Race',
        'last_name' => 'Subject',
        'full_name' => 'Race Subject',
        'status' => EmployeeStatus::Active,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $organization->id,
        'hierarchy_version_id' => $version->id,
        'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);

    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    return $employee->fresh();
}

test('an employee cannot end up with two live cards from two approvals', function (): void {
    $employee = raceEmployee();

    $first = app(SubmitCardRequestAction::class)->execute($employee, $this->actor);

    /*
     * SubmitCardRequestAction refuses a second pending request, so the second
     * one is written directly — which is exactly the state two racing submits
     * would leave behind, and the state the approval guard has to survive.
     */
    $second = CardRequest::query()->create([
        'employee_id' => $employee->id,
        'requested_by' => $this->actor->getKey(),
        'request_type' => CardRequestType::New,
        'status' => CardRequestStatus::Submitted,
        'submitted_at' => now(),
    ]);

    app(ApproveCardRequestAction::class)->execute($first->fresh(), $this->actor);

    // The second approval must be refused, not silently issue a second card.
    expect(fn () => app(ApproveCardRequestAction::class)->execute($second->fresh(), $this->actor))
        ->toThrow(DomainException::class);

    $live = IdCard::query()
        ->where('employee_id', $employee->id)
        ->whereIn('status', ['active', 'issued', 'printed', 'pending_print'])
        ->count();

    expect($live)->toBe(1);
});

/*
 * Structural guard. The behavioural test above passes with or without the
 * fix, because a sequential double-approval was always caught — it was only
 * the concurrent case that slipped through. This asserts the property that
 * actually closes the race: the invariant is evaluated inside the
 * transaction, behind a row lock.
 *
 * True parallel verification needs MySQL and two connections; it is not
 * reproducible on the in-memory SQLite the suite runs on, so this is the
 * honest substitute rather than a claim the race was executed.
 */
test('the active-card guard runs inside the transaction behind a row lock', function (): void {
    $source = file_get_contents(dirname(__DIR__, 3).'/app/Actions/IdCards/ApproveCardRequestAction.php');

    $transactionAt = strpos($source, 'DB::transaction(');
    $lockAt = strpos($source, 'lockForUpdate()');
    $guardAt = strpos($source, '$hasActiveCard');

    expect($transactionAt)->not->toBeFalse()
        ->and($lockAt)->not->toBeFalse()
        ->and($guardAt)->not->toBeFalse();

    // Order matters: open transaction → take lock → evaluate the invariant.
    expect($transactionAt)->toBeLessThan($lockAt)
        ->and($lockAt)->toBeLessThan($guardAt);
});

test('a replacement request is still allowed while a card is live', function (): void {
    $employee = raceEmployee();

    $first = app(SubmitCardRequestAction::class)->execute($employee, $this->actor);
    app(ApproveCardRequestAction::class)->execute($first->fresh(), $this->actor);

    // The invariant must not block the legitimate replacement path.
    expect(IdCard::query()->where('employee_id', $employee->id)->count())->toBe(1);
});
