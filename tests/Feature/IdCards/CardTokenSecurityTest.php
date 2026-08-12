<?php

declare(strict_types=1);

use App\Actions\Entitlements\GrantEntitlementAction;
use App\Actions\IdCards\GenerateCardTokenAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationStatus;
use App\Models\CardVerification;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Verification\VerifyCardForServiceAction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['cards.manage', 'entitlements.view'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
    Role::findOrCreate('HR Officer', 'web')->syncPermissions(Permission::all());
});

function makeActiveCard(): array
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'SEC-BUREAU'], ['name_en' => 'Security Bureau']);
    $organization = Organization::query()->firstOrCreate(
        ['code' => 'SEC-ORG'],
        ['organization_type_id' => $type->id, 'name_en' => 'Security Org', 'status' => OrganizationStatus::Active]
    );

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-SEC-'.uniqid(),
        'first_name' => 'John',
        'last_name' => 'Security',
        'full_name' => 'John Security',
        'phone' => '0911000001',
        'email' => 'john.security@test.local',
        'date_of_birth' => '1990-01-15',
        'status' => EmployeeStatus::Active,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $organization->id,
        'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-SEC-'.uniqid(),
        'status' => CardStatus::Active,
        'expires_at' => now()->addYear(),
        'token_version' => 0,
        'is_current' => true,
    ]);

    return [$employee, $card];
}

// Test 17: QR payload excludes PII
it('QR token does not contain employee PII', function (): void {
    [$employee, $card] = makeActiveCard();
    $token = app(GenerateCardTokenAction::class)->execute($card);

    expect($token)->not->toContain($employee->full_name)
        ->and($token)->not->toContain($employee->phone ?? '')
        ->and($token)->not->toContain($employee->email ?? '')
        ->and($token)->not->toContain($employee->employee_number);
});

// Test 18: Raw QR token is not stored in database
it('raw token is never stored in database - only sha256 hash', function (): void {
    [$employee, $card] = makeActiveCard();
    $rawToken = app(GenerateCardTokenAction::class)->execute($card);

    $card->refresh();
    expect($card->token_hash)->not->toBeNull()
        ->and($card->token_hash)->not->toBe($rawToken)
        ->and($card->token_hash)->toBe(hash('sha256', $rawToken));
});

// Test 19: Verification returns minimal safe response
it('card verification response excludes PII', function (): void {
    [$employee, $card] = makeActiveCard();

    $serviceType = ServiceType::query()->firstOrCreate(
        ['code' => 'transport-sec'],
        ['name_en' => 'Transport Sec']
    );
    $provider = ServiceProvider::query()->firstOrCreate(
        ['code' => 'SP-SEC-TEST'],
        ['service_type_id' => $serviceType->id, 'name' => 'Sec Provider', 'status' => 'active']
    );

    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');
    app(GrantEntitlementAction::class)->execute($employee, $serviceType, $provider, $actor, 10);

    $rawToken = app(GenerateCardTokenAction::class)->execute($card);
    $token = $card->id.'|'.$rawToken;

    $result = app(VerifyCardForServiceAction::class)->execute($token, $serviceType, $provider);

    expect($result)->toHaveKey('allowed')
        ->and($result)->not->toHaveKey('full_name')
        ->and($result)->not->toHaveKey('phone')
        ->and($result)->not->toHaveKey('email')
        ->and($result)->not->toHaveKey('date_of_birth')
        ->and($result['allowed'])->toBeTrue();
});

// Test 20: Verification denial is audited
it('denied verification creates audit log', function (): void {
    $serviceType = ServiceType::query()->firstOrCreate(
        ['code' => 'transport-audit'],
        ['name_en' => 'Transport Audit']
    );

    $result = app(VerifyCardForServiceAction::class)->execute(
        'invalid-uuid|invalid-token',
        $serviceType,
        null,
    );

    expect($result['allowed'])->toBeFalse()
        ->and($result['result_code'])->toBe('invalid_token');

    expect(CardVerification::query()
        ->where('result_code', 'invalid_token')
        ->exists()
    )->toBeTrue();
});
