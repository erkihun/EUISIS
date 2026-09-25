<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditLog;
use App\Models\CardRequest;
use App\Models\CardVerification;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeTransfer;
use App\Models\Entitlement;
use App\Models\IdCard;
use App\Models\NfcCredential;
use App\Models\ServiceProvider;
use App\Models\ServiceTransaction;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricService;
use App\Services\Dashboard\DashboardScopeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

test('unauthenticated user cannot access dashboard', function (): void {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('user without dashboard permission cannot access dashboard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden();
});

test('super admin receives all dashboard sections', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('can.dashboard', true)
            ->where('can.employees', true)
            ->where('can.organizations', true)
            ->where('can.positions', true)
            ->where('can.cards', true)
            ->where('can.entitlements', true)
            ->where('can.transactions', true)
            ->where('can.providers', true)
            ->where('can.transfers', true)
            ->where('can.audit', true)
            ->has('kpis')
            ->has('charts')
            ->has('cards.positions')
            ->has('charts.positionsByGradeLevel')
            ->has('charts.positionsByJobFamily')
            ->has('charts.positionsByOrganization')
            ->has('workflowQueues')
            ->has('alerts')
        );
});

test('scoped hr officer receives scoped dashboard counts', function (): void {
    $user = User::where('email', 'hr.officer@demo.local')->firstOrFail();

    $response = $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('can.employees', true)
        ->where('can.audit', false)
        ->where('meta.scope.providerOnly', false)
        ->has('kpis')
    );

    $props = $response->viewData('page')['props'];

    expect(collect($props['kpis'])->pluck('key'))->toContain('activeEmployees');
    expect($props['recentActivity'])->toBeArray()->toHaveCount(0);
});

test('provider user does not receive hr sections', function (): void {
    $user = User::where('email', 'provider.transport@demo.local')->firstOrFail();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.scope.providerOnly', true)
            ->where('can.employees', false)
            ->where('can.organizations', false)
            ->where('can.transactions', true)
            ->where('can.providers', true)
        );
});

test('dashboard props do not expose sensitive pii fields', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $propsJson = json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR);

    expect($propsJson)->not->toContain('0911000000')
        ->and($propsJson)->not->toContain('demo-token')
        ->and($propsJson)->not->toContain('token_hash')
        ->and($propsJson)->not->toContain('photo_path')
        ->and($propsJson)->not->toContain('signature_path');
});

test('dashboard respects date range filters and keeps bounded lists', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();

    $response = $this->actingAs($user)
        ->get(route('dashboard', ['date_range' => '7d']))
        ->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['filters']['dateRange'])->toBe('7d');
    expect($props['recentActivity'])->toBeArray();
    expect(count($props['recentActivity']))->toBeLessThanOrEqual(12);
    expect($props['charts']['providersTopUsage'])->toBeArray();
    expect(count($props['charts']['providersTopUsage']))->toBeLessThanOrEqual(10);
});

test('dashboard handles empty data safely', function (): void {
    AuditLog::query()->delete();
    ServiceTransaction::query()->delete();
    Entitlement::query()->delete();
    CardVerification::query()->delete();
    IdCard::query()->delete();
    CardRequest::query()->delete();
    EmployeeTransfer::query()->delete();
    EmployeeAssignment::query()->delete();
    Employee::query()->delete();
    ServiceProvider::query()->delete();

    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('kpis')
            ->has('charts')
            ->has('workflowQueues')
            ->has('alerts')
        );
});

/*
 * Admin navigation visibility.
 *
 * `is_employee_user` drives whether the sidebar collapses to employee
 * self-service links. Staff who administer the system are usually employees
 * too, so the flag must key on administrative access rather than on the mere
 * existence of an employee record — otherwise an admin whose email matches an
 * employee loses the entire admin sidebar while still holding every permission.
 */

it('keeps an admin out of employee-only mode when linked to an employee record', function (): void {
    $admin = User::factory()->create(['email' => 'linked.admin@example.test']);
    $admin->assignRole('Super Admin');

    // The User <-> Employee link is by matching email address.
    Employee::query()->create([
        'employee_number' => 'NAV-ADMIN-1',
        'first_name' => 'Linked',
        'last_name' => 'Admin',
        'full_name' => 'Linked Admin',
        'email' => 'linked.admin@example.test',
        'status' => 'active',
    ]);

    // The accessor applies the email fallback for accounts not yet linked by employee_id.
    expect($admin->employee)->not->toBeNull();

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('is_employee_user', false));
});

it('keeps employee self-service mode for a user with no administrative access', function (): void {
    $employeeUser = User::factory()->create(['email' => 'plain.staff@example.test']);

    Employee::query()->create([
        'employee_number' => 'NAV-EMP-1',
        'first_name' => 'Plain',
        'last_name' => 'Staff',
        'full_name' => 'Plain Staff',
        'email' => 'plain.staff@example.test',
        'status' => 'active',
    ]);

    expect($employeeUser->employee)->not->toBeNull()
        ->and($employeeUser->can('dashboard.view'))->toBeFalse();

    $middleware = app(HandleInertiaRequests::class);
    $resolve = new ReflectionMethod($middleware, 'resolveIsEmployeeUser');
    $resolve->setAccessible(true);

    expect($resolve->invoke($middleware, $employeeUser))->toBeTrue();
});

// ── Header, NFC and integration sections ─────────────────────────────────

test('dashboard header reports scope, refresh time and only permitted actions', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $header = $response->viewData('page')['props']['header'];

    expect($header['generatedAt'])->not->toBeNull()
        ->and($header['globalAccess'])->toBeTrue()
        ->and($header['quickActions'])->not->toBeEmpty();

    // Every advertised action must resolve to a real, registered route.
    foreach ($header['quickActions'] as $action) {
        expect(Route::has($action['routeName']))->toBeTrue();
    }
});

test('a user without nfc or api permission gets neither section', function (): void {
    $user = User::where('email', 'hr.officer@demo.local')->firstOrFail();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['can']['nfc'])->toBeFalse()
        ->and($props['can']['integration'])->toBeFalse()
        ->and($props['cards'])->not->toHaveKey('nfc')
        ->and($props['cards'])->not->toHaveKey('integration');
});

test('super admin receives nfc and integration metrics', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['can']['nfc'])->toBeTrue()
        ->and($props['can']['integration'])->toBeTrue();

    foreach (['active', 'pending', 'suspended', 'revoked', 'lost', 'activeTerminals', 'inactiveTerminals', 'verificationsToday'] as $key) {
        expect($props['cards']['nfc'])->toHaveKey($key);
    }

    foreach (['activeApplications', 'activeTokens', 'activeEndpoints', 'requestsToday', 'failedToday'] as $key) {
        expect($props['cards']['integration'])->toHaveKey($key);
    }
});

test('nfc credential counts honour organization scope', function (): void {
    $scoped = User::where('email', 'hr.officer@demo.local')->firstOrFail();
    $scoped->givePermissionTo('nfc_credentials.view');

    $metrics = app(DashboardMetricService::class);
    $scopeService = app(DashboardScopeService::class);
    $scope = $scopeService->resolve($scoped, []);

    // Provision a credential for a card OUTSIDE the officer's organizations.
    $outsideCard = IdCard::query()
        ->whereHas('employee.currentAssignment', fn ($q) => $q->whereNotIn('organization_id', $scope['organization_ids']))
        ->first();

    if ($outsideCard === null) {
        $this->markTestSkipped('Seed data has no ID card outside the scoped officer organizations.');
    }

    NfcCredential::create([
        'id_card_id' => $outsideCard->id,
        'credential_id' => 'nfc_'.str_repeat('a', 64),
        'credential_type' => 'ndef_reference',
        'status' => 'active',
        'issued_at' => now(),
    ]);

    $scopedCount = $metrics->nfcCredentialQuery($scope)->count();
    $globalScope = $scopeService->resolve(User::where('email', 'super.admin@demo.local')->firstOrFail(), []);

    expect($scopedCount)->toBe(0)
        ->and($metrics->nfcCredentialQuery($globalScope)->count())->toBe(1);
});

test('dashboard never exposes api tokens or secrets', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();

    $payload = json_encode($this->actingAs($user)->get(route('dashboard'))->viewData('page')['props']);

    foreach (['plainTextToken', 'access_token', 'allowed_scopes', 'token_hash', 'certificate_reference', 'key_reference'] as $secret) {
        expect($payload)->not->toContain($secret);
    }
});

// ── Grouped status counts ────────────────────────────────────────────────

test('grouped status counts match per-status counts exactly', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();
    $metrics = app(DashboardMetricService::class);
    $scope = app(DashboardScopeService::class)->resolve($user, []);

    foreach (['active', 'printed', 'issued', 'pending_print', 'expired', 'lost', 'revoked', 'replaced'] as $status) {
        expect($metrics->cardStatusCount($scope, $status))
            ->toBe($metrics->cardQuery($scope)->where('id_cards.status', $status)->count());
    }

    foreach (['submitted', 'verified', 'approved'] as $status) {
        expect($metrics->cardRequestStatusCount($scope, $status))
            ->toBe($metrics->cardRequestQuery($scope)->where('card_requests.status', $status)->count());
    }

    // The funnel's first step sums every bucket, so it must equal the total.
    expect(array_sum($metrics->cardRequestStatusCounts($scope)))
        ->toBe($metrics->cardRequestQuery($scope)->count());

    expect($metrics->cardRequestStatusCount($scope, 'submitted', 'verified'))
        ->toBe($metrics->cardRequestQuery($scope)->whereIn('card_requests.status', ['submitted', 'verified'])->count());
});

test('memoised status counts are keyed by scope and never leak across organizations', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();
    $metrics = app(DashboardMetricService::class);
    $globalScope = app(DashboardScopeService::class)->resolve($user, []);

    // Warm the cache with the widest scope first — the dangerous ordering.
    $global = $metrics->cardStatusCounts($globalScope);

    $emptyScope = array_merge($globalScope, ['global_access' => false, 'organization_ids' => []]);

    expect(array_sum($global))->toBeGreaterThan(0)
        ->and(array_sum($metrics->cardStatusCounts($emptyScope)))->toBe(0);
});
