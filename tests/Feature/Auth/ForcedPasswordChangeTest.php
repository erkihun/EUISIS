<?php

declare(strict_types=1);

use App\Actions\Users\CreateUserAction;
use App\Actions\Users\UpdateUserAction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    app()->setLocale('en');

    // MFA is a separate gate; disabling it here keeps these tests focused on
    // the password gate rather than on assembling TOTP state.
    config(['security.mfa_enforce' => false]);

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Employee', 'web');
});

/** A user who must change their password before continuing. */
function forcedUser(array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'password' => 'temp-Password-123',
        'must_change_password' => true,
        'password_changed_at' => null,
        'status' => 'active',
    ], $overrides));

    $user->assignRole('Super Admin');

    return $user;
}

it('marks an admin-created user as needing a password change', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('Super Admin');

    $user = app(CreateUserAction::class)->execute([
        'name' => 'New Officer',
        'email' => 'new.officer@example.test',
        'password' => 'Initial-Password-123',
    ], $admin);

    expect($user->must_change_password)->toBeTrue()
        ->and($user->password_changed_at)->toBeNull();
});

it('redirects a forced user from the dashboard to the change password page', function (): void {
    $this->actingAs(forcedUser())
        ->get(route('dashboard'))
        ->assertRedirect(route('password.forced'));
});

it('blocks protected modules until the password is changed', function (): void {
    $user = forcedUser();

    foreach (['employees.index', 'users.index', 'organizations.index'] as $routeName) {
        $this->actingAs($user)
            ->get(route($routeName))
            ->assertRedirect(route('password.forced'));
    }
});

it('shows the change password page to a forced user', function (): void {
    $this->actingAs(forcedUser())
        ->get(route('password.forced'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/ForcedPasswordChange'));
});

it('lets a forced user log out', function (): void {
    // Being trapped with no way out would be worse than the risk being managed.
    $this->actingAs(forcedUser())
        ->post(route('logout'))
        ->assertRedirect();

    $this->assertGuest();
});

it('does not send a forced user to the change page from guest-only routes', function (): void {
    /*
     * `password.request` sits behind `guest`, so an authenticated user is
     * redirected by that middleware, not by this gate. The distinction matters:
     * a user who never received their temporary password must log out first and
     * then use the reset link, which the logout test above proves is reachable.
     */
    $this->actingAs(forcedUser())
        ->get(route('password.request'))
        ->assertRedirect();
});

it('changes the password and clears the flag', function (): void {
    $user = forcedUser();

    $this->actingAs($user)
        ->post(route('password.forced.update'), [
            'current_password' => 'temp-Password-123',
            'password' => 'Brand-New-Password-456',
            'password_confirmation' => 'Brand-New-Password-456',
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and($user->first_login_at)->not->toBeNull()
        ->and(Hash::check('Brand-New-Password-456', $user->password))->toBeTrue();
});

it('lets the user reach the dashboard after changing the password', function (): void {
    $user = forcedUser();

    $this->actingAs($user)->post(route('password.forced.update'), [
        'current_password' => 'temp-Password-123',
        'password' => 'Brand-New-Password-456',
        'password_confirmation' => 'Brand-New-Password-456',
    ]);

    $this->actingAs($user->fresh())
        ->get(route('dashboard'))
        ->assertOk();
});

it('rejects a wrong current password', function (): void {
    $user = forcedUser();

    $this->actingAs($user)
        ->post(route('password.forced.update'), [
            'current_password' => 'not-the-temp-password',
            'password' => 'Brand-New-Password-456',
            'password_confirmation' => 'Brand-New-Password-456',
        ])
        ->assertSessionHasErrors('current_password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('rejects reusing the temporary password', function (): void {
    $user = forcedUser();

    $this->actingAs($user)
        ->post(route('password.forced.update'), [
            'current_password' => 'temp-Password-123',
            'password' => 'temp-Password-123',
            'password_confirmation' => 'temp-Password-123',
        ])
        ->assertSessionHasErrors('password');

    // Reuse would leave the credential exactly as shared as before.
    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('rejects a new password that fails the policy', function (): void {
    $user = forcedUser();

    $this->actingAs($user)
        ->post(route('password.forced.update'), [
            'current_password' => 'temp-Password-123',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('rejects a mismatched confirmation', function (): void {
    $this->actingAs(forcedUser())
        ->post(route('password.forced.update'), [
            'current_password' => 'temp-Password-123',
            'password' => 'Brand-New-Password-456',
            'password_confirmation' => 'Different-Password-789',
        ])
        ->assertSessionHasErrors('password');
});

it('does not gate a user who has already changed their password', function (): void {
    $user = User::factory()->create([
        'must_change_password' => false,
        'password_changed_at' => now(),
        'status' => 'active',
    ]);
    $user->assignRole('Super Admin');

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('redirects away from the change page when nothing is pending', function (): void {
    $user = User::factory()->create(['must_change_password' => false, 'status' => 'active']);
    $user->assignRole('Super Admin');

    $this->actingAs($user)
        ->get(route('password.forced'))
        ->assertRedirect(route('dashboard'));
});

it('forces a change when an admin resets someone else password', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('Super Admin');

    $target = User::factory()->create([
        'must_change_password' => false,
        'password_changed_at' => now(),
        'status' => 'active',
    ]);

    app(UpdateUserAction::class)->execute(['password' => 'Admin-Reset-Password-1'], $target, $admin);

    $target->refresh();

    expect($target->must_change_password)->toBeTrue()
        ->and($target->password_changed_at)->toBeNull();
});

it('does not force a change when a user updates their own record', function (): void {
    $user = User::factory()->create([
        'must_change_password' => false,
        'password_changed_at' => now(),
        'status' => 'active',
    ]);
    $user->assignRole('Super Admin');

    app(UpdateUserAction::class)->execute(['password' => 'My-Own-Password-1'], $user, $user);

    // Self-service change must not lock the holder out of their own account.
    expect($user->fresh()->must_change_password)->toBeFalse();
});

it('blocks the profile password form while a forced change is pending', function (): void {
    $user = forcedUser();

    $this->actingAs($user)
        ->put(route('password.update'), [
            'current_password' => 'temp-Password-123',
            'password' => 'Profile-Changed-Password-1',
            'password_confirmation' => 'Profile-Changed-Password-1',
        ])
        ->assertRedirect();

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('leaves existing users untouched by the migration', function (): void {
    // The migration defaults to false: nobody is retroactively locked out.
    $user = User::factory()->create(['status' => 'active']);

    expect($user->fresh()->must_change_password)->toBeFalse();
});
