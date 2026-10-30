<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Entitlement;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Cafeteria\Infrastructure\Platform\CafeteriaPlatformClient;

/*
 * The cafeteria on its own platform (ADR 0014, config/cafeteria_platform.php):
 * in platform mode the cafeteria pages send people to the platform and the
 * employee portal reads an employee's standing over the platform's API; in
 * local mode nothing changes.
 */

test('in local mode the cafeteria pages stay in EUISIS', function (): void {
    config(['cafeteria_platform.mode' => 'local', 'cafeteria_platform.url' => 'https://cafeteria.test']);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/cafeteria')->assertStatus(403);
    expect(app(CafeteriaPlatformClient::class)->enabled())->toBeFalse();
});

test('in platform mode the cafeteria pages and the provider portal shortcut go to the platform on the same path', function (): void {
    config(['cafeteria_platform.mode' => 'platform', 'cafeteria_platform.url' => 'https://cafeteria.test', 'cafeteria_platform.token' => 'secret']);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/cafeteria/transactions?page=2')->assertRedirect('https://cafeteria.test/cafeteria/transactions?page=2');
    $this->actingAs($user)->get('/cafeteria')->assertRedirect('https://cafeteria.test/cafeteria');
    $this->get('/cafe')->assertRedirect('https://cafeteria.test/cafe');
    // The provider portal's own sign-in stays where it is.
    $this->get('/provider/portal/login')->assertOk();
});

test('in platform mode my services reads the cafeteria standing and meals from the platform', function (): void {
    $this->travelTo(now()->next(Carbon::WEDNESDAY)->setTime(10, 0));
    config(['cafeteria_platform.mode' => 'platform', 'cafeteria_platform.url' => 'https://cafeteria.test', 'cafeteria_platform.token' => 'secret']);

    $employee = Employee::query()->create(['employee_number' => 'EMP-PLAT-1', 'first_name' => 'Abebe', 'last_name' => 'Kebede', 'full_name' => 'Abebe Kebede', 'status' => EmployeeStatus::Active]);
    $user = User::factory()->create(['email' => 'eater-platform@example.test', 'status' => 'active', 'employee_id' => $employee->id]);
    $type = ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria', 'name_am' => 'ካፍቴሪያ']);
    Entitlement::query()->create(['employee_id' => $employee->id, 'service_type_id' => $type->id, 'status' => 'active', 'effective_from' => now()->subMonth()->toDateString()]);

    $today = Carbon::today()->toDateString();
    $yesterday = Carbon::yesterday()->toDateString();
    Http::fake([
        "https://cafeteria.test/api/v1/integration/cafeteria/employees/{$employee->id}/entitlement" => Http::response(['data' => [
            'employee_id' => $employee->id, 'employee_number' => 'EMP-PLAT-1', 'organization_id' => (string) Str::uuid(), 'eligible' => true, 'reason' => null,
            'policy' => ['daily_subsidy_amount' => '55.00', 'employee_contribution_amount' => '0.00', 'provider_price' => '55.00', 'currency_code' => 'ETB', 'max_daily_uses' => 1],
            'cafeteria' => ['id' => (string) Str::uuid(), 'code' => 'CAF-1', 'name_en' => 'Cafeteria 1', 'name_am' => null], 'used_today' => 1, 'exclusion' => null,
        ]]),
        "https://cafeteria.test/api/v1/integration/cafeteria/employees/{$employee->id}/transactions*" => Http::response(['data' => [
            ['id' => (string) Str::uuid(), 'transaction_number' => 'CAF-1', 'transaction_date' => $today, 'scanned_at' => Carbon::today()->setTime(12, 5)->toIso8601String(), 'status' => 'accepted', 'subsidy_amount' => '55.00', 'employee_payable_amount' => '0.00', 'total_amount' => '55.00', 'currency_code' => 'ETB', 'is_extra_scan' => false, 'cafeteria' => ['name_en' => 'Cafeteria 1']],
            ['id' => (string) Str::uuid(), 'transaction_number' => 'CAF-2', 'transaction_date' => $yesterday, 'scanned_at' => Carbon::yesterday()->setTime(12, 5)->toIso8601String(), 'status' => 'accepted', 'subsidy_amount' => '55.00', 'employee_payable_amount' => '0.00', 'total_amount' => '55.00', 'currency_code' => 'ETB', 'is_extra_scan' => false, 'cafeteria' => ['name_en' => 'Cafeteria 1']],
            ['id' => (string) Str::uuid(), 'transaction_number' => 'CAF-3', 'transaction_date' => $yesterday, 'scanned_at' => Carbon::yesterday()->setTime(12, 9)->toIso8601String(), 'status' => 'rejected', 'subsidy_amount' => '0.00', 'employee_payable_amount' => '0.00', 'total_amount' => '0.00', 'currency_code' => 'ETB', 'is_extra_scan' => true, 'cafeteria' => ['name_en' => 'Cafeteria 1']],
        ]]),
    ]);

    $this->actingAs($user)->get(route('employee.services'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/MyEntitlements')
            ->where('entitlements.0.activity.type', 'cafeteria')
            ->where('entitlements.0.activity.daily.consumed', true)
            ->where('entitlements.0.activity.daily.subsidy', 55)
            ->where('entitlements.0.activity.weekly.days_consumed', 2)
            ->where('entitlements.0.activity.weekly.subsidy_used', 110)
            ->where('entitlements.0.activity.weekly.daily_rate', 55)
            ->where('entitlements.0.activity.platform.eligible', true)
            ->where('entitlements.0.activity.platform.url', 'https://cafeteria.test')
            ->where("entitlements.0.activity.consumed.{$today}", 55)
            ->has('entitlements.0.activity.transactions', 3));
});

test('the employee portal can read the platform while EUISIS still serves the cafeteria pages', function (): void {
    config(['cafeteria_platform.mode' => 'local', 'cafeteria_platform.url' => 'https://cafeteria.test', 'cafeteria_platform.token' => 'secret']);
    $client = app(CafeteriaPlatformClient::class);

    config(['cafeteria_platform.employee_portal' => null]);
    expect($client->servesEmployeePortal())->toBeFalse();
    // Blank, as .env.example ships it, follows the mode too.
    config(['cafeteria_platform.employee_portal' => '', 'cafeteria_platform.mode' => 'platform']);
    expect($client->servesEmployeePortal())->toBeTrue();
    config(['cafeteria_platform.mode' => 'local']);
    expect($client->servesEmployeePortal())->toBeFalse();
    config(['cafeteria_platform.employee_portal' => true]);
    expect($client->servesEmployeePortal())->toBeTrue()->and($client->enabled())->toBeFalse();
    config(['cafeteria_platform.employee_portal' => false, 'cafeteria_platform.mode' => 'platform']);
    expect($client->servesEmployeePortal())->toBeFalse()->and($client->enabled())->toBeTrue();
    config(['cafeteria_platform.employee_portal' => true, 'cafeteria_platform.token' => '']);
    expect($client->servesEmployeePortal())->toBeFalse();
});

test('the platform client reads the totals and the cafeterias', function (): void {
    config(['cafeteria_platform.mode' => 'platform', 'cafeteria_platform.url' => 'https://cafeteria.test', 'cafeteria_platform.token' => 'secret']);
    Http::fake([
        'https://cafeteria.test/api/v1/integration/cafeteria/summary*' => Http::response(['data' => ['meals' => 3, 'subsidy_amount' => '165.00']]),
        'https://cafeteria.test/api/v1/integration/cafeteria/providers' => Http::response(['data' => [['id' => (string) Str::uuid(), 'code' => 'CAF-1']]]),
    ]);

    $client = app(CafeteriaPlatformClient::class);
    expect($client->summary()['meals'])->toBe(3)
        ->and($client->providers())->toHaveCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret'));

    $this->artisan('cafeteria-platform:ping')->assertSuccessful();
});
