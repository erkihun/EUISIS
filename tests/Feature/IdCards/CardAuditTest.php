<?php

declare(strict_types=1);

use App\Actions\IdCards\ActivateCardAction;
use App\Actions\IdCards\ApproveCardRequestAction;
use App\Actions\IdCards\IssueCardAction;
use App\Actions\IdCards\RejectCardRequestAction;
use App\Actions\IdCards\ReportLostOrDamagedCardAction;
use App\Actions\IdCards\RevokeCardAction;
use App\Actions\IdCards\SubmitCardRequestAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Models\AuditLog;
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
    foreach (['cards.manage', 'id-cards.viewAny', 'id-cards.approveRequest'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('City Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('HR Officer', 'web')->syncPermissions(Permission::all());
});

function auditEmployee(): array
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'AUDIT-BUREAU'], ['name_en' => 'Audit Bureau']);
    $org = Organization::query()->firstOrCreate(
        ['code' => 'AUDIT-ORG'],
        ['organization_type_id' => $type->id, 'name_en' => 'Audit Org', 'status' => OrganizationStatus::Active]
    );
    $version = HierarchyVersion::query()->firstOrCreate(
        ['version_name' => 'audit-v1'],
        ['status' => HierarchyVersionStatus::Published]
    );

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-AUD-'.uniqid(),
        'full_name' => 'Audit Employee',
        'first_name' => 'Audit',
        'last_name' => 'Employee',
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

    return [$employee->fresh()];
}

// Test 28: Submitting a card request creates an audit log
it('card request submission is audited', function (): void {
    [$employee] = auditEmployee();
    $actor = User::factory()->create()->assignRole('HR Officer');

    $before = AuditLog::query()->count();
    app(SubmitCardRequestAction::class)->execute($employee, $actor);

    expect(AuditLog::query()->where('event_type', 'card_requested')->count())->toBeGreaterThan(0);
    expect(AuditLog::query()->count())->toBeGreaterThan($before);
});

// Test 29: Approving a card request creates an audit log
it('card request approval is audited', function (): void {
    [$employee] = auditEmployee();
    $actor = User::factory()->create()->assignRole('City Admin');

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);
    app(ApproveCardRequestAction::class)->execute($request->fresh(), $actor);

    expect(AuditLog::query()->where('event_type', 'card_approved')->exists())->toBeTrue();
});

// Test 30: Rejecting a card request creates an audit log
it('card request rejection is audited', function (): void {
    [$employee] = auditEmployee();
    $actor = User::factory()->create()->assignRole('City Admin');

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);
    app(RejectCardRequestAction::class)->execute($request->fresh(), $actor, 'Test rejection reason');

    expect(AuditLog::query()->where('event_type', 'card_rejected')->exists())->toBeTrue();
});

// Test 31: Issuing a card creates an audit log
it('card issuance is audited', function (): void {
    $actor = User::factory()->create()->assignRole('HR Officer');
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-ISS-'.uniqid(),
        'full_name' => 'Issue Employee',
        'first_name' => 'Issue',
        'last_name' => 'Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-ISS-'.uniqid(),
        'status' => CardStatus::Printed,
        'expires_at' => now()->addYear(),
        'token_version' => 1,
    ]);

    app(IssueCardAction::class)->execute($card, $actor, 'Test Recipient');

    expect(AuditLog::query()->where('event_type', 'card_issued')->exists())->toBeTrue();
});

// Test 32: Activating a card creates an audit log
it('card activation is audited', function (): void {
    $actor = User::factory()->create()->assignRole('HR Officer');
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-ACT-'.uniqid(),
        'full_name' => 'Activate Employee',
        'first_name' => 'Activate',
        'last_name' => 'Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-ACT-'.uniqid(),
        'status' => CardStatus::Issued,
        'expires_at' => now()->addYear(),
        'token_version' => 1,
    ]);

    app(ActivateCardAction::class)->execute($card, $actor);

    expect(AuditLog::query()->where('event_type', 'card_activated')->exists())->toBeTrue();
});

// Test 33: Reporting a lost card creates an audit log
it('reporting lost card is audited', function (): void {
    $actor = User::factory()->create()->assignRole('HR Officer');
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-LOST-'.uniqid(),
        'full_name' => 'Lost Employee',
        'first_name' => 'Lost',
        'last_name' => 'Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-LOST-'.uniqid(),
        'status' => CardStatus::Active,
        'expires_at' => now()->addYear(),
        'token_version' => 1,
    ]);

    app(ReportLostOrDamagedCardAction::class)->execute($card, 'lost', $actor, 'Card was lost');

    expect(AuditLog::query()->where('event_type', 'card_lost')->exists())->toBeTrue();
});

// Test 34: Revoking a card creates an audit log
it('card revocation is audited', function (): void {
    $actor = User::factory()->create()->assignRole('HR Officer');
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-REV-'.uniqid(),
        'full_name' => 'Revoke Employee',
        'first_name' => 'Revoke',
        'last_name' => 'Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-REV-'.uniqid(),
        'status' => CardStatus::Active,
        'expires_at' => now()->addYear(),
        'token_version' => 1,
    ]);

    app(RevokeCardAction::class)->execute($card, $actor, 'Security breach detected');

    expect(AuditLog::query()->where('event_type', 'card_revoked')->exists())->toBeTrue();
});
