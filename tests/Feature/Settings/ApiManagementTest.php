<?php

declare(strict_types=1);

use App\Models\ApiRequestLog;
use App\Models\ExternalApplication;
use App\Models\User;
use App\Services\ApiEndpointCatalogService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $permissions = [
        'api_management.view', 'api_management.create', 'api_management.update',
        'api_management.delete', 'api_management.tokens.create',
        'api_management.tokens.revoke', 'api_management.logs.view', 'api_management.docs.view',
        'api_management.endpoints.view', 'api_management.endpoints.sync',
        'api_management.endpoints.update',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('API Manager', 'web')->givePermissionTo($permissions);
    // Organizational Admin deliberately receives no api_management.* permission.
    Role::findOrCreate('Organizational Admin', 'web');

    $this->application = ExternalApplication::query()->create([
        'name' => 'Partner System',
        'code' => 'PARTNER-1',
        'status' => 'active',
        'allowed_scopes' => ['id_cards.verify'],
        'rate_limit_per_minute' => 60,
    ]);
});

function apiManager(): User
{
    $user = User::factory()->create();
    $user->assignRole('API Manager');

    return $user->fresh();
}

it('forbids a guest from reaching api management', function (): void {
    $this->get(route('api-management.index'))->assertRedirect();
});

it('forbids a user without api management permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('api-management.index'))->assertForbidden();
});

it('forbids an organizational admin from managing global api settings', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Organizational Admin');

    $this->actingAs($user->fresh())->get(route('api-management.index'))->assertForbidden();
    $this->actingAs($user->fresh())->get(route('api-management.logs'))->assertForbidden();
    $this->actingAs($user->fresh())
        ->post(route('api-management.tokens.store', $this->application))
        ->assertForbidden();
});

it('lets an api manager view registered applications', function (): void {
    $this->actingAs(apiManager())
        ->get(route('api-management.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ApiManagement/Index')
            ->where('applications.0.code', 'PARTNER-1')
            ->has('scopes')
        );
});

it('lets an api manager register an external application', function (): void {
    $this->actingAs(apiManager())
        ->post(route('api-management.store'), [
            'name' => 'New Partner',
            'code' => 'PARTNER-2',
            'status' => 'active',
            'allowed_scopes' => ['id_cards.verify', 'service_eligibility.check'],
            'rate_limit_per_minute' => 120,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('external_applications', ['code' => 'PARTNER-2']);
});

it('rejects an unknown scope when registering', function (): void {
    $this->actingAs(apiManager())
        ->post(route('api-management.store'), [
            'name' => 'Bad Partner',
            'code' => 'PARTNER-3',
            'status' => 'active',
            'allowed_scopes' => ['employees.read_all'],
            'rate_limit_per_minute' => 60,
        ])
        ->assertSessionHasErrors('allowed_scopes.0');
});

it('shows a generated token exactly once and never stores it in plaintext', function (): void {
    $response = $this->actingAs(apiManager())
        ->post(route('api-management.tokens.store', $this->application))
        ->assertRedirect();

    $plain = session('flash.generated_token');
    expect($plain)->toBeString()->not->toBeEmpty();

    // Sanctum persists only a hash.
    $hash = hash('sha256', explode('|', $plain, 2)[1]);
    $this->assertDatabaseHas('personal_access_tokens', ['token' => $hash]);
    $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plain]);

    // The value lives only in the flash for the immediate next request.
    // Once that request is consumed, a fresh visit must not reveal it again.
    $this->followingRedirects();
    $this->get(route('api-management.show', $this->application))->assertOk();

    $this->actingAs(apiManager())
        ->get(route('api-management.show', $this->application))
        ->assertOk()
        ->assertDontSee($plain);
});

it('issues a token carrying only the approved scopes', function (): void {
    $this->actingAs(apiManager())->post(route('api-management.tokens.store', $this->application));

    $token = $this->application->tokens()->firstOrFail();

    expect($token->abilities)->toBe(['id_cards.verify']);
});

it('refuses to issue a token when no scopes are assigned', function (): void {
    $this->application->update(['allowed_scopes' => []]);

    $this->actingAs(apiManager())
        ->post(route('api-management.tokens.store', $this->application))
        ->assertRedirect();

    expect($this->application->tokens()->count())->toBe(0);
});

it('revokes a token so it can no longer authenticate', function (): void {
    $plain = $this->application->createToken('t', ['id_cards.verify'])->plainTextToken;
    $tokenId = $this->application->tokens()->firstOrFail()->getKey();

    $this->actingAs(apiManager())
        ->delete(route('api-management.tokens.destroy', [$this->application, $tokenId]))
        ->assertRedirect();

    expect($this->application->tokens()->count())->toBe(0)
        // The authoritative check: Sanctum can no longer resolve the token,
        // so it cannot authenticate any request.
        ->and(PersonalAccessToken::findToken($plain))->toBeNull();
});

it('revokes all tokens when the application is deleted', function (): void {
    $this->application->createToken('t', ['id_cards.verify']);

    $this->actingAs(apiManager())
        ->delete(route('api-management.destroy', $this->application))
        ->assertRedirect();

    expect($this->application->tokens()->count())->toBe(0);
});

it('lists api request logs without any payload data', function (): void {
    ApiRequestLog::query()->create([
        'external_application_id' => $this->application->id,
        'endpoint' => '/api/v1/id-cards/verify/abc',
        'method' => 'GET',
        'ip_address' => '10.0.0.1',
        'status_code' => 200,
        'success' => true,
        'requested_at' => now(),
    ]);

    $this->actingAs(apiManager())
        ->get(route('api-management.logs'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ApiManagement/Logs')
            ->where('logs.data.0.status_code', 200)
            ->where('logs.data.0.method', 'GET')
        );
});

it('serves the api documentation page', function (): void {
    $this->actingAs(apiManager())
        ->get(route('api-management.docs'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ApiManagement/Docs')->has('scopes'));
});

it('publishes every synced endpoint on the documentation page', function (): void {
    app(ApiEndpointCatalogService::class)->sync();

    $registered = collect(app('router')->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->map(fn ($route): string => '/'.$route->uri())
        ->unique();

    // Guards against the drift that made the hand-written guide go stale:
    // adding a route must surface it here without editing documentation.
    expect($registered)->not->toBeEmpty();

    $response = $this->actingAs(apiManager())->get(route('api-management.docs'));

    $documented = collect($response->viewData('page')['props']['groups'])
        ->flatten(1)
        ->pluck('uri');

    expect($documented->sort()->values()->all())
        ->toEqual($registered->sort()->values()->all());
});

it('shows the scope each endpoint requires', function (): void {
    app(ApiEndpointCatalogService::class)->sync();

    $response = $this->actingAs(apiManager())->get(route('api-management.docs'));

    $organizations = collect($response->viewData('page')['props']['groups'])
        ->flatten(1)
        ->firstWhere('uri', '/api/v1/organizations');

    expect($organizations)->not->toBeNull()
        ->and($organizations['method'])->toBe('GET')
        ->and($organizations['required_scope'])->toBe('reports.read_limited');
});

it('keeps the documentation page behind its permission', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Organizational Admin');

    $this->actingAs($user)->get(route('api-management.docs'))->assertForbidden();
});

it('lets an api manager update an application and its scopes', function (): void {
    $this->actingAs(apiManager())
        ->patch(route('api-management.update', $this->application), [
            'name' => 'Renamed Partner',
            'code' => 'PARTNER-1',
            'status' => 'suspended',
            'allowed_scopes' => ['id_cards.verify', 'service_eligibility.check'],
            'rate_limit_per_minute' => 200,
            'allowed_ips' => ['10.0.0.5'],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fresh = $this->application->fresh();

    expect($fresh->name)->toBe('Renamed Partner')
        ->and($fresh->status)->toBe('suspended')
        ->and($fresh->rate_limit_per_minute)->toBe(200)
        ->and($fresh->allowed_ips)->toBe(['10.0.0.5'])
        ->and($fresh->allowed_scopes)->toContain('service_eligibility.check');
});

it('rejects an invalid ip in the allowlist', function (): void {
    $this->actingAs(apiManager())
        ->patch(route('api-management.update', $this->application), [
            'name' => 'Partner System',
            'code' => 'PARTNER-1',
            'status' => 'active',
            'allowed_scopes' => ['id_cards.verify'],
            'rate_limit_per_minute' => 60,
            'allowed_ips' => ['not-an-ip'],
        ])
        ->assertSessionHasErrors('allowed_ips.0');
});

it('exposes the delete capability to the detail page', function (): void {
    $this->actingAs(apiManager())
        ->get(route('api-management.show', $this->application))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.delete', true)
            ->where('can.update', true)
        );
});

it('prunes api request logs older than the retention window', function (): void {
    ApiRequestLog::query()->create([
        'external_application_id' => $this->application->id,
        'endpoint' => '/api/v1/old', 'method' => 'GET',
        'status_code' => 200, 'success' => true,
        'requested_at' => now()->subDays(200),
    ]);

    ApiRequestLog::query()->create([
        'external_application_id' => $this->application->id,
        'endpoint' => '/api/v1/recent', 'method' => 'GET',
        'status_code' => 200, 'success' => true,
        'requested_at' => now()->subDay(),
    ]);

    $this->artisan('api:prune-logs', ['--days' => 90])->assertSuccessful();

    expect(ApiRequestLog::query()->count())->toBe(1)
        ->and(ApiRequestLog::query()->first()->endpoint)->toBe('/api/v1/recent');
});

it('renders api management as a settings tab beside security', function (): void {
    $page = file_get_contents(dirname(__DIR__, 3).'/resources/js/Pages/SystemSettings/Index.tsx');

    expect($page)
        // Inserted directly after the Security tab.
        ->toContain("findIndex((tab) => tab.id === 'security')")
        ->toContain("id: 'api_management'")
        ->toContain("href: route('api-management.index')")
        // Hidden unless the user may open the module.
        ->toContain('if (!can.apiManagement)');

    foreach (['en', 'am'] as $locale) {
        $settings = file_get_contents(dirname(__DIR__, 3)."/resources/js/i18n/{$locale}/settings.ts");
        expect($settings)->toContain('api_management:');
    }
});

it('shares the api management capability with the settings page', function (): void {
    Permission::findOrCreate('system-settings.view', 'web');
    $user = apiManager();
    $user->givePermissionTo('system-settings.view');

    $this->actingAs($user->fresh())
        ->get(route('system-settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.apiManagement', true));
});

it('hides the api management tab from a user without the permission', function (): void {
    Permission::findOrCreate('system-settings.view', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo('system-settings.view');

    $this->actingAs($user->fresh())
        ->get(route('system-settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.apiManagement', false));
});

it('offers edit and delete controls on the application detail page', function (): void {
    $page = file_get_contents(dirname(__DIR__, 3).'/resources/js/Pages/ApiManagement/Show.tsx');

    expect($page)
        ->toContain("route('api-management.update', application.id)")
        ->toContain("route('api-management.destroy', application.id)")
        // Destructive actions are confirmed, not one-click.
        ->toContain('handleDelete')
        ->toContain('handleRevoke')
        ->toContain("t('apiManagement.deleteWarning')");
});

it('grants api management permissions to the seeded administrative roles', function (): void {
    // Guards the failure that hid the menu: the permission existed but no role
    // held it, so `can('api_management.view')` was false for every user.
    // Drop the fixtures created in beforeEach so this asserts on what the
    // seeders actually produce, not on roles this test made itself.
    Role::query()->whereIn('name', ['API Manager', 'Organizational Admin'])->delete();

    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    foreach (['Super Admin', 'System Admin', 'API Manager'] as $roleName) {
        $role = Role::query()->where('name', $roleName)->first();

        expect($role)->not->toBeNull("role {$roleName} should be seeded")
            ->and($role->hasPermissionTo('api_management.view'))->toBeTrue(
                "{$roleName} should hold api_management.view"
            );
    }

    // Scoped roles must NOT receive global API administration.
    $scoped = Role::query()->where('name', 'Organizational Admin')->first();
    expect($scoped?->hasPermissionTo('api_management.view'))->toBeFalse();
});

it('shares api management permission with the frontend for an authorized user', function (): void {
    $user = apiManager();

    // HandleInertiaRequests shares a flat permission-name array; the sidebar
    // filter reads exactly this.
    expect($user->getAllPermissions()->pluck('name'))->toContain('api_management.view');
});

it('loads the index with a token count without a type mismatch', function (): void {
    // Regression: personal_access_tokens.tokenable_id is VARCHAR (it holds both
    // bigint user ids and UUID application ids). If external_applications.id
    // stays a native uuid, PostgreSQL rejects the polymorphic join with
    // "operator does not exist: uuid = character varying" and the page 500s.
    $this->application->createToken('t', ['id_cards.verify']);

    $this->actingAs(apiManager())
        ->get(route('api-management.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('applications.0.tokens_count', 1));
});

it('resolves an issued token back to its owning application', function (): void {
    $plain = $this->application->createToken('t', ['id_cards.verify'])->plainTextToken;

    $resolved = PersonalAccessToken::findToken($plain);

    expect($resolved)->not->toBeNull()
        ->and($resolved->tokenable?->is($this->application))->toBeTrue();
});

it('records the creating user without a key-type mismatch', function (): void {
    // Regression: created_by was declared UUID while users.id is BIGINT, so
    // PostgreSQL rejected the insert with "invalid input syntax for type uuid".
    $manager = apiManager();

    $this->actingAs($manager)
        ->post(route('api-management.store'), [
            'name' => 'Cafeteria System',
            'code' => 'CAFETERIA',
            'status' => 'active',
            'allowed_scopes' => [
                'id_cards.verify', 'employees.basic_verify',
                'service_eligibility.check', 'service_transactions.create',
            ],
            'rate_limit_per_minute' => 60,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $created = ExternalApplication::query()->where('code', 'CAFETERIA')->firstOrFail();

    expect((string) $created->created_by)->toBe((string) $manager->getKey())
        ->and($created->allowed_scopes)->toHaveCount(4);
});
