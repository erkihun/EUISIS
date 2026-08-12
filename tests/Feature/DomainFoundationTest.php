<?php

declare(strict_types=1);

use App\Actions\Employees\RegisterEmployeeAction;
use App\Actions\Entitlements\GrantEntitlementAction;
use App\Actions\IdCards\ActivateCardAction;
use App\Actions\IdCards\ApproveCardRequestAction;
use App\Actions\IdCards\CreatePrintBatchAction;
use App\Actions\IdCards\GenerateCardTokenAction;
use App\Actions\IdCards\IssueCardAction;
use App\Actions\IdCards\ReplaceCardAction;
use App\Actions\IdCards\ReportLostOrDamagedCardAction;
use App\Actions\IdCards\SubmitCardRequestAction;
use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Actions\Transfers\CompleteTransferAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Enums\TransferAnnouncementStatus;
use App\Enums\TransferApplicationStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDocument;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationEdge;
use App\Models\OrganizationNameHistory;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Verification\VerifyCardForServiceAction;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'employees.view', 'employees.manage',
        'cards.view', 'cards.manage',
        'entitlements.view',
        'transactions.manage',
        'audit.view', 'reports.view',
        'transfers.viewAny', 'transfers.view', 'transfers.create', 'transfers.update', 'transfers.submit', 'transfers.confirmCurrentOrganization', 'transfers.confirmReceivingOrganization', 'transfers.approve', 'transfers.reject', 'transfers.cancel', 'transfers.complete',
        'positions.viewAny', 'positions.view', 'positions.create', 'positions.update', 'positions.archive', 'positions.restore',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('City Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('HR Officer', 'web')->syncPermissions([
        'employees.view', 'employees.manage', 'cards.view', 'cards.manage', 'entitlements.view',
        'transfers.viewAny', 'transfers.view', 'transfers.create', 'transfers.update', 'transfers.submit', 'transfers.confirmCurrentOrganization', 'transfers.approve',
        'positions.viewAny', 'positions.view', 'positions.create', 'positions.update', 'positions.archive', 'positions.restore',
    ]);
    Role::findOrCreate('Service Provider User', 'web')->syncPermissions(['transactions.manage']);
});

function createHierarchy(): array
{
    $type = OrganizationType::query()->create([
        'code' => 'bureau',
        'name_en' => 'Bureau',
    ]);

    $root = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'ROOT',
        'name_en' => 'Root',
        'status' => OrganizationStatus::Active,
    ]);

    $child = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'CHILD',
        'name_en' => 'Child',
        'status' => OrganizationStatus::Active,
    ]);

    $outside = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'OUTSIDE',
        'name_en' => 'Outside',
        'status' => OrganizationStatus::Active,
    ]);

    $grandchild = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'GRAND',
        'name_en' => 'Grandchild',
        'status' => OrganizationStatus::Active,
    ]);

    $version = HierarchyVersion::query()->create([
        'version_name' => 'v1',
        'status' => HierarchyVersionStatus::Draft,
    ]);

    OrganizationEdge::query()->create([
        'hierarchy_version_id' => $version->id,
        'parent_organization_id' => $root->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
    ]);

    OrganizationEdge::query()->create([
        'hierarchy_version_id' => $version->id,
        'parent_organization_id' => $child->id,
        'child_organization_id' => $grandchild->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo,
    ]);

    return compact('type', 'root', 'child', 'grandchild', 'outside', 'version');
}

it('enforces employee scope outside and inside subtree', function (): void {
    extract(createHierarchy());

    $admin = User::factory()->create();
    $admin->assignRole('HR Officer');

    UserOrganizationScope::query()->create([
        'user_id' => $admin->id,
        'organization_id' => $root->id,
        'scope_type' => OrganizationScopeType::Subtree,
    ]);

    $publisher = User::factory()->create();
    $publisher->assignRole('City Admin');

    app(PublishHierarchyVersionAction::class)->execute($version, $publisher);

    expect(OrganizationClosurePath::query()
        ->where('hierarchy_version_id', $version->id)
        ->where('ancestor_organization_id', $root->id)
        ->where('descendant_organization_id', $grandchild->id)
        ->where('depth', 2)
        ->exists())->toBeTrue();

    $insideEmployee = Employee::query()->create([
        'employee_number' => 'EMP-INSIDE',
        'first_name' => 'Inside',
        'last_name' => 'User',
        'full_name' => 'Inside User',
        'status' => EmployeeStatus::Active,
    ]);
    $insideAssignment = EmployeeAssignment::query()->create([
        'employee_id' => $insideEmployee->id,
        'organization_id' => $child->id,
        'assignment_status' => 'active',
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);
    $insideEmployee->update(['current_assignment_id' => $insideAssignment->id]);

    $outsideEmployee = Employee::query()->create([
        'employee_number' => 'EMP-OUTSIDE',
        'first_name' => 'Outside',
        'last_name' => 'User',
        'full_name' => 'Outside User',
        'status' => EmployeeStatus::Active,
    ]);
    $outsideAssignment = EmployeeAssignment::query()->create([
        'employee_id' => $outsideEmployee->id,
        'organization_id' => $outside->id,
        'assignment_status' => 'active',
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);
    $outsideEmployee->update(['current_assignment_id' => $outsideAssignment->id]);

    expect($admin->can('view', $insideEmployee))->toBeTrue();
    expect($admin->can('view', $outsideEmployee))->toBeFalse();
});

it('registers employee, flags duplicates, and preserves identity across transfer', function (): void {
    extract(createHierarchy());

    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->assignRole('HR Officer');

    app(PublishHierarchyVersionAction::class)->execute($version, User::factory()->create()->assignRole('City Admin'));

    $first = app(RegisterEmployeeAction::class)->execute([
        'employee_number' => 'EMP-100',
        'first_name' => 'Demo',
        'last_name' => 'One',
        'full_name' => 'Demo One',
        'phone' => '0911000001',
        'status' => EmployeeStatus::Active,
    ], [
        'organization_id' => $root->id,
        'hierarchy_version_id' => $version->id,
        'effective_from' => now()->toDateString(),
    ], $actor);

    $second = app(RegisterEmployeeAction::class)->execute([
        'employee_number' => 'EMP-101',
        'first_name' => 'Demo',
        'last_name' => 'One',
        'full_name' => 'Demo One',
        'phone' => '0911000001',
        'status' => EmployeeStatus::Active,
    ], [
        'organization_id' => $root->id,
        'hierarchy_version_id' => $version->id,
        'effective_from' => now()->toDateString(),
    ], $actor);

    expect($second->currentAssignment)->not->toBeNull();
    expect($second->fresh()->id)->toBeString();
    expect($second->fresh()->currentAssignment->organization_id)->toBe($root->id);
    expect($second->fresh()->currentAssignment->assignment_status->value)->toBe('active');
    expect($second->fresh()->employeeDuplicateFlags()->count())->toBeGreaterThan(0);
    expect(OrganizationNameHistory::query()->count())->toBe(0);

    // Use new Transfer Module to verify employee identity is preserved across transfer
    $toPosition = Position::query()->create([
        'organization_id' => $child->id,
        'title_en' => 'Transfer Target Position',
        'job_position_code' => 'TTP-001',
        'is_active' => true,
    ]);

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $child->id,
        'position_id' => $toPosition->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => $actor->id,
    ]);

    $settings = TransferSetting::current();
    $settings->update([
        'releasing_consent_required' => false,
        'receiving_consent_required' => false,
        'final_approval_required' => false,
        'card_reprint_policy' => 'no_reprint',
        'service_recalculation_policy' => 'no_recalculation',
    ]);

    $application = TransferApplication::query()->create([
        'announcement_id' => $announcement->id,
        'employee_id' => $first->id,
        'current_assignment_id' => $first->current_assignment_id,
        'releasing_organization_id' => $root->id,
        'receiving_organization_id' => $child->id,
        'status' => TransferApplicationStatus::Approved,
        'submitted_at' => now(),
    ]);

    $originalId = $first->id;
    app(CompleteTransferAction::class)->execute($application->fresh(), $actor);

    $first->refresh();
    expect($first->id)->toBe($originalId);
    expect($first->currentAssignment->organization_id)->toBe($child->id);
    expect(EmployeeAssignment::query()->where('employee_id', $first->id)->where('assignment_status', AssignmentStatus::Closed->value)->exists())->toBeTrue();
});

it('enforces card approval, print, issue, token privacy, replacement, and verification rules', function (): void {
    extract(createHierarchy());

    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');
    $approver = User::factory()->create();
    $approver->assignRole('City Admin');

    app(PublishHierarchyVersionAction::class)->execute($version, $approver);

    $employee = app(RegisterEmployeeAction::class)->execute([
        'employee_number' => 'EMP-200',
        'first_name' => 'Card',
        'last_name' => 'Holder',
        'full_name' => 'Card Holder',
        'status' => EmployeeStatus::Active,
    ], [
        'organization_id' => $root->id,
        'hierarchy_version_id' => $version->id,
        'effective_from' => now()->toDateString(),
    ], $actor);

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor, 'new');

    expect(fn () => app(CreatePrintBatchAction::class)->execute($request, $actor))
        ->toThrow(DomainException::class);

    app(ApproveCardRequestAction::class)->execute($request, $approver);
    $card = app(CreatePrintBatchAction::class)->execute($request->fresh(), $actor);

    expect($card->token_hash)->not->toContain($employee->full_name);
    expect($card->token_hash)->not->toContain($employee->employee_number);

    $card->update(['status' => CardStatus::PendingPrint]);
    expect(fn () => app(IssueCardAction::class)->execute($card->fresh(), $actor))
        ->toThrow(DomainException::class);

    $card->update(['status' => CardStatus::Printed]);
    $issued = app(IssueCardAction::class)->execute($card->fresh(), $actor, 'Recipient');
    $issued = app(ActivateCardAction::class)->execute($issued->fresh(), $actor);
    $rawToken = app(GenerateCardTokenAction::class)->execute($issued);
    $token = $issued->id.'|'.$rawToken;

    expect($token)->not->toContain($employee->full_name);
    expect($issued->fresh()->token_hash)->not->toBe($token);

    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'transport'], ['name_en' => 'Transport Service']);
    $provider = ServiceProvider::query()->create([
        'service_type_id' => $serviceType->id,
        'name' => 'Transport Demo',
        'code' => 'SP1',
        'status' => 'active',
    ]);
    app(GrantEntitlementAction::class)->execute($employee, $serviceType, $provider, $actor, 10);

    $verified = app(VerifyCardForServiceAction::class)->execute($token, $serviceType, $provider, null);
    expect($verified['allowed'])->toBeTrue();

    $employee->update(['status' => EmployeeStatus::Suspended]);
    $denied = app(VerifyCardForServiceAction::class)->execute($token, $serviceType, $provider, null);
    expect($denied['allowed'])->toBeFalse();
    expect($denied['result_code'])->toBe('employee_inactive');

    $employee->update(['status' => EmployeeStatus::Active]);
    app(ReportLostOrDamagedCardAction::class)->execute($issued->fresh(), 'lost', $actor, 'reported lost');
    $replacement = app(ReplaceCardAction::class)->execute($issued->fresh(), $actor);

    expect($replacement->previous_card_id)->toBe($issued->id);
    expect($issued->fresh()->status)->toBe(CardStatus::Replaced);
    expect(AuditLog::query()->where('event_type', 'card_replaced')->exists())->toBeTrue();
});

it('records service transactions, audits them, and returns minimal provider api responses', function (): void {
    extract(createHierarchy());

    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->assignRole('HR Officer');
    $providerUser = User::factory()->create();
    $providerUser->assignRole('Service Provider User');

    app(PublishHierarchyVersionAction::class)->execute($version, User::factory()->create()->assignRole('City Admin'));

    $employee = app(RegisterEmployeeAction::class)->execute([
        'employee_number' => 'EMP-300',
        'first_name' => 'API',
        'last_name' => 'User',
        'full_name' => 'API User',
        'status' => EmployeeStatus::Active,
    ], [
        'organization_id' => $root->id,
        'hierarchy_version_id' => $version->id,
        'effective_from' => now()->toDateString(),
    ], $actor);

    $request = app(SubmitCardRequestAction::class)->execute($employee, $actor);
    app(ApproveCardRequestAction::class)->execute($request, User::factory()->create()->assignRole('City Admin'));
    $card = app(CreatePrintBatchAction::class)->execute($request->fresh(), $actor);
    $card->update(['status' => CardStatus::Printed]);
    $issued = app(IssueCardAction::class)->execute($card->fresh(), $actor);
    $issued = app(ActivateCardAction::class)->execute($issued->fresh(), $actor);
    $rawToken = app(GenerateCardTokenAction::class)->execute($issued);
    $token = $issued->id.'|'.$rawToken;

    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria Service']);
    $provider = ServiceProvider::query()->create([
        'service_type_id' => $serviceType->id,
        'name' => 'Cafeteria Demo',
        'code' => 'CAF-DEMO',
        'status' => 'active',
    ]);

    app(GrantEntitlementAction::class)->execute($employee, $serviceType, $provider, $actor, 5);

    UserOrganizationScope::query()->create([
        'user_id' => $actor->id,
        'organization_id' => $root->id,
        'scope_type' => OrganizationScopeType::Subtree,
    ]);

    UserOrganizationScope::query()->create([
        'user_id' => $providerUser->id,
        'organization_id' => $root->id,
        'scope_type' => OrganizationScopeType::ServiceProvider,
        'service_provider_id' => $provider->id,
        'service_type_id' => $serviceType->id,
    ]);

    Sanctum::actingAs($providerUser, ['provider:access']);

    $response = $this->postJson(route('api.v1.services.transactions', ['serviceType' => $serviceType->code]), [
        'token' => $token,
        'provider_code' => $provider->code,
        'reference' => 'TX-1',
        'amount' => 12.5,
    ]);

    $response->assertOk()
        ->assertJsonMissing(['full_name', 'employee_number', 'phone', 'email'])
        ->assertJsonPath('data.allowed', true);

    expect(AuditLog::query()->where('event_type', 'service_transaction_recorded')->exists())->toBeTrue();
});

it('writes audit log for sensitive employee update and enforces provider token abilities', function (): void {
    extract(createHierarchy());

    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');
    $providerUser = User::factory()->create();
    $providerUser->assignRole('Service Provider User');

    app(PublishHierarchyVersionAction::class)->execute($version, User::factory()->create()->assignRole('City Admin'));

    $employee = app(RegisterEmployeeAction::class)->execute([
        'employee_number' => 'EMP-400',
        'first_name' => 'Sensitive',
        'last_name' => 'Update',
        'full_name' => 'Sensitive Update',
        'status' => EmployeeStatus::Active,
    ], [
        'organization_id' => $root->id,
        'hierarchy_version_id' => $version->id,
        'effective_from' => now()->toDateString(),
    ], $actor);

    EmployeeDocument::query()->create([
        'employee_id' => $employee->id,
        'document_type' => 'national_id',
        'file_path' => 'employees/demo/document.pdf',
        'storage_disk' => 'private',
        'is_private' => true,
    ]);

    UserOrganizationScope::query()->create([
        'user_id' => $actor->id,
        'organization_id' => $root->id,
        'scope_type' => OrganizationScopeType::Subtree,
    ]);

    $this->actingAs($actor)->patch(route('employees.update', $employee), [
        'first_name' => 'Sensitive',
        'middle_name' => '',
        'last_name' => 'Updated',
        'full_name' => 'Sensitive Updated',
        'phone' => '0911000999',
        'email' => 'sensitive.updated@example.test',
        'date_of_birth' => '',
        'gender' => '',
        'status' => 'active',
    ])->assertRedirect();

    expect(AuditLog::query()->where('event_type', 'employee_updated')->exists())->toBeTrue();

    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'consumer_association'], ['name_en' => 'Consumer Association Service']);
    $provider = ServiceProvider::query()->create([
        'service_type_id' => $serviceType->id,
        'name' => 'Consumer Demo',
        'code' => 'CONS-DEMO',
        'status' => 'active',
    ]);

    UserOrganizationScope::query()->create([
        'user_id' => $providerUser->id,
        'organization_id' => $root->id,
        'scope_type' => OrganizationScopeType::ServiceProvider,
        'service_provider_id' => $provider->id,
        'service_type_id' => $serviceType->id,
    ]);

    Sanctum::actingAs($providerUser, ['transactions:view']);

    $response = $this->postJson(route('api.v1.cards.verify'), [
        'token' => 'bad-token',
        'service_type' => $serviceType->code,
        'provider_code' => $provider->code,
    ]);

    $response->assertForbidden()
        ->assertJson(['message' => 'Forbidden.']);

    expect(AuditLog::query()->where('event_type', 'provider_api_denied')->exists())->toBeTrue();
});
