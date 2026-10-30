<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;
use Modules\Entitlements\Domain\Models\ServiceType;
use Modules\Providers\Domain\Models\Provider;
use Modules\Providers\Domain\Models\ProviderType;
use Modules\Providers\Domain\Models\ProviderUser;

function createPortalUserWithProvider(): array
{
    $serviceType = ServiceType::query()->firstOrCreate(
        ['code' => 'cafeteria'],
        ['name_en' => 'Cafeteria Service', 'is_active' => true],
    );

    $providerType = ProviderType::query()->firstOrCreate(
        ['code' => 'CAFETERIA'],
        ['name_en' => 'Cafeteria', 'is_active' => true],
    );

    $generalProvider = Provider::query()->create([
        'provider_code' => 'TST-CAF',
        'provider_type_id' => $providerType->id,
        'name_en' => 'Test Cafeteria',
        'status' => 'active',
    ]);

    $provider = CafeteriaProvider::query()->create([
        'provider_id' => $generalProvider->id,
        'code' => 'TST-CAF',
        'name_en' => 'Test Cafeteria',
        'is_active' => true,
    ]);

    DB::table('provider_services')->insert([
        'id' => (string) Str::uuid7(),
        'provider_id' => $generalProvider->id,
        'service_type_id' => $serviceType->id,
        'status' => 'active',
        'enabled_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $providerUser = ProviderUser::query()->create([
        'provider_id' => $generalProvider->id,
        'name' => 'Portal Operator',
        'email' => 'portal-operator@example.test',
        'username' => 'portal-operator',
        'password' => Hash::make('password'),
        'provider_role' => 'operator',
        'status' => 'active',
        'portal_enabled' => true,
    ]);

    return [$providerUser, $provider];
}

test('provider login renders the general provider portal login page', function (): void {
    $this->get(route('provider.portal.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ProviderPortal/Auth/Login')
        );
});

test('provider dashboard requires provider authentication', function (): void {
    $this->get(route('provider.portal.dashboard'))
        ->assertRedirect(route('provider.portal.login'));
});

test('legacy cafeteria portal login redirects to provider portal login', function (): void {
    $this->get(route('cafeteria.portal.login'))
        ->assertRedirect(route('provider.portal.login'));
});

test('provider-only system user is redirected away from admin dashboard', function (): void {
    $user = User::factory()->create([
        'status' => 'active',
        'user_type' => 'provider',
        'provider_portal_enabled' => true,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('provider.portal.dashboard'));
});

test('provider user can access provider dashboard with provider portal context', function (): void {
    [$user, $provider] = createPortalUserWithProvider();

    $this->actingAs($user, 'provider')
        ->get(route('provider.portal.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Cafeteria/Portal/Dashboard')
            ->where('providerPortal.isPortal', true)
            ->where('selected_provider_id', $provider->id)
        );
});
