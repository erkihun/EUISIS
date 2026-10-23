<?php

declare(strict_types=1);

use App\Actions\ServiceTransactions\RecordServiceTransactionAction;
use App\Backbone\Adapters\CardBasedServiceEligibility;
use App\Backbone\Contracts\ServiceEligibility;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EntitlementStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Entitlement;
use App\Models\IdCard;
use App\Models\ServiceProvider;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\EmployeeServiceEligibilityService;
use Illuminate\Database\UniqueConstraintViolationException;

function serviceEmployee(EmployeeStatus $status = EmployeeStatus::Active): Employee
{
    return Employee::query()->create([
        'employee_number' => 'ELIG-'.uniqid(),
        'first_name' => 'Service',
        'last_name' => 'Employee',
        'full_name' => 'Service Employee',
        'status' => $status,
    ]);
}

function serviceCard(
    Employee $employee,
    CardStatus $status = CardStatus::Active,
    mixed $expiresAt = null,
): IdCard {
    return IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'ELIG-CARD-'.uniqid(),
        'status' => $status,
        'expires_at' => $expiresAt ?? now()->addYear(),
        'activated_at' => $status === CardStatus::Active ? now() : null,
        'is_current' => true,
        'qr_status' => 'active',
        'public_card_uuid' => fake()->uuid(),
    ]);
}

it('allows an employee with one active valid ID card to receive service', function (): void {
    $employee = serviceEmployee();
    $card = serviceCard($employee);

    $result = app(EmployeeServiceEligibilityService::class)->check($employee, $card, 'cafeteria');

    expect($result['eligible'])->toBeTrue()
        ->and($result['reason_code'])->toBeNull();
});

it('blocks an employee with no ID card', function (): void {
    $employee = serviceEmployee();

    $result = app(EmployeeServiceEligibilityService::class)->check($employee, null, 'cafeteria');

    expect($result['eligible'])->toBeFalse()
        ->and($result['reason_code'])->toBe('no_active_id_card');
});

it('blocks every non-serviceable ID card status', function (CardStatus $status, string $reason): void {
    $employee = serviceEmployee();
    $card = serviceCard($employee, $status);

    $result = app(EmployeeServiceEligibilityService::class)->check($employee, $card, 'cafeteria');

    expect($result['eligible'])->toBeFalse()
        ->and($result['reason_code'])->toBe($reason);
})->with([
    'pending' => [CardStatus::PendingPrint, 'id_card_pending'],
    'printed but not active' => [CardStatus::Printed, 'id_card_pending'],
    'issued but not active' => [CardStatus::Issued, 'id_card_pending'],
    'revoked' => [CardStatus::Revoked, 'id_card_revoked'],
    'lost' => [CardStatus::Lost, 'id_card_lost'],
    'replaced' => [CardStatus::Replaced, 'id_card_replaced'],
    'suspended' => [CardStatus::Suspended, 'id_card_suspended'],
    'damaged' => [CardStatus::Damaged, 'id_card_not_active'],
]);

it('blocks an expired active ID card', function (): void {
    $employee = serviceEmployee();
    $card = serviceCard($employee, CardStatus::Active, now()->subMinute());

    $result = app(EmployeeServiceEligibilityService::class)->check($employee, $card, 'cafeteria');

    expect($result['eligible'])->toBeFalse()
        ->and($result['reason_code'])->toBe('id_card_expired');
});

it('blocks an inactive employee even when the card is active', function (): void {
    $employee = serviceEmployee(EmployeeStatus::Suspended);
    $card = serviceCard($employee);

    $result = app(EmployeeServiceEligibilityService::class)->check($employee, $card, 'cafeteria');

    expect($result['eligible'])->toBeFalse()
        ->and($result['reason_code'])->toBe('employee_inactive');
});

it('does not create a service transaction or deduct quota for a blocked card', function (): void {
    $employee = serviceEmployee();
    $card = serviceCard($employee, CardStatus::Lost);
    $serviceType = ServiceType::query()->create(['code' => 'eligibility-test', 'name_en' => 'Eligibility Test']);
    $provider = ServiceProvider::query()->create([
        'service_type_id' => $serviceType->id,
        'name' => 'Eligibility Provider',
        'code' => 'ELIG-SP',
        'status' => 'active',
    ]);
    $entitlement = Entitlement::query()->create([
        'employee_id' => $employee->id,
        'service_type_id' => $serviceType->id,
        'service_provider_id' => $provider->id,
        'status' => EntitlementStatus::Active,
        'quota_limit' => 10,
        'quota_used' => 0,
    ]);
    $actor = User::factory()->create();

    expect(fn () => app(RecordServiceTransactionAction::class)->execute(
        $employee,
        $card,
        $serviceType,
        $provider,
        $entitlement,
        'authorized',
        $actor,
    ))->toThrow(DomainException::class, 'id_card_lost');

    expect(ServiceTransaction::query()->count())->toBe(0)
        ->and($entitlement->fresh()->quota_used)->toBe(0);
});

it('audits every blocked service attempt with required context', function (): void {
    $employee = serviceEmployee();
    $card = serviceCard($employee, CardStatus::Revoked);
    $actor = User::factory()->create();

    app(EmployeeServiceEligibilityService::class)->check(
        $employee,
        $card,
        'cafeteria',
        $actor,
        'provider-123',
    );

    $audit = AuditLog::query()->where('event_type', AuditEventType::ServiceAccessBlocked)->latest('created_at')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->new_values)->toMatchArray([
            'employee_id' => $employee->id,
            'id_card_id' => $card->id,
            'provider_id' => 'provider-123',
            'service_type' => 'cafeteria',
            'reason_code' => 'id_card_revoked',
            'attempted_by' => $actor->id,
        ])
        ->and($audit->new_values['attempted_at'])->not->toBeEmpty();
});

it('uses the shared eligibility service in cafeteria and provider portal scans', function (): void {
    $constructor = new ReflectionMethod(CafeteriaQrScanService::class, '__construct');
    $dependencies = collect($constructor->getParameters())
        ->map(fn (ReflectionParameter $parameter): ?string => $parameter->getType() instanceof ReflectionNamedType
            ? $parameter->getType()->getName()
            : null);

    // The cafeteria depends on the backbone gate contract, whose adapter delegates to the shared service.
    expect($dependencies)->toContain(ServiceEligibility::class)
        ->and(app(ServiceEligibility::class))->toBeInstanceOf(CardBasedServiceEligibility::class)
        ->and((new ReflectionMethod(CardBasedServiceEligibility::class, '__construct'))->getParameters()[0]->getType()?->getName())
        ->toBe(EmployeeServiceEligibilityService::class);
});

function quotaFixture(int $limit): array
{
    $employee = serviceEmployee();
    $card = serviceCard($employee);
    $serviceType = ServiceType::query()->create(['code' => 'quota-'.uniqid(), 'name_en' => 'Quota Test']);
    $provider = ServiceProvider::query()->create([
        'service_type_id' => $serviceType->id, 'name' => 'Quota Provider',
        'code' => 'Q-SP-'.uniqid(), 'status' => 'active',
    ]);
    $entitlement = Entitlement::query()->create([
        'employee_id' => $employee->id, 'service_type_id' => $serviceType->id,
        'service_provider_id' => $provider->id, 'status' => EntitlementStatus::Active,
        'quota_limit' => $limit, 'quota_used' => 0,
    ]);

    return [$employee, $card, $serviceType, $provider, $entitlement];
}

it('never takes quota past its limit, even from a stale entitlement copy', function (): void {
    [$employee, $card, $type, $provider, $entitlement] = quotaFixture(1);
    $stale = Entitlement::query()->findOrFail($entitlement->id);
    $action = app(RecordServiceTransactionAction::class);

    $action->execute($employee, $card, $type, $provider, $entitlement, 'authorized', null, ['reference' => 'R-1']);
    expect(fn () => $action->execute($employee, $card, $type, $provider, $stale, 'authorized', null, ['reference' => 'R-2']))
        ->toThrow(DomainException::class);

    expect($entitlement->fresh()->quota_used)->toBe(1)
        ->and(ServiceTransaction::query()->count())->toBe(1);
});

it('the database refuses a second transaction with the same provider reference', function (): void {
    [$employee, $card, $type, $provider] = quotaFixture(10);
    $row = fn () => ServiceTransaction::query()->create([
        'employee_id' => $employee->id, 'id_card_id' => $card->id, 'service_type_id' => $type->id,
        'service_provider_id' => $provider->id, 'status' => 'authorized', 'occurred_at' => now(), 'reference' => 'DUP-1',
    ]);

    $row();
    expect($row)->toThrow(UniqueConstraintViolationException::class);
});
