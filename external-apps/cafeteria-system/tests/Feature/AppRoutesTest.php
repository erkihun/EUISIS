<?php

declare(strict_types=1);

use CafeteriaSystem\Models\Cafeteria;
use CafeteriaSystem\Models\CafeteriaOrganizationAssignment;
use CafeteriaSystem\Models\CafeteriaProvider;
use CafeteriaSystem\Models\CafeteriaServiceTransaction;
use CafeteriaSystem\Models\CafeteriaUser;
use Illuminate\Support\Facades\Http;

/**
 * End-to-end HTTP coverage: auth guard, page rendering and the scan endpoint.
 */
beforeEach(function (): void {
    config()->set('euisis.base_url', 'https://euisis.test');
    config()->set('euisis.token', 'test-token');

    $this->provider = CafeteriaProvider::query()->create([
        'code' => 'CAF-TEST',
        'name' => 'Test Cafeteria',
        'status' => 'active',
    ]);

    $this->cafeteria = Cafeteria::query()->create([
        'provider_id' => $this->provider->id,
        'name' => 'Test Cafeteria Point',
        'code' => 'CAF-TP',
        'status' => 'active',
    ]);

    CafeteriaOrganizationAssignment::query()->create([
        'cafeteria_id' => $this->cafeteria->id,
        'organization_code' => 'ORG-1',
        'organization_name_snapshot' => 'Bole Sub-city',
        'status' => 'active',
        'effective_from' => now()->subMonth()->toDateString(),
    ]);

    $this->operator = CafeteriaUser::query()->create([
        'provider_id' => $this->provider->id,
        'cafeteria_id' => $this->cafeteria->id,
        'name' => 'Test Operator',
        'email' => 'operator@test.local',
        'password' => 'password',
        'role' => 'operator',
        'status' => 'active',
    ]);
});

it('redirects a guest to the login page', function (): void {
    $this->get('/')->assertRedirect('/login');
});

it('renders the login page', function (): void {
    $this->get('/login')->assertOk();
});

it('signs in a cafeteria operator', function (): void {
    $this->post('/login', [
        'email' => 'operator@test.local',
        'password' => 'password',
    ])->assertRedirect('/');

    $this->assertAuthenticatedAs($this->operator, 'cafeteria');
});

it('rejects a wrong password', function (): void {
    $this->post('/login', [
        'email' => 'operator@test.local',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('cafeteria');
});

it('refuses a suspended operator', function (): void {
    $this->operator->update(['status' => 'suspended']);

    $this->post('/login', [
        'email' => 'operator@test.local',
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('cafeteria');
});

it('renders every authenticated page', function (string $path, string $component): void {
    $this->actingAs($this->operator, 'cafeteria')
        ->get($path)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['/', 'Dashboard/Index'],
    ['/scan', 'Scan/Index'],
    ['/transactions', 'Transactions/Index'],
    ['/reports', 'Reports/Index'],
    ['/settlements', 'Settlements/Index'],
    ['/api-logs', 'ApiLogs/Index'],
    ['/settings', 'Settings/Index'],
]);

it('never sends the api token to the browser', function (): void {
    config()->set('euisis.token', 'super-secret-token-value');

    $response = $this->actingAs($this->operator, 'cafeteria')->get('/settings');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->where('integration.token_configured', true));

    expect($response->getContent())->not->toContain('super-secret-token-value');
});

it('serves an eligible employee through the scan endpoint', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response([
            'valid' => true,
            'status' => 'active',
            'employee_id' => '019f0000-0000-7000-8000-000000000001',
            'employee' => ['employee_number' => 'EMP-1', 'full_name' => 'Abebe Bekele', 'status' => 'active'],
            'organization' => ['code' => 'ORG-1', 'name_en' => 'Bole Sub-city'],
            'position' => ['title_en' => 'HR Officer'],
        ], 200),
        'https://euisis.test/api/v1/employees/*/service-eligibility*' => Http::response(['eligible' => true], 200),
    ]);

    $this->actingAs($this->operator, 'cafeteria')
        ->postJson('/scan/verify', ['card_token' => 'https://euisis.test/verify/card/019f0000-0000-7000-8000-0000000000aa'])
        ->assertOk()
        ->assertJsonPath('served', true)
        ->assertJsonPath('employee.employee_number', 'EMP-1');
});

it('blocks the scan endpoint for an inactive card', function (): void {
    Http::fake([
        'https://euisis.test/api/v1/id-cards/verify/*' => Http::response([
            'valid' => false,
            'status' => 'revoked',
            'employee' => ['employee_number' => 'EMP-1', 'full_name' => 'Abebe Bekele', 'status' => 'active'],
        ], 200),
    ]);

    $this->actingAs($this->operator, 'cafeteria')
        ->postJson('/scan/verify', ['card_token' => '019f0000-0000-7000-8000-0000000000aa'])
        ->assertStatus(422)
        ->assertJsonPath('served', false)
        ->assertJsonPath('result_code', 'card_revoked');
});

it('requires authentication for the scan endpoint', function (): void {
    $this->postJson('/scan/verify', ['card_token' => 'x'])->assertUnauthorized();
});

it('seeds the scan page with today served list', function (): void {
    CafeteriaServiceTransaction::query()->create([
        'transaction_number' => 'CAF-TODAY-1',
        'provider_id' => $this->provider->id,
        'cafeteria_id' => $this->cafeteria->id,
        'employee_number' => 'EMP-9',
        'employee_name' => 'Kebede Alemu',
        'status' => 'served',
        'served_at' => now(),
    ]);

    $this->actingAs($this->operator, 'cafeteria')
        ->get('/scan')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Scan/Index')
            ->where('today_scans.0.employee_number', 'EMP-9')
            ->where('today_scans.0.transaction_number', 'CAF-TODAY-1')
        );
});

it('gives the scan terminal its provider context', function (): void {
    $this->actingAs($this->operator, 'cafeteria')
        ->get('/scan')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Scan/Index')
            ->where('provider.code', 'CAF-TEST')
            ->where('provider.name', 'Test Cafeteria')
        );
});
