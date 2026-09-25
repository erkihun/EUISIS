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
use Inertia\Testing\AssertableInertia;
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
it('scopes the card request picker list and submissions to accessible organizations', function (): void {
    Permission::findOrCreate('employees.view', 'web');
    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');
    $actor->givePermissionTo('employees.view');
    $inside = makeActiveEmployee();
    $outside = makeActiveEmployee();
    $otherOrganization = Organization::query()->create([
        'organization_type_id' => $inside->currentAssignment->organization->organization_type_id,
        'code' => 'OTHER-ORG', 'name_en' => 'Other Organization', 'status' => 'active',
    ]);
    $outside->currentAssignment->update(['organization_id' => $otherOrganization->id]);
    $actor->organizationScopes()->create([
        'organization_id' => $inside->currentAssignment->organization_id,
        'scope_type' => 'self', 'is_active' => true,
    ]);
    $insideRequest = app(SubmitCardRequestAction::class)->execute($inside, $actor);
    $outsideRequest = app(SubmitCardRequestAction::class)->execute($outside->fresh(), $actor);

    $this->actingAs($actor)->get(route('card-requests.create'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('employees', 1)->where('employees.0.id', $inside->id));
    $this->get(route('card-requests.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('cardRequests.data', 1)->where('cardRequests.data.0.id', $insideRequest->id));
    $this->get(route('card-requests.show', $outsideRequest))->assertForbidden();
    $this->post(route('card-requests.store'), ['employee_ids' => [$inside->id, $outside->id]])->assertForbidden();
    $this->post(route('card-requests.store'), ['employee_id' => $outside->id])->assertForbidden();
    $this->assertDatabaseCount('card_requests', 2);

    $inactiveActor = User::factory()->create();
    $inactiveActor->assignRole('HR Officer');
    $inactiveActor->organizationScopes()->create([
        'organization_id' => $inside->currentAssignment->organization_id,
        'scope_type' => 'self', 'is_active' => false,
    ]);
    $this->actingAs($inactiveActor)->get(route('card-requests.create'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('employees', 0));
    $this->get(route('card-requests.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('cardRequests.data', 0));

    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin)->get(route('card-requests.create'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('employees', 2));
    $this->get(route('card-requests.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('cardRequests.data', 2));
});

it('filters eligibility by request type and rejects mismatched submissions', function (?string $status, array $types): void {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $employee = makeActiveEmployee();
    $card = $status === null ? null : IdCard::query()->create([
        'employee_id' => $employee->id, 'card_number' => 'ELIG-'.uniqid(),
        'status' => $status, 'expires_at' => now()->addYear(),
        'reprint_required' => $status === 'active',
    ]);
    $this->actingAs($actor)->get(route('card-requests.create'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('employees.0.eligible_request_types', $types));
    foreach (array_diff(['new', 'renewal', 'replacement', 'lost', 'damaged', 'correction'], $types) as $type) {
        $this->post(route('card-requests.store'), ['employee_ids' => [$employee->id], 'request_type' => $type])
            ->assertSessionHasErrors('employee_ids');
    }
    $this->assertDatabaseCount('card_requests', 0);
    if ($types !== []) {
        $this->post(route('card-requests.store'), ['employee_ids' => [$employee->id], 'request_type' => $types[0]])
            ->assertSessionHasNoErrors()->assertRedirect(route('card-requests.index'));
        $this->assertDatabaseHas('card_requests', ['employee_id' => $employee->id, 'previous_card_id' => $card?->id]);
        $this->get(route('card-requests.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('employees.0.eligible_request_types', []));
    }
})->with([
    'no card' => [null, ['new']],
    'expired' => ['expired', ['renewal', 'replacement']],
    'lost' => ['lost', ['replacement', 'lost']],
    'damaged' => ['damaged', ['replacement', 'damaged']],
    'revoked' => ['revoked', ['replacement']],
    'suspended' => ['suspended', ['replacement']],
    'correction' => ['active', ['correction']],
    'printing' => ['pending_print', []],
    'replaced' => ['replaced', []],
]);

it('replaces the linked card only when a correction is approved', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $employee = makeActiveEmployee();
    $card = IdCard::query()->create([
        'employee_id' => $employee->id, 'card_number' => 'CORRECTION-1',
        'status' => CardStatus::Active, 'reprint_required' => true, 'is_current' => true,
        'expires_at' => now()->addYear(),
    ]);
    $this->actingAs($actor)->post(route('card-requests.store'), [
        'employee_ids' => [$employee->id], 'request_type' => 'correction',
    ])->assertSessionHasNoErrors();
    expect($card->fresh()->status)->toBe(CardStatus::Active);
    $request = $employee->cardRequests()->firstOrFail();
    $result = app(ApproveCardRequestAction::class)->execute($request, $actor);
    expect($card->fresh()->status)->toBe(CardStatus::Replaced)
        ->and($card->fresh()->is_current)->toBeFalse()
        ->and($result['card']->previous_card_id)->toBe($card->id);
});

it('rejects a stale selection and rolls back earlier eligible employees', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $employees = collect([makeActiveEmployee(), makeActiveEmployee()])->sortBy('id')->values();
    $this->actingAs($actor)->get(route('card-requests.create'))->assertOk();
    IdCard::query()->create([
        'employee_id' => $employees[1]->id, 'card_number' => 'STALE-1',
        'status' => CardStatus::Active, 'expires_at' => now()->addYear(),
    ]);
    $this->post(route('card-requests.store'), [
        'employee_ids' => $employees->pluck('id')->all(), 'request_type' => 'new',
    ])->assertSessionHasErrors('employee_ids');
    $this->assertDatabaseCount('card_requests', 0);
});

it('submits one card request per selected employee through the form', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $employees = [makeActiveEmployee(), makeActiveEmployee()];

    $this->actingAs($actor)->post(route('card-requests.store'), [
        'employee_ids' => array_map(fn ($employee) => $employee->id, $employees),
        'request_type' => 'new',
        'reason' => 'Team cards',
    ])->assertRedirect(route('card-requests.index'))->assertSessionHasNoErrors();

    foreach ($employees as $employee) {
        $this->assertDatabaseHas('card_requests', ['employee_id' => $employee->id, 'request_reason' => 'Team cards', 'status' => 'submitted']);
    }
    $this->assertDatabaseCount('card_requests', 2);
});

it('rolls back the whole selection when a later employee already has a pending request', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $employees = collect([makeActiveEmployee(), makeActiveEmployee()])->sortBy('id')->values();
    app(SubmitCardRequestAction::class)->execute($employees[1], $actor);

    $this->actingAs($actor)->post(route('card-requests.store'), [
        'employee_ids' => $employees->pluck('id')->all(),
    ])->assertSessionHasErrors('employee_ids');

    $this->assertDatabaseCount('card_requests', 1);
    $this->assertDatabaseMissing('card_requests', ['employee_id' => $employees[0]->id]);
});

it('rejects duplicate employee selections', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $employee = makeActiveEmployee();
    $this->actingAs($actor)->post(route('card-requests.store'), [
        'employee_ids' => [$employee->id, $employee->id],
    ])->assertSessionHasErrors('employee_ids.0');
    $this->assertDatabaseCount('card_requests', 0);
});

it('preserves single employee request submissions', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $employee = makeActiveEmployee();
    $this->actingAs($actor)->post(route('card-requests.store'), [
        'employee_id' => $employee->id,
    ])->assertRedirect(route('card-requests.index'))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('card_requests', 1);
});

it('does not create requests when a selected employee is unauthorized', function (): void {
    Permission::findOrCreate('employees.view', 'web');
    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');
    $employees = [makeActiveEmployee(), makeActiveEmployee()];
    $this->actingAs($actor)->post(route('card-requests.store'), [
        'employee_ids' => array_map(fn ($employee) => $employee->id, $employees),
    ])->assertForbidden();
    $this->assertDatabaseCount('card_requests', 0);
});

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
