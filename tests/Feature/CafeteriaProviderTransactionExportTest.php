<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\ProviderUser;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Cafeteria\Domain\Enums\CafeteriaTransactionStatus;
use Modules\Cafeteria\Domain\Enums\CafeteriaUsageMode;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;
use Modules\Cafeteria\Domain\Models\CafeteriaTransaction;

function createExportPortalUserWithProvider(bool $canExport = true): array
{
    $serviceType = ServiceType::query()->firstOrCreate(
        ['code' => 'cafeteria'],
        ['name_en' => 'Cafeteria Service', 'is_active' => true],
    );

    $providerType = ProviderType::query()->firstOrCreate(
        ['code' => 'CAFETERIA'],
        ['name_en' => 'Cafeteria', 'is_active' => true],
    );

    $generalProvider = Provider::query()->create([
        'provider_code' => 'EXP-CAF',
        'provider_type_id' => $providerType->id,
        'name_en' => 'Export Cafeteria',
        'status' => 'active',
    ]);

    $provider = CafeteriaProvider::query()->create([
        'provider_id' => $generalProvider->id,
        'code' => 'EXP-CAF',
        'name_en' => 'Export Cafeteria',
        'is_active' => true,
    ]);

    DB::table('provider_services')->insert([
        'id' => (string) Str::uuid7(),
        'provider_id' => $generalProvider->id,
        'service_type_id' => $serviceType->id,
        'status' => 'active',
        'enabled_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = ProviderUser::query()->create([
        'provider_id' => $generalProvider->id,
        'name' => 'Export Operator',
        'email' => 'export-operator@example.test',
        'username' => 'export-operator',
        'password' => Hash::make('password'),
        'provider_role' => $canExport ? 'operator' : 'viewer',
        'status' => 'active',
        'portal_enabled' => true,
    ]);

    return [$user, $provider];
}

function createExportEmployee(string $employeeNumber, string $fullName, string $phone): Employee
{
    return Employee::query()->create([
        'employee_number' => $employeeNumber,
        'first_name' => Str::before($fullName, ' '),
        'last_name' => Str::after($fullName, ' '),
        'full_name' => $fullName,
        'phone' => $phone,
        'status' => 'active',
    ]);
}

function createExportTransaction(CafeteriaProvider $provider, Employee $employee, User|ProviderUser $operator, array $overrides = []): CafeteriaTransaction
{
    $serviceTransactionId = (string) Str::uuid();
    $serviceTypeId = (string) Str::uuid();
    $serviceProviderId = (string) Str::uuid();

    DB::table('service_types')->insert([
        'id' => $serviceTypeId,
        'code' => 'CAF-'.Str::upper(Str::random(8)),
        'name_en' => 'Cafeteria',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('service_providers')->insert([
        'id' => $serviceProviderId,
        'service_type_id' => $serviceTypeId,
        'name' => 'Export Service Provider',
        'code' => 'SP-'.Str::upper(Str::random(8)),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('service_transactions')->insert([
        'id' => $serviceTransactionId,
        'employee_id' => $employee->id,
        'service_type_id' => $serviceTypeId,
        'service_provider_id' => $serviceProviderId,
        'status' => 'completed',
        'occurred_at' => $overrides['scanned_at'] ?? '2026-06-01 08:30:00',
        'reference' => $overrides['transaction_number'] ?? 'EXP-TX-001',
        'amount' => $overrides['meal_amount'] ?? 125,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $attributes = [
        'transaction_number' => $overrides['transaction_number'] ?? 'EXP-TX-001',
        'employee_id' => $employee->id,
        'cafeteria_provider_id' => $provider->id,
        'transaction_date' => $overrides['transaction_date'] ?? '2026-06-01',
        'transaction_time' => '08:30:00',
        'scanned_at' => $overrides['scanned_at'] ?? '2026-06-01 08:30:00',
        'meal_amount' => $overrides['meal_amount'] ?? 125,
        'subsidy_amount_applied' => $overrides['subsidy_amount_applied'] ?? 100,
        'employee_payable_amount' => $overrides['employee_payable_amount'] ?? 25,
        'transaction_type' => 'scan',
        'status' => $overrides['status'] ?? CafeteriaTransactionStatus::Accepted,
        'usage_mode' => CafeteriaUsageMode::SingleDay,
        'consumed_days_count' => 1,
        'qr_reference' => $overrides['qr_reference'] ?? 'SECRET-QR-REFERENCE',
        'scan_nonce' => (string) Str::uuid(),
        'scan_request_hash' => $overrides['scan_request_hash'] ?? hash('sha256', 'secret-request'),
        'created_by' => $operator instanceof User ? $operator->id : null,
        'metadata' => ['private_note' => 'hidden-from-export'],
    ];

    if (Schema::hasColumn('cafeteria_transactions', 'service_transaction_id')) {
        $attributes['service_transaction_id'] = $serviceTransactionId;
    }

    return CafeteriaTransaction::query()->create($attributes);
}

test('provider portal exports only assigned provider transactions without sensitive fields', function (): void {
    [$user, $provider] = createExportPortalUserWithProvider();
    $employee = createExportEmployee('EMP-EXPORT-001', 'Ada Lovelace', '0911000001');
    $transaction = createExportTransaction($provider, $employee, $user);

    $otherProvider = CafeteriaProvider::query()->create([
        'code' => 'OTHER-CAF',
        'name_en' => 'Other Cafeteria',
        'is_active' => true,
    ]);
    $otherEmployee = createExportEmployee('EMP-EXPORT-002', 'Grace Hopper', '0911000002');
    createExportTransaction($otherProvider, $otherEmployee, $user, [
        'transaction_number' => 'OTHER-TX-001',
        'qr_reference' => 'OTHER-SECRET-QR',
        'scan_request_hash' => hash('sha256', 'other-secret-request'),
    ]);

    $response = $this->actingAs($user, 'provider')->get(route('provider.portal.transactions.export.csv', [
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $content = $response->streamedContent();

    expect($content)
        ->toContain($transaction->transaction_number)
        ->toContain($employee->employee_number)
        ->toContain('Ada Lovelace')
        ->not->toContain('OTHER-TX-001')
        ->not->toContain('Grace Hopper')
        ->not->toContain('SECRET-QR-REFERENCE')
        ->not->toContain('scan_request_hash')
        ->not->toContain('0911000001')
        ->not->toContain('hidden-from-export');

    expect(AuditLog::query()
        ->where('event_type', AuditEventType::CafeteriaProviderTransactionsExported)
        ->where('auditable_id', $provider->id)
        ->exists())->toBeTrue();
});

test('provider portal payment claim export includes scoped payable summary', function (): void {
    [$user, $provider] = createExportPortalUserWithProvider();
    $employee = createExportEmployee('EMP-CLAIM-001', 'Katherine Johnson', '0911000003');
    createExportTransaction($provider, $employee, $user, [
        'transaction_number' => 'CLAIM-TX-001',
        'subsidy_amount_applied' => 120,
        'employee_payable_amount' => 30,
    ]);

    $response = $this->actingAs($user, 'provider')->get(route('provider.portal.transactions.export.payment-claim', [
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]));

    $response->assertOk();

    $content = $response->streamedContent();

    expect($content)
        ->toContain('CLAIM-TX-001')
        ->toContain('120.00')
        ->toContain('30.00')
        ->toContain($user->name);

    expect(AuditLog::query()
        ->where('event_type', AuditEventType::CafeteriaProviderPaymentClaimExported)
        ->where('auditable_id', $provider->id)
        ->exists())->toBeTrue();
});

test('provider portal can export transactions as xlsx and pdf', function (): void {
    [$user, $provider] = createExportPortalUserWithProvider();
    $employee = createExportEmployee('EMP-FORMAT-001', 'Mary Jackson', '0911000004');
    createExportTransaction($provider, $employee, $user, [
        'transaction_number' => 'FORMAT-TX-001',
    ]);

    $query = [
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ];

    $this->actingAs($user, 'provider')
        ->get(route('provider.portal.transactions.export.xlsx', $query))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($user, 'provider')
        ->get(route('provider.portal.transactions.export.pdf', $query))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($user, 'provider')
        ->get(route('provider.portal.transactions.export.payment-claim.xlsx', $query))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($user, 'provider')
        ->get(route('provider.portal.transactions.export.payment-claim.pdf', $query))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('assigned provider portal user can export without separately seeded export permission', function (): void {
    [$user] = createExportPortalUserWithProvider(canExport: false);

    $this->actingAs($user, 'provider')
        ->get(route('provider.portal.transactions.export.csv'))
        ->assertOk();
});

test('unassigned provider portal user cannot export another provider transactions', function (): void {
    [, $provider] = createExportPortalUserWithProvider();

    $providerType = ProviderType::query()->firstOrCreate(
        ['code' => 'OTHER'],
        ['name_en' => 'Other', 'is_active' => true],
    );

    $otherGeneralProvider = Provider::query()->create([
        'provider_code' => 'OTHER-GENERAL',
        'provider_type_id' => $providerType->id,
        'name_en' => 'Other General Provider',
        'status' => 'active',
    ]);

    $user = ProviderUser::query()->create([
        'provider_id' => $otherGeneralProvider->id,
        'name' => 'Other Operator',
        'email' => 'other-operator@example.test',
        'username' => 'other-operator',
        'password' => Hash::make('password'),
        'provider_role' => 'operator',
        'status' => 'active',
        'portal_enabled' => true,
    ]);

    $this->actingAs($user, 'provider')
        ->withSession(['provider_portal.provider_id' => $provider->id])
        ->get(route('provider.portal.transactions.export.csv'))
        ->assertForbidden();
});
