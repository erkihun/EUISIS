<?php

declare(strict_types=1);

use App\Models\CafeteriaProvider;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\ProviderUser;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A provider-portal account whose provider runs the given services.
 *
 * @param  list<string>  $services
 */
function providerLoginAccount(array $services, array $overrides = []): ProviderUser
{
    $providerType = ProviderType::query()->firstOrCreate(
        ['code' => 'GENERAL'],
        ['name_en' => 'General', 'is_active' => true],
    );

    $provider = Provider::query()->create([
        'provider_code' => 'PRV-'.Str::upper(Str::random(6)),
        'provider_type_id' => $providerType->id,
        'name_en' => 'Login Test Provider',
        'status' => 'active',
    ]);

    foreach ($services as $code) {
        $serviceType = ServiceType::query()->firstOrCreate(
            ['code' => $code],
            ['name_en' => Str::title($code), 'is_active' => true],
        );

        DB::table('provider_services')->insert([
            'id' => (string) Str::uuid7(),
            'provider_id' => $provider->id,
            'service_type_id' => $serviceType->id,
            'status' => 'active',
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    if (in_array('cafeteria', $services, true)) {
        CafeteriaProvider::query()->create([
            'provider_id' => $provider->id,
            'code' => $provider->provider_code,
            'name_en' => 'Login Test Cafeteria',
            'is_active' => true,
        ]);
    }

    return ProviderUser::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Login Operator',
        'email' => 'login-operator@example.test',
        'username' => 'login-operator',
        'password' => Hash::make('password'),
        'provider_role' => 'operator',
        'status' => 'active',
        'portal_enabled' => true,
        ...$overrides,
    ]);
}

test('the login page renders for guests', function (): void {
    $this->get(route('provider.portal.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ProviderPortal/Auth/Login'));
});

test('a cafeteria provider signs in with email and lands on the cafeteria dashboard', function (): void {
    $user = providerLoginAccount(['cafeteria']);

    $this->post(route('provider.portal.login.store'), [
        'identifier' => 'login-operator@example.test',
        'password' => 'password',
    ])->assertRedirect(route('provider.portal.dashboard'));

    $this->assertAuthenticatedAs($user, 'provider');
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('a provider can sign in with a username', function (): void {
    $user = providerLoginAccount(['cafeteria']);

    $this->post(route('provider.portal.login.store'), [
        'identifier' => 'login-operator',
        'password' => 'password',
    ])->assertRedirect(route('provider.portal.dashboard'));

    $this->assertAuthenticatedAs($user, 'provider');
});

test('a transport-only provider lands on the transport dashboard', function (): void {
    $user = providerLoginAccount(['transport']);

    $this->post(route('provider.portal.login.store'), [
        'identifier' => 'login-operator',
        'password' => 'password',
    ])->assertRedirect(route('provider.portal.transport.dashboard'));

    $this->assertAuthenticatedAs($user, 'provider');
});

test('a wrong password is rejected on the identifier field', function (): void {
    providerLoginAccount(['cafeteria']);

    $this->from(route('provider.portal.login'))
        ->post(route('provider.portal.login.store'), [
            'identifier' => 'login-operator',
            'password' => 'wrong-password',
        ])
        ->assertRedirect(route('provider.portal.login'))
        ->assertSessionHasErrors(['identifier' => __('provider-portal.login_failed')]);

    $this->assertGuest('provider');
});

test('an account with portal access disabled cannot sign in', function (): void {
    providerLoginAccount(['cafeteria'], ['portal_enabled' => false]);

    $this->from(route('provider.portal.login'))
        ->post(route('provider.portal.login.store'), [
            'identifier' => 'login-operator',
            'password' => 'password',
        ])
        ->assertSessionHasErrors(['identifier' => __('provider-portal.portal_disabled')]);

    $this->assertGuest('provider');
});

test('a signed-in provider opening the login page is sent to their portal home', function (array $services, string $home): void {
    $user = providerLoginAccount($services);

    $this->actingAs($user, 'provider')
        ->get(route('provider.portal.login'))
        ->assertRedirect(route($home));
})->with([
    'cafeteria' => [['cafeteria'], 'provider.portal.dashboard'],
    'transport only' => [['transport'], 'provider.portal.transport.dashboard'],
]);

test('a signed-in provider opening the legacy cafeteria login is sent to their portal home', function (): void {
    $user = providerLoginAccount(['transport']);

    $this->actingAs($user, 'provider')
        ->get(route('cafeteria.portal.login'))
        ->assertRedirect(route('provider.portal.transport.dashboard'));
});

test('a provider bounced from a portal page returns to it after signing in', function (): void {
    providerLoginAccount(['transport']);

    $this->get(route('provider.portal.transport.trips.index'))
        ->assertRedirect(route('provider.portal.login'));

    $this->post(route('provider.portal.login.store'), [
        'identifier' => 'login-operator',
        'password' => 'password',
    ])->assertRedirect(route('provider.portal.transport.trips.index'));
});

test('an intended URL outside the provider portal is not followed', function (): void {
    providerLoginAccount(['cafeteria']);

    $this->withSession(['url.intended' => route('dashboard')])
        ->post(route('provider.portal.login.store'), [
            'identifier' => 'login-operator',
            'password' => 'password',
        ])
        ->assertRedirect(route('provider.portal.dashboard'));
});

test('staff visiting the staff login are still sent to the admin dashboard', function (): void {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});
