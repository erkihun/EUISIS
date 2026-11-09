<?php

declare(strict_types=1);

use App\Models\ApiEndpointDefinition;
use App\Models\ExternalApplication;
use App\Models\User;
use App\Services\ApiEndpointCatalogService;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
| TD-16: API tokens carry their own expiry. A partner application may set a
| lifetime in days (court-ms and the cafeteria platform cannot re-issue a
| token every 8 hours); a token with no expiry of its own still stops after
| the default 8 hours, and none outlives its application's approval expiry.
*/

beforeEach(function (): void {
    app(ApiEndpointCatalogService::class)->sync();
});

function lifetimeApplication(array $attributes = []): ExternalApplication
{
    $application = ExternalApplication::query()->create([
        'name' => 'Connected system', 'code' => 'LIFETIME-'.Str::random(6), 'status' => 'active',
        'allowed_scopes' => ['organizations.read'], 'rate_limit_per_minute' => 120, 'organization_access' => 'city',
        ...$attributes,
    ]);
    $endpoints = ApiEndpointDefinition::query()->where('required_scope', 'organizations.read')->pluck('id');
    $application->endpoints()->sync($endpoints->mapWithKeys(fn (string $id): array => [$id => ['id' => (string) Str::uuid(), 'is_enabled' => true]])->all());

    return $application;
}

function lifetimeCall(object $test, string $token): int
{
    // A fresh guard per call: the test client would otherwise keep the first resolved user.
    app('auth')->forgetGuards();

    return $test->withToken($token)->getJson('/api/v1/organizations/directory')->status();
}

it('stops a token without its own expiry after the default 8 hours, as before', function (): void {
    $token = lifetimeApplication()->createToken('legacy', ['organizations.read'])->plainTextToken;

    expect(lifetimeCall($this, $token))->toBe(200);
    $this->travel(7)->hours();
    expect(lifetimeCall($this, $token))->toBe(200);
    $this->travel(2)->hours();
    expect(lifetimeCall($this, $token))->toBe(401);
});

it('issues tokens that live the application lifetime, never past its approval expiry', function (): void {
    Permission::findOrCreate('api_management.tokens.create', 'web');
    $manager = User::factory()->create();
    $manager->givePermissionTo('api_management.tokens.create');

    $connected = lifetimeApplication(['token_lifetime_days' => 30]);
    $this->actingAs($manager)->post(route('api-management.tokens.store', $connected), ['name' => 'court-ms'])->assertSessionHasNoErrors();
    $issued = PersonalAccessToken::query()->where('tokenable_id', $connected->id)->sole();
    expect($issued->expires_at->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()))->toBeTrue();

    // No lifetime: the token is stamped with the 8-hour default.
    $plain = lifetimeApplication();
    $this->actingAs($manager)->post(route('api-management.tokens.store', $plain))->assertSessionHasNoErrors();
    expect(PersonalAccessToken::query()->where('tokenable_id', $plain->id)->sole()->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(479)->toBeLessThan(481);

    // The approval expiry caps the lifetime.
    $ending = lifetimeApplication(['token_lifetime_days' => 90, 'expires_at' => now()->addDays(10)->startOfDay()]);
    expect($ending->newTokenExpiry()->toDateString())->toBe(now()->addDays(10)->toDateString());
});

it('keeps a lifetime token working past 8 hours and until its own expiry', function (): void {
    $application = lifetimeApplication(['token_lifetime_days' => 30]);
    $token = $application->createToken('court-ms', ['organizations.read'], $application->newTokenExpiry())->plainTextToken;

    $this->travel(9)->hours();
    expect(lifetimeCall($this, $token))->toBe(200);
    $this->travel(29)->days();
    expect(lifetimeCall($this, $token))->toBe(200);
    $this->travel(2)->days();
    expect(lifetimeCall($this, $token))->toBe(401);
});

it('validates the lifetime on the application form', function (): void {
    foreach (['api_management.view', 'api_management.update'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Lifetime Manager', 'web')->givePermissionTo(['api_management.view', 'api_management.update']);
    $manager = User::factory()->create();
    $manager->assignRole('Lifetime Manager');
    $application = lifetimeApplication();
    $payload = ['name' => 'Connected system', 'code' => $application->code, 'status' => 'active', 'rate_limit_per_minute' => 120, 'allowed_scopes' => ['organizations.read']];

    $this->actingAs($manager->fresh())->patch(route('api-management.update', $application), [...$payload, 'token_lifetime_days' => 400])
        ->assertSessionHasErrors('token_lifetime_days');
    $this->actingAs($manager->fresh())->patch(route('api-management.update', $application), [...$payload, 'token_lifetime_days' => 90])
        ->assertSessionHasNoErrors();
    expect($application->fresh()->token_lifetime_days)->toBe(90);
});
