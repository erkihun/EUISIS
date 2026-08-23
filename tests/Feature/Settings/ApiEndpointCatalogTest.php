<?php

declare(strict_types=1);

use App\Models\ApiEndpointDefinition;
use App\Models\ApiRequestLog;
use App\Models\ExternalApplication;
use App\Models\User;
use App\Services\ApiEndpointCatalogService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $permissions = [
        'api_management.view', 'api_management.logs.view', 'api_management.docs.view',
        'api_management.endpoints.view', 'api_management.endpoints.sync',
        'api_management.endpoints.update',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('API Manager', 'web')->givePermissionTo($permissions);
    // Organizational Admin deliberately receives no api_management.* permission.
    Role::findOrCreate('Organizational Admin', 'web');
});

function endpointManager(): User
{
    $user = User::factory()->create();
    $user->assignRole('API Manager');

    return $user->fresh();
}

function organizationalAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Organizational Admin');

    return $user->fresh();
}

it('shows the endpoint catalog to an api manager', function (): void {
    app(ApiEndpointCatalogService::class)->sync();

    $this->actingAs(endpointManager())
        ->get(route('api-management.endpoints'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ApiManagement/Endpoints')
            ->has('endpoints')
            ->where('can.sync', true)
        );
});

it('denies the endpoint catalog to an organizational admin', function (): void {
    $this->actingAs(organizationalAdmin())
        ->get(route('api-management.endpoints'))
        ->assertForbidden();
});

it('denies endpoint sync to an organizational admin', function (): void {
    $this->actingAs(organizationalAdmin())
        ->post(route('api-management.endpoints.sync'))
        ->assertForbidden();

    expect(ApiEndpointDefinition::query()->count())->toBe(0);
});

it('grants the endpoint catalog to an organizational admin only when explicitly granted', function (): void {
    $user = organizationalAdmin();
    $user->givePermissionTo('api_management.endpoints.view');

    $this->actingAs($user->fresh())
        ->get(route('api-management.endpoints'))
        ->assertOk();
});

it('discovers the endpoints registered in routes/api.php', function (): void {
    $discovered = app(ApiEndpointCatalogService::class)->discover()->pluck('uri');

    expect($discovered)->toContain('/api/v1/organizations')
        ->and($discovered)->toContain('/api/v1/cards/verify')
        ->and($discovered)->toContain('/api/v1/id-cards/verify/{token}');
});

it('excludes non-integration and debug routes from the catalog', function (): void {
    $discovered = app(ApiEndpointCatalogService::class)->discover()->pluck('uri');

    // Web UI routes and profiling tools must never be advertised as API.
    foreach ($discovered as $uri) {
        expect($uri)->toStartWith('/api/v')
            ->and($uri)->not->toContain('telescope')
            ->and($uri)->not->toContain('horizon')
            ->and($uri)->not->toContain('_debugbar');
    }

    expect($discovered)->not->toContain('/system-settings/api-management');
});

it('records method, scope, auth, rate limit and version when syncing', function (): void {
    app(ApiEndpointCatalogService::class)->sync();

    $endpoint = ApiEndpointDefinition::query()->where('uri', '/api/v1/organizations')->sole();

    expect($endpoint->method)->toBe('GET')
        ->and($endpoint->required_scope)->toBe('reports.read_limited')
        ->and($endpoint->auth_required)->toBeTrue()
        ->and($endpoint->rate_limit)->toBe('api')
        ->and($endpoint->version)->toBe('v1')
        ->and($endpoint->status)->toBe(ApiEndpointDefinition::STATUS_ACTIVE)
        ->and($endpoint->controller_action)->toContain('OrganizationDirectoryController');
});

it('adds a newly registered endpoint on sync', function (): void {
    $catalog = app(ApiEndpointCatalogService::class);
    $catalog->sync();

    $before = ApiEndpointDefinition::query()->count();

    // Register a route after the first sync to imitate a new endpoint landing.
    Route::middleware(['auth:sanctum', 'api.scope:reports.read_limited'])
        ->get('api/v1/test-new-endpoint', fn () => response()->json([]))
        ->name('api.v1.test.new');

    $summary = $catalog->sync();

    expect($summary['created'])->toBe(1)
        ->and(ApiEndpointDefinition::query()->count())->toBe($before + 1);

    $created = ApiEndpointDefinition::query()->where('uri', '/api/v1/test-new-endpoint')->sole();

    expect($created->required_scope)->toBe('reports.read_limited')
        ->and($created->status)->toBe(ApiEndpointDefinition::STATUS_ACTIVE);
});

it('marks a removed endpoint as deprecated instead of deleting it', function (): void {
    // A catalog row with no matching route stands in for a removed endpoint.
    $stale = ApiEndpointDefinition::query()->create([
        'method' => 'GET',
        'uri' => '/api/v1/retired-endpoint',
        'route_name' => 'api.v1.retired',
        'status' => ApiEndpointDefinition::STATUS_ACTIVE,
    ]);

    $summary = app(ApiEndpointCatalogService::class)->sync();

    expect($summary['deprecated'])->toBe(1)
        // Deleting would orphan the request logs that reference it.
        ->and(ApiEndpointDefinition::query()->whereKey($stale->id)->exists())->toBeTrue()
        ->and($stale->fresh()->status)->toBe(ApiEndpointDefinition::STATUS_DEPRECATED);
});

it('reactivates a deprecated endpoint when its route returns', function (): void {
    $catalog = app(ApiEndpointCatalogService::class);
    $catalog->sync();

    $endpoint = ApiEndpointDefinition::query()->where('uri', '/api/v1/organizations')->sole();
    $endpoint->update(['status' => ApiEndpointDefinition::STATUS_DEPRECATED]);

    $catalog->sync();

    expect($endpoint->fresh()->status)->toBe(ApiEndpointDefinition::STATUS_ACTIVE);
});

it('preserves a curated description across a re-sync', function (): void {
    $catalog = app(ApiEndpointCatalogService::class);
    $catalog->sync();

    $endpoint = ApiEndpointDefinition::query()->where('uri', '/api/v1/organizations')->sole();
    $endpoint->update(['description' => 'Directory used by the cafeteria system.']);

    $catalog->sync();

    // An administrator's notes must survive syncing, or nobody would write any.
    expect($endpoint->fresh()->description)->toBe('Directory used by the cafeteria system.');
});

it('is idempotent when routes have not changed', function (): void {
    $catalog = app(ApiEndpointCatalogService::class);
    $catalog->sync();

    $summary = $catalog->sync();

    expect($summary['created'])->toBe(0)
        ->and($summary['updated'])->toBe(0)
        ->and($summary['deprecated'])->toBe(0);
});

it('lets an api manager assign a scope to an endpoint', function (): void {
    app(ApiEndpointCatalogService::class)->sync();
    $endpoint = ApiEndpointDefinition::query()->where('uri', '/api/v1/organizations')->sole();

    $this->actingAs(endpointManager())
        ->patch(route('api-management.endpoints.update', $endpoint), [
            'required_scope' => 'employees.basic_verify',
            'description' => 'Updated description.',
            'status' => ApiEndpointDefinition::STATUS_ACTIVE,
            'is_public_documented' => true,
        ])
        ->assertRedirect();

    expect($endpoint->fresh()->required_scope)->toBe('employees.basic_verify')
        ->and($endpoint->fresh()->description)->toBe('Updated description.');
});

it('rejects a scope that is not a known api scope', function (): void {
    app(ApiEndpointCatalogService::class)->sync();
    $endpoint = ApiEndpointDefinition::query()->where('uri', '/api/v1/organizations')->sole();

    $this->actingAs(endpointManager())
        ->patch(route('api-management.endpoints.update', $endpoint), [
            'required_scope' => 'made.up.scope',
            'status' => ApiEndpointDefinition::STATUS_ACTIVE,
            'is_public_documented' => true,
        ])
        ->assertSessionHasErrors('required_scope');
});

it('denies an endpoint update to a user without the update permission', function (): void {
    app(ApiEndpointCatalogService::class)->sync();
    $endpoint = ApiEndpointDefinition::query()->where('uri', '/api/v1/organizations')->sole();

    $viewer = organizationalAdmin();
    $viewer->givePermissionTo('api_management.endpoints.view');

    $this->actingAs($viewer->fresh())
        ->patch(route('api-management.endpoints.update', $endpoint), [
            'required_scope' => 'employees.basic_verify',
            'status' => ApiEndpointDefinition::STATUS_ACTIVE,
            'is_public_documented' => true,
        ])
        ->assertForbidden();

    expect($endpoint->fresh()->required_scope)->toBe('reports.read_limited');
});

it('shows an endpoint detail page with a placeholder token only', function (): void {
    app(ApiEndpointCatalogService::class)->sync();
    $endpoint = ApiEndpointDefinition::query()->where('uri', '/api/v1/organizations')->sole();

    $this->actingAs(endpointManager())
        ->get(route('api-management.endpoints.show', $endpoint))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ApiManagement/EndpointShow')
            ->where('endpoint.uri', '/api/v1/organizations')
            ->where('sampleRequest', fn (string $sample): bool => str_contains($sample, '<YOUR_API_TOKEN>') && ! str_contains($sample, 'euisis_'))
        );
});

it('shows recent request logs for an endpoint', function (): void {
    app(ApiEndpointCatalogService::class)->sync();
    $endpoint = ApiEndpointDefinition::query()->where('uri', '/api/v1/organizations')->sole();

    $application = ExternalApplication::query()->create([
        'name' => 'Cafeteria System',
        'code' => 'CAFE-1',
        'status' => 'active',
        'allowed_scopes' => ['reports.read_limited'],
        'rate_limit_per_minute' => 60,
    ]);

    ApiRequestLog::query()->create([
        'external_application_id' => $application->id,
        'endpoint' => '/api/v1/organizations',
        'method' => 'GET',
        'status_code' => 200,
        'success' => true,
        'requested_at' => now(),
    ]);

    $this->actingAs(endpointManager())
        ->get(route('api-management.endpoints.show', $endpoint))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentLogs', 1)
            ->where('recentLogs.0.status_code', 200)
        );
});

it('hides request logs from a user without the logs permission', function (): void {
    app(ApiEndpointCatalogService::class)->sync();
    $endpoint = ApiEndpointDefinition::query()->where('uri', '/api/v1/organizations')->sole();

    $viewer = organizationalAdmin();
    $viewer->givePermissionTo('api_management.endpoints.view');

    $this->actingAs($viewer->fresh())
        ->get(route('api-management.endpoints.show', $endpoint))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('recentLogs', 0)->where('can.viewLogs', false));
});

it('excludes an undocumented endpoint from the documentation page', function (): void {
    app(ApiEndpointCatalogService::class)->sync();

    ApiEndpointDefinition::query()
        ->where('uri', '/api/v1/organizations')
        ->update(['is_public_documented' => false]);

    $response = $this->actingAs(endpointManager())->get(route('api-management.docs'));

    $documented = collect($response->viewData('page')['props']['groups'])->flatten(1)->pluck('uri');

    expect($documented)->not->toContain('/api/v1/organizations');
});

it('excludes a deprecated endpoint from the documentation page', function (): void {
    app(ApiEndpointCatalogService::class)->sync();

    ApiEndpointDefinition::query()
        ->where('uri', '/api/v1/organizations')
        ->update(['status' => ApiEndpointDefinition::STATUS_DEPRECATED]);

    $response = $this->actingAs(endpointManager())->get(route('api-management.docs'));

    $documented = collect($response->viewData('page')['props']['groups'])->flatten(1)->pluck('uri');

    expect($documented)->not->toContain('/api/v1/organizations');
});

it('groups documented endpoints by function', function (): void {
    app(ApiEndpointCatalogService::class)->sync();

    $response = $this->actingAs(endpointManager())->get(route('api-management.docs'));
    $groups = $response->viewData('page')['props']['groups'];

    expect(collect($groups['id_card_verification'])->pluck('uri'))
        ->toContain('/api/v1/id-cards/verify/{token}')
        ->and(collect($groups['reports'])->pluck('uri'))
        ->toContain('/api/v1/organizations');
});

it('never exposes a token or database detail in the catalog payload', function (): void {
    app(ApiEndpointCatalogService::class)->sync();

    $payload = json_encode(
        $this->actingAs(endpointManager())
            ->get(route('api-management.endpoints'))
            ->viewData('page')['props']
    );

    expect($payload)->not->toContain('euisis_')
        ->and($payload)->not->toContain('DB_PASSWORD')
        ->and(strtolower((string) $payload))->not->toContain('mysql')
        ->and(strtolower((string) $payload))->not->toContain('pgsql');
});

it('records an audit entry when the catalog is synced', function (): void {
    $this->actingAs(endpointManager())
        ->post(route('api-management.endpoints.sync'))
        ->assertRedirect();

    expect(ApiEndpointDefinition::query()->count())->toBeGreaterThan(0);
});
