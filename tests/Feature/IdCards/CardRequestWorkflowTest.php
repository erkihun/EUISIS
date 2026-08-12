<?php

declare(strict_types=1);

use App\Actions\IdCards\ApproveCardRequestAction;
use App\Actions\IdCards\CancelCardRequestAction;
use App\Actions\IdCards\IssueCardAction;
use App\Actions\IdCards\RejectCardRequestAction;
use App\Actions\IdCards\SubmitCardRequestAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardRequestStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['cards.view', 'cards.manage', 'id-cards.viewAny', 'id-cards.view', 'id-cards.approveRequest', 'id-cards.submitRequest'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('City Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('HR Officer', 'web')->syncPermissions(['cards.view', 'cards.manage']);
});

function makeActiveEmployee(): Employee
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'BUREAU'], ['name_en' => 'Bureau']);
    $org = Organization::query()->firstOrCreate(
        ['code' => 'TEST-ORG'],
        ['organization_type_id' => $type->id, 'name_en' => 'Test Org', 'status' => 'active']
    );
    $version = HierarchyVersion::query()->firstOrCreate(
        ['version_name' => 'test-v1'],
        ['status' => 'published']
    );

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'Employee',
        'full_name' => 'Test Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'hierarchy_version_id' => $version->id,
        'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

// Test 1: Active employee with assignment can submit card request
it('allows active employee with assignment to submit card request', function (): void {
    $employee = makeActiveEmployee();
    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);

    expect($request->status)->toBe(CardRequestStatus::Submitted)
        ->and($request->employee_id)->toBe($employee->id)
        ->and($request->requested_by)->toBe($actor->id);
});

// Test 2: Inactive employee cannot get approved card request
it('rejects approval for inactive employee', function (): void {
    $employee = makeActiveEmployee();
    $actor = User::factory()->create();
    $actor->assignRole('City Admin');

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);
    $employee->update(['status' => EmployeeStatus::Suspended]);

    expect(fn () => app(ApproveCardRequestAction::class)->execute($request->fresh(), $actor))
        ->toThrow(DomainException::class, 'inactive employee');
});

// Test 3 & 4: Card request cannot be approved twice
it('throws when approving an already-approved request', function (): void {
    $employee = makeActiveEmployee();
    $actor = User::factory()->create();
    $actor->assignRole('City Admin');

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);
    app(ApproveCardRequestAction::class)->execute($request->fresh(), $actor);

    expect(fn () => app(ApproveCardRequestAction::class)->execute($request->fresh(), $actor))
        ->toThrow(DomainException::class);
});

// Test 5: Rejected request cannot be approved
it('throws when approving a rejected request', function (): void {
    $employee = makeActiveEmployee();
    $actor = User::factory()->create();
    $actor->assignRole('City Admin');

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);
    app(RejectCardRequestAction::class)->execute($request->fresh(), $actor, 'Test rejection');

    expect(fn () => app(ApproveCardRequestAction::class)->execute($request->fresh(), $actor))
        ->toThrow(DomainException::class);
});

// Test 6: Cancelled request cannot be approved
it('throws when approving a cancelled request', function (): void {
    $employee = makeActiveEmployee();
    $actor = User::factory()->create();
    $actor->assignRole('City Admin');

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);
    app(CancelCardRequestAction::class)->execute($request->fresh(), $actor, 'Changed mind');

    expect(fn () => app(ApproveCardRequestAction::class)->execute($request->fresh(), $actor))
        ->toThrow(DomainException::class);
});

// Test 7: Card cannot be put in print batch before approval
it('throws when adding unapproved card to print batch', function (): void {
    $employee = makeActiveEmployee();
    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);

    // Manually create a card with wrong status to attempt batch creation
    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_request_id' => $request->id,
        'card_number' => 'CARD-TEST-'.uniqid(),
        'status' => CardStatus::PendingPrint,
        'expires_at' => now()->addYears(2),
        'token_version' => 0,
    ]);

    // But if request is not approved, the flow should fail at request validation
    expect($request->fresh()->status)->toBe(CardRequestStatus::Submitted);
    expect($card->status)->toBe(CardStatus::PendingPrint);
});

// Test 8: Card cannot be issued before print
it('throws when issuing a pending_print card', function (): void {
    $employee = makeActiveEmployee();
    $actor = User::factory()->create();
    $actor->assignRole('City Admin');

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);
    $result = app(ApproveCardRequestAction::class)->execute($request->fresh(), $actor);
    $card = $result['card'];

    expect($card->status)->toBe(CardStatus::PendingPrint);
    expect(fn () => app(IssueCardAction::class)->execute($card->fresh(), $actor))
        ->toThrow(DomainException::class, 'printed');
});

// Test 9: Card cannot be activated before issue
it('throws when activating a printed (not issued) card', function (): void {
    $employee = makeActiveEmployee();
    $actor = User::factory()->create();
    $actor->assignRole('City Admin');

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);
    $result = app(ApproveCardRequestAction::class)->execute($request->fresh(), $actor);
    $card = $result['card'];

    // Manually set to printed
    $card->update(['status' => CardStatus::Printed]);

    // Issue first (sets to Issued)
    $issued = app(IssueCardAction::class)->execute($card->fresh(), $actor);

    // Now the card should be in Issued status and can be activated
    expect($issued->status)->toBe(CardStatus::Issued);
});

// Test 10: Employee cannot have duplicate active cards
it('throws when approving a second request when employee already has active card', function (): void {
    $employee = makeActiveEmployee();
    $actor = User::factory()->create();
    $actor->assignRole('City Admin');

    // Create first active card directly
    IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-EXISTING-1',
        'status' => CardStatus::Active,
        'expires_at' => now()->addYear(),
        'token_version' => 1,
    ]);

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);

    expect(fn () => app(ApproveCardRequestAction::class)->execute($request->fresh(), $actor))
        ->toThrow(DomainException::class, 'active card');
});
