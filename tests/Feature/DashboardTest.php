<?php

declare(strict_types=1);

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A fixed global dashboard scope.
 *
 * `DashboardScopeService::resolve()` derives organization ids from hierarchy
 * versions and user membership — state other tests in the suite change. Tests
 * that are about the counting logic, not about scope resolution, use this so
 * they assert the same thing whatever ran before them.
 *
 * @return array<string, mixed>
 */
function globalEmployeeScope(): array
{
    return [
        'global_access' => true,
        'provider_only' => false,
        'organization_ids' => [],
        'provider_ids' => [],
        'service_type_ids' => [],
        'top_limit' => 10,
    ];
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

test('unauthenticated user cannot access dashboard', function (): void {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('user without dashboard permission is sent to My Portal instead of the dashboard', function (): void {
    $user = User::factory()->create();

    // Sign-in already lands them there; a stale link must not end on a 403
    // whose only way forward is this same link again.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('employee.portal'));
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

test('reports.view alone does not open the audit activity feed', function (): void {
    // The feed names actors and actions; it follows the audit log policy.
    $user = User::factory()->create();
    $user->givePermissionTo(['dashboard.view', 'reports.view']);

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->where('can.audit', false));
    expect($response->viewData('page')['props']['recentActivity'])->toHaveCount(0);
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

test('transaction counts follow the officer organization scope', function (): void {
    $officer = User::where('email', 'hr.officer@demo.local')->firstOrFail();
    $admin = User::where('email', 'super.admin@demo.local')->firstOrFail();
    $metrics = app(DashboardMetricService::class);
    $scope = app(DashboardScopeService::class)->resolve($officer, []);

    $expected = ServiceTransaction::query()
        ->whereIn('employee_id', Employee::query()->whereIn('current_assignment_id', EmployeeAssignment::query()
            ->whereIn('organization_id', $scope['organization_ids'])->select('id'))->select('id'))
        ->count();

    expect($metrics->transactionQuery($scope)->count())->toBe($expected)
        ->and($metrics->transactionQuery(app(DashboardScopeService::class)->resolve($admin, []))->count())
        ->toBe(ServiceTransaction::query()->count());
});

test('provider top usage counts only transactions in the selected period', function (): void {
    // Load test 2026-10-11: the ranking joined every transaction ever recorded
    // (2.5 s at 220k employees); it now follows the dashboard's date range.
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();
    $template = ServiceTransaction::query()->whereNotNull('service_provider_id')->firstOrFail();
    $provider = ServiceProvider::query()->findOrFail($template->service_provider_id);
    $value = fn () => collect($this->actingAs($user)->get(route('dashboard', ['date_range' => '30d']))
        ->viewData('page')['props']['charts']['providersTopUsage'])->firstWhere('key', $provider->name)['value'] ?? 0;

    $before = $value();
    $old = $template->replicate();
    $old->forceFill(['occurred_at' => now()->subDays(400), 'reference' => 'OLD-'.uniqid()])->save();
    cache()->flush();

    expect($value())->toBe($before);
});

test('dashboard handles empty data safely', function (): void {
    AuditLog::query()->delete();
    ServiceTransaction::query()->delete();
    Entitlement::query()->delete();
    CardVerification::query()->delete();
    // The assignment pointer and card lineage are foreign keys on server databases (ADR 0003).
    Employee::query()->update(['current_assignment_id' => null]);
    IdCard::query()->update(['previous_card_id' => null]);
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

test('an expired dashboard entry is served at once and refreshed once afterwards, not recomputed by every viewer', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();
    $generatedAt = fn () => $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('page')['props']['header']['generatedAt'];

    $first = $generatedAt();
    $this->travel(10)->minutes(); // past the 5-minute fresh window, inside the stale window

    // The stale figures come back immediately; the refresh runs after the response.
    expect($generatedAt())->toBe($first);
    expect($generatedAt())->not->toBe($first);
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

test('grouped employee counts match per-status counts and one scan serves both KPIs', function (): void {
    $metrics = app(DashboardMetricService::class);

    // A fixed global scope rather than one resolved from the seeded user: the
    // resolver reads hierarchy versions and organization membership, which
    // other tests in the suite mutate, and that made this assertion depend on
    // suite ordering rather than on the thing it is testing.
    $scope = globalEmployeeScope();

    foreach (['active', 'inactive', 'suspended'] as $status) {
        expect($metrics->employeeStatusCount($scope, $status))
            ->toBe($metrics->employeeQuery($scope)->where('employees.status', $status)->count());
    }

    // The registered total is the sum of every bucket, not a separate scan.
    expect($metrics->employeeTotalCount($scope))
        ->toBe($metrics->employeeQuery($scope)->count());

    // A status with no rows is a real zero, never a missing key.
    expect($metrics->employeeStatusCount($scope, 'no_such_status'))->toBe(0);
});

test('the employee status buckets are fetched once per scope', function (): void {
    // The KPI strip and the employeesByStatus chart both want these numbers.
    // Before they shared a source they cost a full scan each, which on a
    // 3M-row table is ~400 ms apiece. Proven here by query count, not by
    // timing: two reads of the buckets must issue one query.
    $metrics = app(DashboardMetricService::class);
    $scope = globalEmployeeScope();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $metrics->employeeStatusCounts($scope);
    $metrics->employeeStatusCount($scope, 'active');
    $metrics->employeeTotalCount($scope);

    // PostgreSQL and SQLite quote the table as "employees", MySQL (the live server) as `employees`.
    $queries = collect(DB::getRawQueryLog())
        ->filter(fn (array $q): bool => preg_match('/from [`"]employees[`"]/', $q['raw_query']) === 1);

    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});

test('a partial scope without provider_only still resolves employee buckets', function (): void {
    /*
     * `DashboardScopeService` always supplies `provider_only`, but callers that
     * build a scope by hand (chart-level tests, and any future internal caller)
     * may not. The memo key treats an absent flag as false, matching the query
     * builders, which consult it only to add a restriction. Without this, a
     * partial scope fatals on an undefined key instead of returning counts.
     */
    $metrics = app(DashboardMetricService::class);

    $partial = [
        'global_access' => true,
        'organization_ids' => [],
        'top_limit' => 10,
    ];

    $counts = $metrics->employeeStatusCounts($partial);

    expect($counts)->toBeArray();
    expect($metrics->employeeTotalCount($partial))->toBe(array_sum($counts));
});

test('employee status buckets are keyed by scope and never leak across organizations', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();
    $scoped = User::where('email', 'hr.officer@demo.local')->firstOrFail();
    $metrics = app(DashboardMetricService::class);
    $scopes = app(DashboardScopeService::class);

    // Warm with the widest scope first — the ordering that would leak.
    $scopedScope = $scopes->resolve($scoped, []);
    $globalTotal = $metrics->employeeTotalCount($scopes->resolve($user, []));
    $scopedTotal = $metrics->employeeTotalCount($scopedScope);

    expect($scopedTotal)->toBeLessThanOrEqual($globalTotal);

    // Same scope instance on both sides: a scope re-resolved between the two
    // reads can legitimately differ, which is not what this test is about.
    expect($scopedTotal)->toBe($metrics->employeeQuery($scopedScope)->count());
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

test('users with the same scope and permissions share one dashboard computation', function (): void {
    // Risk register DB-5: ~50 COUNTs per dashboard were cached per user, so
    // every officer of one organization recomputed the same figures.
    $first = User::where('email', 'super.admin@demo.local')->firstOrFail();
    $second = User::factory()->create(['name' => 'Second Admin']);
    $second->assignRole('Super Admin');
    $props = fn (User $user) => $this->actingAs($user)->get(route('dashboard'))->viewData('page')['props'];

    $this->travelTo(now()->startOfMinute());
    $a = $props($first);
    $this->travel(30)->seconds();
    $b = $props($second);

    expect($b['header']['generatedAt'])->toBe($a['header']['generatedAt'])
        ->and($b['kpis'])->toEqual($a['kpis']);

    // A different scope never reuses it.
    $narrowed = $this->actingAs($second)->get(route('dashboard', ['organization_id' => EmployeeAssignment::query()->value('organization_id')]))->viewData('page')['props'];
    expect($narrowed['header']['generatedAt'])->not->toBe($a['header']['generatedAt']);
});

test('recent activity leaves out audited page views but keeps them in the audit log', function (): void {
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();
    app(WriteAuditLogAction::class)->execute(AuditEventType::BackupStatusViewed, $user);
    app(WriteAuditLogAction::class)->execute(AuditEventType::RestoreRequested, $user);

    $events = collect($this->actingAs($user)->get(route('dashboard'))->viewData('page')['props']['recentActivity'])->pluck('event');

    expect($events)->not->toContain('BACKUP_STATUS_VIEWED')->toContain('RESTORE_REQUESTED')
        ->and(AuditLog::where('event_type', 'BACKUP_STATUS_VIEWED')->exists())->toBeTrue();
});

test('a flow delta under a running total says what it counts', function (): void {
    /*
     * The "Active Employees" KPI is a stock (every active employee), but its
     * delta is a flow (employees registered inside the selected period). When
     * both wore the same "vs previous period" label, a reader could not tell
     * whether the total had moved by the delta or whether that many people had
     * simply been registered — at city scale the delta is invisible noise
     * against the value it sits beneath. The label now names the measure.
     */
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $kpis = collect($response->viewData('page')['props']['kpis'])->keyBy('key');

    $active = $kpis->get('activeEmployees');
    expect($active)->not->toBeNull();
    expect($active['trend'])->toBeInt();
    expect($active['comparisonLabelKey'])->toBe('dashboard.newlyRegisteredVsPrevious');

    // The generic label must not reappear on a stock-vs-flow comparison.
    expect(collect($kpis)->pluck('comparisonLabelKey')->filter())
        ->not->toContain('dashboard.previousPeriod');
});

test('a kpi without a trend advertises no comparison', function (): void {
    // Guards the `$trend['labelKey'] ?? null` path: a card with nothing to
    // compare must not inherit a stale comparison label.
    $user = User::where('email', 'super.admin@demo.local')->firstOrFail();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $kpis = collect($response->viewData('page')['props']['kpis']);

    $withoutTrend = $kpis->filter(fn (array $kpi): bool => $kpi['trend'] === null);

    expect($withoutTrend)->not->toBeEmpty();
    $withoutTrend->each(function (array $kpi): void {
        expect($kpi['comparisonLabelKey'])->toBeNull();
    });
});
