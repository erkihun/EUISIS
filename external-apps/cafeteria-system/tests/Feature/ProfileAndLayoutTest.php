<?php

declare(strict_types=1);

use CafeteriaSystem\Models\Cafeteria;
use CafeteriaSystem\Models\CafeteriaProvider;
use CafeteriaSystem\Models\CafeteriaUser;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->provider = CafeteriaProvider::query()->create([
        'code' => 'CAF-UI',
        'name' => 'Layout Cafe',
        'status' => 'active',
    ]);

    $this->cafeteria = Cafeteria::query()->create([
        'provider_id' => $this->provider->id,
        'name' => 'Layout Point',
        'code' => 'UI-TP',
        'status' => 'active',
    ]);
});

function makeUser(string $role, array $overrides = []): CafeteriaUser
{
    return CafeteriaUser::query()->create(array_merge([
        'provider_id' => test()->provider->id,
        'cafeteria_id' => test()->cafeteria->id,
        'name' => 'Test Person',
        'email' => $role.'@test.local',
        'password' => 'password',
        'role' => $role,
        'status' => 'active',
    ], $overrides));
}

// ── Shared layout props ────────────────────────────────────────────────

it('shares capabilities so the sidebar can hide unusable links', function (): void {
    $this->actingAs(makeUser(CafeteriaUser::ROLE_PROVIDER_ADMIN), 'cafeteria')
        ->get('/transactions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.manage', true)
            ->where('auth.can.serve', true)
        );
});

it('reports a scanner as unable to manage', function (): void {
    $this->actingAs(makeUser(CafeteriaUser::ROLE_SCANNER), 'cafeteria')
        ->get('/transactions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.manage', false)
            ->where('auth.can.serve', true)
        );
});

it('reports a report viewer as unable to serve', function (): void {
    $this->actingAs(makeUser(CafeteriaUser::ROLE_REPORT_VIEWER), 'cafeteria')
        ->get('/transactions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.serve', false)
            ->where('auth.can.manage', false)
        );
});

it('shares the serving context for the sidebar', function (): void {
    $this->actingAs(makeUser(CafeteriaUser::ROLE_SCANNER), 'cafeteria')
        ->get('/transactions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.context.cafeteria.name', 'Layout Point')
            ->where('auth.context.provider.name', 'Layout Cafe')
        );
});

it('never shares the password hash with the frontend', function (): void {
    $payload = json_encode(
        $this->actingAs(makeUser(CafeteriaUser::ROLE_PROVIDER_ADMIN), 'cafeteria')
            ->get('/transactions')
            ->viewData('page')['props']
    );

    expect($payload)->not->toContain('password')
        ->and($payload)->not->toContain('remember_token');
});

// ── Profile ────────────────────────────────────────────────────────────

it('shows the profile page to any signed-in operator', function (): void {
    $this->actingAs(makeUser(CafeteriaUser::ROLE_SCANNER), 'cafeteria')
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Index')
            ->where('profile.role', CafeteriaUser::ROLE_SCANNER)
            ->where('profile.cafeteria.name', 'Layout Point')
        );
});

it('redirects a guest away from the profile page', function (): void {
    $this->get('/profile')->assertRedirect('/login');
});

it('lets an operator update their own name and email', function (): void {
    $user = makeUser(CafeteriaUser::ROLE_SCANNER);

    $this->actingAs($user, 'cafeteria')
        ->patch('/profile', ['name' => 'Renamed Person', 'email' => 'renamed@test.local'])
        ->assertRedirect();

    expect($user->fresh()->name)->toBe('Renamed Person')
        ->and($user->fresh()->email)->toBe('renamed@test.local');
});

it('rejects an email already used by another operator', function (): void {
    makeUser(CafeteriaUser::ROLE_CAFETERIA_MANAGER, ['email' => 'taken@test.local']);
    $user = makeUser(CafeteriaUser::ROLE_SCANNER);

    $this->actingAs($user, 'cafeteria')
        ->patch('/profile', ['name' => 'Test Person', 'email' => 'taken@test.local'])
        ->assertSessionHasErrors('email');
});

it('does not let an operator change their own role', function (): void {
    $user = makeUser(CafeteriaUser::ROLE_SCANNER);

    $this->actingAs($user, 'cafeteria')
        ->patch('/profile', [
            'name' => 'Test Person',
            'email' => 'scanner@test.local',
            // A scanner promoting themselves would defeat every management gate.
            'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN,
        ])
        ->assertRedirect();

    expect($user->fresh()->role)->toBe(CafeteriaUser::ROLE_SCANNER);
});

it('changes the password when the current one is correct', function (): void {
    $user = makeUser(CafeteriaUser::ROLE_SCANNER);

    $this->actingAs($user, 'cafeteria')
        ->patch('/profile/password', [
            'current_password' => 'password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])
        ->assertRedirect();

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue();
});

it('refuses a password change without the current password', function (): void {
    $user = makeUser(CafeteriaUser::ROLE_SCANNER);

    $this->actingAs($user, 'cafeteria')
        ->patch('/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])
        ->assertSessionHasErrors('current_password');

    // An unattended terminal must not let a passer-by lock the operator out.
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuses a password change when the confirmation does not match', function (): void {
    $user = makeUser(CafeteriaUser::ROLE_SCANNER);

    $this->actingAs($user, 'cafeteria')
        ->patch('/profile/password', [
            'current_password' => 'password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'different-password',
        ])
        ->assertSessionHasErrors('password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuses a password shorter than the minimum', function (): void {
    $user = makeUser(CafeteriaUser::ROLE_SCANNER);

    $this->actingAs($user, 'cafeteria')
        ->patch('/profile/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertSessionHasErrors('password');
});

// ── Management authorization ───────────────────────────────────────────

it('denies every management page to a scanner', function (string $path): void {
    // The sidebar hides these, but the route is the real gate: `manager()`
    // used to return the user without checking a role, so a scanner could
    // reach every management page and write action by typing the URL.
    $this->actingAs(makeUser(CafeteriaUser::ROLE_SCANNER), 'cafeteria')
        ->get($path)
        ->assertForbidden();
})->with(['/users', '/providers', '/cafeterias', '/assignments', '/cafeteria-settings']);

it('denies management writes to a scanner', function (): void {
    $this->actingAs(makeUser(CafeteriaUser::ROLE_SCANNER), 'cafeteria')
        ->post('/users', [
            'name' => 'Sneaky Admin',
            'email' => 'sneaky@test.local',
            'password' => 'password123',
            'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN,
        ])
        ->assertForbidden();

    expect(CafeteriaUser::query()->where('email', 'sneaky@test.local')->exists())->toBeFalse();
});

it('denies management pages to a report viewer', function (): void {
    $this->actingAs(makeUser(CafeteriaUser::ROLE_REPORT_VIEWER), 'cafeteria')
        ->get('/users')
        ->assertForbidden();
});

it('allows management pages for a cafeteria manager', function (): void {
    $this->actingAs(makeUser(CafeteriaUser::ROLE_CAFETERIA_MANAGER), 'cafeteria')
        ->get('/users')
        ->assertOk();
});
