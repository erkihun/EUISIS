<?php

declare(strict_types=1);

use App\Models\SystemSetting;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/*
 * Where a user lands after signing in.
 *
 * The existing authentication test signs in a user with no permissions, which
 * always takes the employee-portal branch. The administrator branch — the one
 * that reads `general.default_dashboard_route` — had no coverage, and a
 * missing import there turned every administrator login into a 500 while the
 * whole suite stayed green. These tests exercise that branch directly.
 */
function landingUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function setLandingRoute(string $route): void
{
    SystemSetting::query()->updateOrCreate(
        ['group' => 'general', 'key' => 'default_dashboard_route'],
        ['value' => $route, 'type' => 'select', 'is_public' => true],
    );
}

it('sends an administrator to the dashboard by default', function (): void {
    $user = landingUser(['dashboard.view']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('honours the configured landing route when the user may open it', function (): void {
    setLandingRoute('employees.index');
    $user = landingUser(['dashboard.view', 'employees.view']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('employees.index', absolute: false));
});

it('falls back to the dashboard when the user may not open the configured route', function (): void {
    setLandingRoute('employees.index');
    $user = landingUser(['dashboard.view']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
});
