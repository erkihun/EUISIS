<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CafeteriaTransaction;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CafeteriaScenario;
use Tests\TestCase;

/**
 * The cafeteria module on its own platform (ADR 0014): the EUISIS screens
 * open, a scan records a meal over the mirrored master data, EUISIS is asked
 * about a card the mirror does not know, the directory sync fills the
 * mirror, and EUISIS reads the results back over the platform's API.
 */
class CafeteriaPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(?Organization $scope = null): User
    {
        $user = User::query()->create([
            'name' => 'Cafeteria admin',
            'email' => 'admin-'.Str::random(6).'@cafeteria.test',
            'password' => 'secret-password',
            'role' => 'core_admin',
            'scope_organization_id' => $scope?->id,
            'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE,
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_the_cafeteria_screens_open_for_an_administrator(): void
    {
        $scenario = CafeteriaScenario::make();
        $organization = $scenario->organization('Bureau A');
        $scenario->enroll($organization);
        [$employee, $card] = $scenario->employee($organization);
        $this->admin();

        $pages = [
            '/' => 'Cafeteria/Dashboard', '/cafeteria' => 'Cafeteria/Dashboard', '/cafeteria/scan' => 'Cafeteria/Scan', '/cafeteria/scan/mobile' => 'Cafeteria/MobileScan',
            '/cafeteria/transactions' => 'Cafeteria/Transactions/Index', '/cafeteria/ledger' => 'Cafeteria/Ledger/Index', '/cafeteria/reports' => 'Cafeteria/Reports/Index',
            '/cafeteria/analytics' => 'Cafeteria/Analytics/Index', '/cafeteria/providers' => 'Cafeteria/Providers/Index', '/cafeteria/providers/create' => 'Cafeteria/Providers/Create',
            '/cafeteria/providers/'.$scenario->main->id => 'Cafeteria/Providers/Show', '/cafeteria/providers/dashboard' => 'Cafeteria/Providers/ProviderDashboard',
            '/cafeteria/subsidy-rules' => 'Cafeteria/SubsidyRules/Index', '/cafeteria/holidays' => 'Cafeteria/Holidays/Index', '/cafeteria/settings' => 'Cafeteria/Settings/Index',
            '/cafeteria/day-rules' => 'Cafeteria/DayRules/Index', '/cafeteria/special-days' => 'Cafeteria/SpecialDays/Index',
            '/cafeteria/employee-exclusions' => 'Cafeteria/EmployeeExclusions/Index', '/cafeteria/employee-exclusions/create' => 'Cafeteria/EmployeeExclusions/Create',
            '/cafeteria/payees' => 'Cafeteria/Payees/Index', '/cafeteria/networks' => 'Cafeteria/Networks/Index', '/cafeteria/networks/'.$scenario->network->id => 'Cafeteria/Networks/Show',
            '/cafeteria/organization-access' => 'Cafeteria/Access/Index', '/cafeteria/service-assignments' => 'Cafeteria/Assignments/Index',
            '/cafeteria/service-policies' => 'Cafeteria/Policies/Index', '/cafeteria/service-policies/create' => 'Cafeteria/Policies/Form',
            '/cafeteria/settlements' => 'Cafeteria/Settlements/Index', '/cafeteria/settlements/create' => 'Cafeteria/Settlements/Create',
            '/users' => 'Users/Index', '/roles' => 'Roles/Index', '/system-settings' => 'SystemSettings/Index', '/audit-logs' => 'AuditLogs/Index',
        ];
        foreach ($pages as $path => $component) {
            $response = $this->get($path);
            if ($response->isRedirect()) {
                $response = $this->get($response->headers->get('Location'));
            }
            $this->assertSame(200, $response->status(), "Page $path answered {$response->status()}");
            $this->assertSame($component, $response->viewData('page')['component'] ?? null, "Page $path");
        }

        // The sidebar carries the Cafeteria Management group, from the server.
        $nav = $this->get('/cafeteria')->viewData('page')['props']['workspace']['nav'];
        $routes = collect($nav)->flatMap(fn (array $section) => collect($section['groups'])->flatMap(fn (array $group) => array_column($group['items'], 'route')))->all();
        $this->assertContains('cafeteria.scan', $routes);
        $this->assertContains('cafeteria.policies.index', $routes);
        $this->assertContains('users.index', $routes);
    }

    public function test_a_scan_records_a_meal_from_the_mirrored_card_and_a_second_scan_is_blocked(): void
    {
        $scenario = CafeteriaScenario::make();
        $organization = $scenario->organization('Bureau A');
        $scenario->enroll($organization, '120.00');
        [$employee, $card] = $scenario->employee($organization);
        $this->admin();
        $this->travelTo(now()->next('Tuesday')->setTime(12, 30));

        $scan = fn (): TestResponse => $this->post('/cafeteria/scan', [
            'qr_token' => $card->public_card_uuid, 'provider_id' => $scenario->main->id, 'scan_nonce' => (string) Str::uuid(), 'usage_mode' => 'single_day',
        ]);
        $first = $scan();
        $first->assertRedirect();
        $result = session('scan_result');
        $this->assertTrue($result['allowed'] ?? false, json_encode($result));
        $this->assertSame(1, CafeteriaTransaction::query()->where('employee_id', $employee->id)->where('status', 'accepted')->count());
        $this->assertSame('120.00', CafeteriaTransaction::query()->where('employee_id', $employee->id)->value('subsidy_amount_applied'));

        $scan();
        $this->assertFalse(session('scan_result')['allowed']);
        $this->assertSame(1, CafeteriaTransaction::query()->where('employee_id', $employee->id)->where('status', 'accepted')->count());

        // The verification URL printed in the QR code resolves to the same card.
        $this->travelTo(now()->addDay()->setTime(12, 30));
        $this->post('/cafeteria/scan', ['qr_token' => 'https://euisis.example/id-checker/'.$card->public_card_uuid, 'provider_id' => $scenario->main->id, 'scan_nonce' => (string) Str::uuid(), 'usage_mode' => 'single_day']);
        $this->assertTrue(session('scan_result')['allowed'], json_encode(session('scan_result')));
    }

    public function test_an_unknown_card_is_verified_with_euisis_and_mirrored(): void
    {
        config(['euisis.url' => 'https://euisis.test', 'euisis.token' => 'secret']);
        $scenario = CafeteriaScenario::make();
        $organization = $scenario->organization('Bureau A');
        $scenario->enroll($organization, '90.00');
        $this->admin();
        $this->travelTo(now()->next('Wednesday')->setTime(12, 30));

        $cardUuid = (string) Str::uuid();
        $employeeUuid = (string) Str::uuid();
        Http::fake([
            "https://euisis.test/api/v1/id-cards/verify/{$cardUuid}" => Http::response([
                'valid' => true, 'status' => 'active', 'card_uuid' => $cardUuid, 'employee_uuid' => $employeeUuid,
                'organization_uuid' => $organization->id, 'assignment_status' => 'active',
                'card' => ['card_uuid' => $cardUuid, 'card_number' => 'AA-0001', 'issued_at' => '2026-01-10', 'expires_at' => '2028-01-10', 'is_current' => true],
                'employee' => ['employee_uuid' => $employeeUuid, 'employee_number' => 'EMP-9001', 'full_name' => 'Abebe Kebede', 'status' => 'active'],
                'organization' => ['organization_uuid' => $organization->id, 'code' => $organization->code, 'name_en' => $organization->name_en, 'name_am' => null],
            ]),
            'https://euisis.test/*' => Http::response(['message' => 'Card not found.'], 404),
        ]);

        $this->post('/cafeteria/scan', ['qr_token' => $cardUuid, 'provider_id' => $scenario->main->id, 'scan_nonce' => (string) Str::uuid(), 'usage_mode' => 'single_day']);
        $result = session('scan_result');
        $this->assertTrue($result['allowed'] ?? false, json_encode($result));

        $employee = Employee::query()->find($employeeUuid);
        $this->assertNotNull($employee);
        $this->assertSame('EMP-9001', $employee->employee_number);
        $this->assertSame($organization->id, EmployeeAssignment::query()->where('employee_id', $employeeUuid)->where('is_current', true)->value('organization_id'));
        $this->assertSame($cardUuid, IdCard::query()->where('public_card_uuid', $cardUuid)->value('id'));
        $this->assertSame('90.00', CafeteriaTransaction::query()->where('employee_id', $employeeUuid)->value('subsidy_amount_applied'));

        // A card EUISIS does not know is refused.
        $this->post('/cafeteria/scan', ['qr_token' => (string) Str::uuid(), 'provider_id' => $scenario->main->id, 'scan_nonce' => (string) Str::uuid(), 'usage_mode' => 'single_day']);
        $this->assertFalse(session('scan_result')['allowed']);
    }

    public function test_the_directory_sync_mirrors_organizations_units_and_employees(): void
    {
        config(['euisis.url' => 'https://euisis.test', 'euisis.token' => 'secret']);
        $org = (string) Str::uuid();
        $unit = (string) Str::uuid();
        $emp = (string) Str::uuid();
        $assignment = (string) Str::uuid();
        Http::fake([
            'https://euisis.test/api/v1/organizations/directory*' => Http::response(['data' => [
                ['id' => $org, 'code' => 'BUR-A', 'name_en' => 'Bureau A', 'name_am' => 'ቢሮ ሀ', 'organization_type' => ['code' => 'BUREAU', 'name_en' => 'Bureau', 'name_am' => null], 'status' => 'active'],
            ], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
            "https://euisis.test/api/v1/organizations/{$org}/units*" => Http::response(['data' => [
                ['id' => $unit, 'code' => 'HR', 'name_en' => 'Human Resources', 'name_am' => null, 'parent_unit_id' => null, 'organization_id' => $org, 'status' => 'active'],
            ], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
            'https://euisis.test/api/v1/employees*' => Http::response(['data' => [
                ['id' => $emp, 'employee_number' => 'EMP-0001', 'full_name' => 'Abebe Kebede', 'full_name_en' => 'Abebe Kebede', 'employment_status' => 'active',
                    'organization_id' => $org, 'organization_unit_id' => $unit, 'position_id' => null,
                    'active_assignment' => ['id' => $assignment, 'employee_id' => $emp, 'organization_id' => $org, 'organization_unit_id' => $unit, 'position_id' => null, 'assignment_status' => 'active', 'is_current' => true, 'effective_from' => '2025-01-01', 'effective_to' => null]],
            ], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
        ]);

        $this->artisan('cafeteria:sync-from-euisis')->assertSuccessful();

        $this->assertSame('Bureau A', Organization::query()->find($org)?->name_en);
        $this->assertSame($org, EmployeeAssignment::query()->find($assignment)?->organization_id);
        $this->assertSame($assignment, Employee::query()->find($emp)?->current_assignment_id);
        $this->assertSame($unit, EmployeeAssignment::query()->find($assignment)?->organization_unit_id);

        // The next run asks only for changes since the first.
        $this->artisan('cafeteria:sync-from-euisis')->assertSuccessful();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/employees') && str_contains($request->url(), 'updated_after='));
    }

    public function test_euisis_reads_an_employees_standing_meals_and_the_totals_with_the_cafeteria_scope(): void
    {
        $scenario = CafeteriaScenario::make();
        $organization = $scenario->organization('Bureau A');
        $scenario->enroll($organization, '120.00');
        [$employee, $card] = $scenario->employee($organization);
        $this->admin();
        $this->travelTo(now()->next('Thursday')->setTime(12, 30));
        $this->post('/cafeteria/scan', ['qr_token' => $card->public_card_uuid, 'provider_id' => $scenario->main->id, 'scan_nonce' => (string) Str::uuid(), 'usage_mode' => 'single_day']);
        $this->assertTrue(session('scan_result')['allowed']);

        $with = $this->client(['cafeteria:read']);
        $without = $this->client(['organizations:read']);

        $this->getJson("/api/v1/integration/cafeteria/employees/{$employee->id}/entitlement", $this->bearer($with))->assertOk()
            ->assertJsonPath('data.eligible', true)->assertJsonPath('data.used_today', 1)->assertJsonPath('data.policy.daily_subsidy_amount', '120.00')
            ->assertJsonPath('data.cafeteria.id', $scenario->main->id);
        $this->getJson("/api/v1/integration/cafeteria/employees/{$employee->id}/transactions", $this->bearer($with))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'accepted')->assertJsonPath('data.0.subsidy_amount', '120.00');
        $this->getJson('/api/v1/integration/cafeteria/summary', $this->bearer($with))->assertOk()
            ->assertJsonPath('data.meals', 1)->assertJsonPath('data.subsidy_amount', '120.00')->assertJsonPath('data.per_cafeteria.0.id', $scenario->main->id);
        $this->getJson('/api/v1/integration/cafeteria/providers', $this->bearer($with))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/integration/cafeteria/employees/'.Str::uuid().'/entitlement', $this->bearer($with))->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');

        $this->getJson('/api/v1/integration/cafeteria/summary', $this->bearer($without))->assertForbidden()->assertJsonPath('code', 'SCOPE_DENIED');
    }

    public function test_the_provider_portal_signs_its_operators_in_on_their_own_guard(): void
    {
        $this->get('/provider/portal/login')->assertOk();
        $this->get('/cafe')->assertRedirect('/provider/portal/login');
        $this->get('/provider/portal/dashboard')->assertRedirect('/provider/portal/login');
    }

    /** @param  list<string>  $scopes */
    private function client(array $scopes): string
    {
        $response = $this->postJson('/api/v1/api-clients', ['name' => 'EUISIS', 'scopes' => $scopes, 'scope_type' => 'citywide'])->assertCreated();

        return $response->json('secret');
    }

    /** @return array<string, string> */
    private function bearer(string $secret): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$secret];
    }
}
