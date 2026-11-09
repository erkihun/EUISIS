<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;
use Modules\Entitlements\Domain\Models\ServiceType;
use Modules\Providers\Contracts\Data\ProviderPortalEntry;
use Modules\Providers\Domain\Models\Provider;
use Modules\Providers\Domain\Models\ProviderType;
use Modules\Providers\Domain\Models\ProviderUser;

/*
 * Cafeteria and transport providers each sign in on their own page, with its
 * own look, into the one `provider` guard. A door admits only accounts whose
 * provider runs its service; a refusal at the wrong door counts toward the
 * lockout like a wrong password. Sign-out, expiry and bounced visits return
 * to the portal the visitor uses, and /provider/portal/login asks which.
 */

beforeEach(function (): void {
    // Exercises a module that is feature-flagged off in production (config/features.php).
    config(['features.transport' => true]);
});

/** @param list<string> $services */
function splitLoginAccount(array $services): ProviderUser
{
    $providerType = ProviderType::query()->firstOrCreate(['code' => 'GENERAL'], ['name_en' => 'General', 'is_active' => true]);

    $provider = Provider::query()->create([
        'provider_code' => 'PRV-'.Str::upper(Str::random(6)),
        'provider_type_id' => $providerType->id,
        'name_en' => 'Split Login Provider',
        'status' => 'active',
    ]);

    foreach ($services as $code) {
        $serviceType = ServiceType::query()->firstOrCreate(['code' => $code], ['name_en' => Str::title($code), 'is_active' => true]);

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
            'name_en' => 'Split Login Cafeteria',
            'is_active' => true,
        ]);
    }

    return ProviderUser::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Split Operator',
        'email' => 'split-operator@example.test',
        'username' => 'split-operator',
        'password' => Hash::make('password'),
        'provider_role' => 'operator',
        'status' => 'active',
        'portal_enabled' => true,
    ]);
}

function splitSignIn(string $portal, string $password = 'password'): TestResponse
{
    return test()->post(route("provider.portal.{$portal}.login.store"), [
        'identifier' => 'split-operator',
        'password' => $password,
    ]);
}

// ── The two pages ────────────────────────────────────────────────────────────

it('gives the cafeteria and transport their own sign-in pages', function (string $portal, string $component): void {
    $this->get(route("provider.portal.{$portal}.login"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component("ProviderPortal/Auth/{$component}"));
})->with([
    'cafeteria' => ['cafeteria', 'CafeteriaLogin'],
    'transport' => ['transport', 'TransportLogin'],
]);

it('sends the /cafe shortcut and the legacy cafeteria address to the cafeteria sign-in', function (): void {
    $this->get('/cafe')->assertRedirect(route('provider.portal.cafeteria.login'));
    $this->get('/cafeteria/portal/login')->assertRedirect(route('provider.portal.cafeteria.login'));
});

it('asks which portal at the general address, the last one used first', function (): void {
    $this->get(route('provider.portal.login'))
        ->assertInertia(fn (Assert $page) => $page->component('ProviderPortal/Auth/ChoosePortal')->where('lastPortal', null));

    $this->withCookie(ProviderPortalEntry::COOKIE, 'transport')
        ->get(route('provider.portal.login'))
        ->assertInertia(fn (Assert $page) => $page->where('lastPortal', 'transport'));
});

// ── Who each door admits ─────────────────────────────────────────────────────

it('signs a provider in at its own door and lands on that portal\'s dashboard', function (array $services, string $portal, string $home): void {
    $user = splitLoginAccount($services);

    splitSignIn($portal)
        ->assertRedirect(route($home))
        ->assertCookie(ProviderPortalEntry::COOKIE, $portal);

    $this->assertAuthenticatedAs($user, 'provider');
})->with([
    'cafeteria at the cafeteria door' => [['cafeteria'], 'cafeteria', 'provider.portal.dashboard'],
    'transport at the transport door' => [['transport'], 'transport', 'provider.portal.transport.dashboard'],
    'both services, cafeteria door' => [['cafeteria', 'transport'], 'cafeteria', 'provider.portal.dashboard'],
    'both services, transport door' => [['cafeteria', 'transport'], 'transport', 'provider.portal.transport.dashboard'],
]);

it('refuses an account at the door of a service its provider does not run, and points to the other', function (array $services, string $portal): void {
    splitLoginAccount($services);

    $this->from(route("provider.portal.{$portal}.login"))
        ->post(route("provider.portal.{$portal}.login.store"), ['identifier' => 'split-operator', 'password' => 'password'])
        ->assertRedirect(route("provider.portal.{$portal}.login"))
        ->assertSessionHasErrors(['identifier' => __("provider-portal.wrong_portal_{$portal}")]);

    $this->assertGuest('provider');
})->with([
    'transport account at the cafeteria door' => [['transport'], 'cafeteria'],
    'cafeteria account at the transport door' => [['cafeteria'], 'transport'],
]);

it('answers a wrong password the same at either door, revealing no service', function (string $portal): void {
    splitLoginAccount(['cafeteria']);

    splitSignIn($portal, 'wrong-password')->assertSessionHasErrors(['identifier' => __('provider-portal.login_failed')]);
    $this->assertGuest('provider');
})->with(['cafeteria', 'transport']);

it('counts a wrong-door refusal toward the lockout, so the door is no password oracle', function (): void {
    // The route's per-IP request throttle is a separate guard; this is about the account lockout.
    $this->withoutMiddleware(ThrottleRequests::class);
    splitLoginAccount(['transport']);

    foreach (range(1, 5) as $attempt) {
        splitSignIn('cafeteria')->assertSessionHasErrors('identifier');
    }

    // Locked now: even the right door with the right password is refused.
    splitSignIn('transport')->assertSessionHasErrors('identifier');
    $this->assertGuest('provider');
});

it('sends a signed-in provider opening either sign-in to their portal home', function (string $portal): void {
    $user = splitLoginAccount(['transport']);

    $this->actingAs($user, 'provider')
        ->get(route("provider.portal.{$portal}.login"))
        ->assertRedirect(route('provider.portal.transport.dashboard'));
})->with(['cafeteria', 'transport']);

// ── Getting back to the right door ───────────────────────────────────────────

it('sends a guest bounced from a portal page to that portal\'s sign-in', function (string $page, string $login): void {
    $this->get(route($page))->assertRedirect(route($login));
})->with([
    'transport trips' => ['provider.portal.transport.trips.index', 'provider.portal.transport.login'],
    'cafeteria dashboard' => ['provider.portal.dashboard', 'provider.portal.cafeteria.login'],
    'cafeteria menus' => ['provider.portal.menus.index', 'provider.portal.cafeteria.login'],
    'shared profile, no portal remembered' => ['provider.portal.profile.show', 'provider.portal.login'],
]);

it('sends a guest bounced from a shared page to the portal this browser last used', function (): void {
    $this->withCookie(ProviderPortalEntry::COOKIE, 'transport')
        ->get(route('provider.portal.profile.show'))
        ->assertRedirect(route('provider.portal.transport.login'));
});

it('returns a provider to the sign-in of the portal they use when they sign out', function (?string $remembered, string $login): void {
    $user = splitLoginAccount(['cafeteria', 'transport']);

    $request = $this->actingAs($user, 'provider');
    if ($remembered !== null) {
        $request = $request->withCookie(ProviderPortalEntry::COOKIE, $remembered);
    }

    $request->post(route('provider.portal.logout'))->assertRedirect(route($login));
    $this->assertGuest('provider');
})->with([
    'transport' => ['transport', 'provider.portal.transport.login'],
    'cafeteria' => ['cafeteria', 'provider.portal.cafeteria.login'],
    'none remembered' => [null, 'provider.portal.login'],
]);

it('ignores a remembered portal it does not know', function (): void {
    $this->withCookie(ProviderPortalEntry::COOKIE, 'admin')
        ->get(route('provider.portal.profile.show'))
        ->assertRedirect(route('provider.portal.login'));
});
