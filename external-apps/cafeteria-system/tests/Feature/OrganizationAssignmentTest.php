<?php

declare(strict_types=1);

use CafeteriaSystem\Models\Cafeteria;
use CafeteriaSystem\Models\CafeteriaOrganizationAssignment;
use CafeteriaSystem\Models\CafeteriaProvider;
use CafeteriaSystem\Models\CafeteriaServiceTransaction;
use CafeteriaSystem\Models\CafeteriaSubsidyLedger;
use CafeteriaSystem\Models\CafeteriaUser;
use CafeteriaSystem\Services\CafeteriaReportService;
use CafeteriaSystem\Services\ServeEmployeeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Organization-based serving rules and provider/cafeteria data isolation.
 */
beforeEach(function (): void {
    config()->set('euisis.base_url', 'https://euisis.test');
    config()->set('euisis.token', 'test-token');

    // Provider A with two cafeterias.
    $this->providerA = CafeteriaProvider::query()->create([
        'code' => 'PROV-A', 'name' => 'Provider A', 'status' => 'active',
    ]);

    $this->cafeteriaA1 = Cafeteria::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Cafeteria A1', 'code' => 'CAF-A1', 'status' => 'active',
    ]);

    $this->cafeteriaA2 = Cafeteria::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Cafeteria A2', 'code' => 'CAF-A2', 'status' => 'active',
    ]);

    // A rival provider, used to prove isolation.
    $this->providerB = CafeteriaProvider::query()->create([
        'code' => 'PROV-B', 'name' => 'Provider B', 'status' => 'active',
    ]);

    $this->cafeteriaB1 = Cafeteria::query()->create([
        'provider_id' => $this->providerB->id,
        'name' => 'Cafeteria B1', 'code' => 'CAF-B1', 'status' => 'active',
    ]);

    // ORG-1 is served by A1 only.
    $this->assignment = CafeteriaOrganizationAssignment::query()->create([
        'cafeteria_id' => $this->cafeteriaA1->id,
        'organization_code' => 'ORG-1',
        'organization_name_snapshot' => 'Bole Sub-city',
        'status' => 'active',
        'effective_from' => now()->subMonth()->toDateString(),
    ]);

    $this->service = app(ServeEmployeeService::class);
});

/** @param array<string, mixed> $overrides */
function euisisCard(array $overrides = []): array
{
    return array_merge([
        'valid' => true,
        'status' => 'active',
        'employee_id' => '019f0000-0000-7000-8000-000000000001',
        'employee' => [
            'employee_number' => 'EMP-1',
            'full_name' => 'Abebe Bekele',
            'status' => 'active',
        ],
        'organization' => ['code' => 'ORG-1', 'name_en' => 'Bole Sub-city'],
        'position' => ['title_en' => 'HR Officer'],
    ], $overrides);
}

function fakeEuisis(array $card = [], bool $eligible = true): void
{
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response(euisisCard($card), 200),
        'https://euisis.test/api/v1/employees/*/service-eligibility*' => Http::response(
            ['eligible' => $eligible, 'reason_code' => $eligible ? null : 'not_eligible'],
            $eligible ? 200 : 403,
        ),
    ]);
}

// ── Organization assignment ─────────────────────────────────────────────

it('serves an employee whose organization is assigned to this cafeteria', function (): void {
    fakeEuisis();

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA1);

    expect($result['served'])->toBeTrue()
        ->and($result['result_code'])->toBe('served');

    $stored = CafeteriaServiceTransaction::query()->firstOrFail();

    expect($stored->organization_code)->toBe('ORG-1')
        ->and($stored->cafeteria_id)->toBe($this->cafeteriaA1->id)
        ->and($stored->provider_id)->toBe($this->providerA->id);
});

it('blocks an employee whose organization is not assigned to this cafeteria', function (): void {
    fakeEuisis();

    // A2 has no assignment for ORG-1.
    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA2);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('organization_not_assigned')
        ->and(CafeteriaServiceTransaction::query()->count())->toBe(0);
});

it('blocks service when the assignment is inactive', function (): void {
    $this->assignment->update(['status' => 'inactive']);
    fakeEuisis();

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA1);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('organization_not_assigned');
});

it('blocks service when the assignment has expired', function (): void {
    $this->assignment->update(['effective_to' => now()->subDay()->toDateString()]);
    fakeEuisis();

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA1);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('organization_not_assigned');
});

it('blocks service when the assignment has not started yet', function (): void {
    $this->assignment->update(['effective_from' => now()->addWeek()->toDateString()]);
    fakeEuisis();

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA1);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('organization_not_assigned');
});

it('blocks service when euisis returns no organization for the employee', function (): void {
    fakeEuisis(['organization' => null]);

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA1);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('organization_unknown');
});

// ── Card / employee / duplicate rules still apply ───────────────────────

it('blocks an inactive id card before checking the assignment', function (): void {
    fakeEuisis(['valid' => false, 'status' => 'revoked']);

    $result = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA1);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('card_revoked');
});

it('blocks a duplicate service at the same cafeteria on the same day', function (): void {
    fakeEuisis();

    $first = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA1);
    $second = $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA1);

    expect($first['served'])->toBeTrue()
        ->and($second['served'])->toBeFalse()
        ->and($second['result_code'])->toBe('already_served_today')
        ->and(CafeteriaServiceTransaction::query()->count())->toBe(1);
});

it('never stores the raw card token, only a hash', function (): void {
    fakeEuisis();
    $token = '019f0000-0000-7000-8000-0000000000aa';

    $this->service->serve($token, $this->cafeteriaA1);

    $stored = CafeteriaServiceTransaction::query()->firstOrFail();

    expect($stored->card_token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode($stored->toArray()))->not->toContain($token);
});

// ── User scope ──────────────────────────────────────────────────────────

it('gives a provider admin every cafeteria under their own provider only', function (): void {
    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin A', 'email' => 'admin-a@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $ids = $admin->accessibleCafeteriaIds();

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($this->cafeteriaA1->id)
        ->and($ids)->toContain($this->cafeteriaA2->id)
        // Never another provider's cafeteria.
        ->and($ids)->not->toContain($this->cafeteriaB1->id);
});

it('confines a cafeteria manager to the single cafeteria they are bound to', function (): void {
    $manager = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'cafeteria_id' => $this->cafeteriaA1->id,
        'name' => 'Manager', 'email' => 'manager@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_CAFETERIA_MANAGER, 'status' => 'active',
    ]);

    expect($manager->accessibleCafeteriaIds())->toBe([$this->cafeteriaA1->id])
        ->and($manager->canAccessCafeteria($this->cafeteriaA1->id))->toBeTrue()
        ->and($manager->canAccessCafeteria($this->cafeteriaA2->id))->toBeFalse()
        ->and($manager->canAccessCafeteria($this->cafeteriaB1->id))->toBeFalse();
});

it('confines a scanner to their own cafeteria and lets them serve', function (): void {
    $scanner = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'cafeteria_id' => $this->cafeteriaA1->id,
        'name' => 'Scanner', 'email' => 'scanner@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_SCANNER, 'status' => 'active',
    ]);

    expect($scanner->canServe())->toBeTrue()
        ->and($scanner->canManage())->toBeFalse()
        ->and($scanner->canAccessCafeteria($this->cafeteriaA2->id))->toBeFalse();
});

it('gives an unbound non-admin user no cafeteria access at all', function (): void {
    // Fail closed: a misconfigured account sees nothing rather than everything.
    $orphan = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Orphan', 'email' => 'orphan@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_SCANNER, 'status' => 'active',
    ]);

    expect($orphan->accessibleCafeteriaIds())->toBe([])
        ->and($orphan->canAccessCafeteria($this->cafeteriaA1->id))->toBeFalse();
});

it('denies a report viewer the ability to serve or manage', function (): void {
    $viewer = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'cafeteria_id' => $this->cafeteriaA1->id,
        'name' => 'Viewer', 'email' => 'viewer@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_REPORT_VIEWER, 'status' => 'active',
    ]);

    expect($viewer->canServe())->toBeFalse()
        ->and($viewer->canManage())->toBeFalse();
});

it('keeps transactions scoped to the serving provider', function (): void {
    fakeEuisis();
    $this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA1);

    $providerATransactions = CafeteriaServiceTransaction::query()
        ->where('provider_id', $this->providerA->id)->count();
    $providerBTransactions = CafeteriaServiceTransaction::query()
        ->where('provider_id', $this->providerB->id)->count();

    expect($providerATransactions)->toBe(1)
        ->and($providerBTransactions)->toBe(0);
});

it('allows the same organization to be assigned to a second cafeteria', function (): void {
    // Two service points can legitimately serve one organization.
    CafeteriaOrganizationAssignment::query()->create([
        'cafeteria_id' => $this->cafeteriaA2->id,
        'organization_code' => 'ORG-1',
        'organization_name_snapshot' => 'Bole Sub-city',
        'status' => 'active',
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    fakeEuisis();

    expect($this->service->serve('019f0000-0000-7000-8000-0000000000aa', $this->cafeteriaA2)['served'])->toBeTrue();
});

// ── Management endpoint isolation ───────────────────────────────────────

it('shows a provider admin only their own cafeterias', function (): void {
    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'a@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->get('/cafeterias')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $codes = collect($page->toArray()['props']['cafeterias'])->pluck('code');

            expect($codes)->toContain('CAF-A1')
                ->and($codes)->toContain('CAF-A2')
                // Provider B's cafeteria must never appear.
                ->and($codes)->not->toContain('CAF-B1');
        });
});

it('shows a cafeteria manager only their own cafeteria', function (): void {
    $manager = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'cafeteria_id' => $this->cafeteriaA1->id,
        'name' => 'Mgr', 'email' => 'm@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_CAFETERIA_MANAGER, 'status' => 'active',
    ]);

    $this->actingAs($manager, 'cafeteria')
        ->get('/cafeterias')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $codes = collect($page->toArray()['props']['cafeterias'])->pluck('code');

            expect($codes)->toHaveCount(1)->and($codes)->toContain('CAF-A1');
        });
});

it('forbids assigning an organization to another provider cafeteria', function (): void {
    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'a2@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->post('/assignments', [
            'cafeteria_id' => $this->cafeteriaB1->id, // belongs to provider B
            'organization_code' => 'ORG-9',
            'organization_name_snapshot' => 'Somewhere Else',
            'status' => 'active',
            'effective_from' => now()->toDateString(),
        ])
        ->assertForbidden();

    expect(CafeteriaOrganizationAssignment::query()->where('organization_code', 'ORG-9')->exists())->toBeFalse();
});

it('lets a provider admin assign an organization to their own cafeteria', function (): void {
    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'a3@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->post('/assignments', [
            'cafeteria_id' => $this->cafeteriaA2->id,
            'organization_code' => 'ORG-2',
            'organization_name_snapshot' => 'Kirkos Sub-city',
            'status' => 'active',
            'effective_from' => now()->toDateString(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(CafeteriaOrganizationAssignment::query()->where('organization_code', 'ORG-2')->exists())->toBeTrue();
});

it('never lists another provider users', function (): void {
    CafeteriaUser::query()->create([
        'provider_id' => $this->providerB->id,
        'cafeteria_id' => $this->cafeteriaB1->id,
        'name' => 'Rival Staff', 'email' => 'rival@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_SCANNER, 'status' => 'active',
    ]);

    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'a4@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->get('/users')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $emails = collect($page->toArray()['props']['users'])->pluck('email');

            expect($emails)->not->toContain('rival@test.local');
        });
});

it('forbids a scanner from creating cafeteria users', function (): void {
    $scanner = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'cafeteria_id' => $this->cafeteriaA1->id,
        'name' => 'Scanner', 'email' => 's2@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_SCANNER, 'status' => 'active',
    ]);

    $this->actingAs($scanner, 'cafeteria')
        ->post('/users', [
            'name' => 'Sneaky', 'email' => 'sneaky@test.local', 'password' => 'password123',
            'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
        ])
        ->assertForbidden();
});

// ── New sections: ledger, providers, settings ───────────────────────────

it('renders every cafeteria section for a provider admin', function (string $path, string $component): void {
    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'nav@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->get($path)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['/ledger', 'Ledger/Index'],
    ['/providers', 'Management/Providers'],
    ['/cafeteria-settings', 'Management/CafeteriaSettings'],
    ['/cafeterias', 'Management/Cafeterias'],
    ['/assignments', 'Management/Assignments'],
    ['/users', 'Management/Users'],
]);

it('shows a provider only their own provider record', function (): void {
    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'p@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->get('/providers')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $codes = collect($page->toArray()['props']['providers'])->pluck('code');

            expect($codes)->toHaveCount(1)
                ->and($codes)->toContain('PROV-A')
                ->and($codes)->not->toContain('PROV-B');
        });
});

it('forbids a scanner from saving cafeteria settings', function (): void {
    $scanner = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'cafeteria_id' => $this->cafeteriaA1->id,
        'name' => 'Scanner', 'email' => 'sc@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_SCANNER, 'status' => 'active',
    ]);

    $this->actingAs($scanner, 'cafeteria')
        ->patch('/cafeteria-settings', [
            'settings' => [['key' => 'default_daily_subsidy_amount', 'value' => '999']],
        ])
        ->assertForbidden();
});

it('saves cafeteria settings scoped to the provider', function (): void {
    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'set@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->patch('/cafeteria-settings', [
            'settings' => [
                ['key' => 'default_daily_subsidy_amount', 'value' => '55.00'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('cafeteria_settings', [
        'provider_id' => $this->providerA->id,
        'key' => 'default_daily_subsidy_amount',
        'value' => '55.00',
    ]);
});

it('keeps the subsidy ledger scoped to the acting provider', function (): void {
    CafeteriaSubsidyLedger::query()->create([
        'provider_id' => $this->providerA->id,
        'cafeteria_id' => $this->cafeteriaA1->id,
        'employee_number' => 'EMP-1', 'entry_type' => 'credit',
        'amount' => 100, 'balance_after' => 100, 'entry_date' => now()->toDateString(),
    ]);

    CafeteriaSubsidyLedger::query()->create([
        'provider_id' => $this->providerB->id,
        'cafeteria_id' => $this->cafeteriaB1->id,
        'employee_number' => 'EMP-RIVAL', 'entry_type' => 'credit',
        'amount' => 500, 'balance_after' => 500, 'entry_date' => now()->toDateString(),
    ]);

    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'led@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->get('/ledger')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $numbers = collect($page->toArray()['props']['entries']['data'])->pluck('employee_number');

            expect($numbers)->toContain('EMP-1')
                ->and($numbers)->not->toContain('EMP-RIVAL');
        });
});

it('exposes all nine settings tabs with their fields', function (): void {
    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'tabs@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->get('/cafeteria-settings')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];

            expect($props['tabs'])->toBe([
                'general', 'subsidy', 'days', 'scan', 'day-rules',
                'holidays', 'subsidy-rules', 'reports', 'provider-users',
            ]);

            // Every settings tab renders a non-empty field set.
            foreach (array_slice($props['tabs'], 0, 8) as $tab) {
                expect($props['groups'][$tab])->not->toBeEmpty();
            }

            // Defaults are merged in even with nothing saved yet.
            $general = collect($props['groups']['general'])->keyBy('key');
            expect($general['currency']['value'])->toBe('ETB')
                ->and($general['require_active_id_card']['type'])->toBe('boolean');
        });
});

it('rejects an unknown settings key', function (): void {
    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'badkey@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    // Guards against arbitrary rows being written into the settings table.
    $this->actingAs($admin, 'cafeteria')
        ->patch('/cafeteria-settings', [
            'settings' => [['key' => 'arbitrary_injected_key', 'value' => 'x']],
        ])
        ->assertSessionHasErrors('settings.0.key');
});

it('lists provider users on the provider users tab', function (): void {
    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'pu@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    CafeteriaUser::query()->create([
        'provider_id' => $this->providerB->id,
        'name' => 'Rival', 'email' => 'rival-pu@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_SCANNER, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->get('/cafeteria-settings')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $emails = collect($page->toArray()['props']['providerUsers'])->pluck('email');

            expect($emails)->toContain('pu@test.local')
                ->and($emails)->not->toContain('rival-pu@test.local');
        });
});

it('groups report periods on whichever database driver is active', function (string $granularity, string $pattern): void {
    // Regression: period bucketing used to_char(), which exists on PostgreSQL
    // but not on MySQL or SQLite. Each driver needs its own expression.
    CafeteriaServiceTransaction::query()->create([
        'transaction_number' => 'RPT-'.$granularity,
        'provider_id' => $this->providerA->id,
        'cafeteria_id' => $this->cafeteriaA1->id,
        'organization_code' => 'ORG-1',
        'employee_number' => 'EMP-RPT',
        'status' => 'served',
        'meal_amount' => 50,
        'subsidy_amount' => 30,
        'service_date' => now()->toDateString(),
        'served_at' => now(),
    ]);

    $rows = app(CafeteriaReportService::class)
        ->summary($this->providerA->id, now()->startOfMonth(), now()->endOfDay(), $granularity);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['period'])->toMatch($pattern)
        ->and($rows[0]['transactions'])->toBe(1)
        ->and($rows[0]['total_amount'])->toBe(50.0)
        ->and($rows[0]['total_subsidy'])->toBe(30.0);
})->with([
    ['day', '/^\d{4}-\d{2}-\d{2}$/'],
    ['week', '/^\d{4}-W?\d{2}$/'],
    ['month', '/^\d{4}-\d{2}$/'],
]);

it('proxies the euisis organization directory to the assignment form', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/organizations*' => Http::response([
            'data' => [
                ['id' => 'org-uuid-1', 'code' => 'ORG-1', 'name_en' => 'Bole Sub-city', 'name_am' => null, 'status' => 'active', 'type' => ['code' => 'SUBCITY', 'name_en' => 'Sub-city']],
            ],
        ], 200),
    ]);

    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'lookup@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    $this->actingAs($admin, 'cafeteria')
        ->getJson('/assignments/organization-lookup?search=Bole')
        ->assertOk()
        ->assertJsonPath('organizations.0.code', 'ORG-1')
        ->assertJsonPath('organizations.0.name_en', 'Bole Sub-city')
        ->assertJsonPath('error', null);
});

it('returns an empty directory with a reason when euisis is unreachable', function (): void {
    Http::fake([
        'https://euisis.test/*' => fn () => throw new ConnectionException('down'),
    ]);

    $admin = CafeteriaUser::query()->create([
        'provider_id' => $this->providerA->id,
        'name' => 'Admin', 'email' => 'lookup2@test.local', 'password' => 'password',
        'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN, 'status' => 'active',
    ]);

    // Fails soft: the form falls back to manual code entry.
    $this->actingAs($admin, 'cafeteria')
        ->getJson('/assignments/organization-lookup')
        ->assertOk()
        ->assertJsonPath('organizations', [])
        ->assertJsonPath('error', 'connection_failed');
});

it('requires authentication for the organization lookup', function (): void {
    $this->getJson('/assignments/organization-lookup')->assertUnauthorized();
});
