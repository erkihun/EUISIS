<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    foreach ([
        'roles.viewAny', 'permissions.viewAny', 'system-settings.view',
        'transport-reports.view', 'transport-settings.view', 'transport-providers.viewAny',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
});

function plainUser(): User
{
    return User::factory()->create();
}

// ── Guests ───────────────────────────────────────────────────────────────────

test('guests are redirected from protected admin pages', function (string $routeName): void {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with([
    'roles.index',
    'permissions.index',
    'system-settings.index',
    'transport.reports.index',
    'transport.settings.index',
]);

// ── Authenticated but unauthorized ───────────────────────────────────────────

test('an authenticated user without the permission is forbidden', function (string $routeName): void {
    actingAs(plainUser())
        ->get(route($routeName))
        ->assertForbidden();
})->with([
    'roles.index',
    'permissions.index',
    'system-settings.index',
    'transport.reports.index',
    'transport.settings.index',
    'transport.providers.index',
]);

// ── Authorized ───────────────────────────────────────────────────────────────

test('a user with transport-reports.view can open the transport reports page', function (): void {
    Role::findOrCreate('Transport Viewer', 'web')->syncPermissions(['transport-reports.view']);
    $user = plainUser();
    $user->assignRole('Transport Viewer');

    actingAs($user)
        ->get(route('transport.reports.index'))
        ->assertOk();
});

test('transport settings update is blocked without the update permission', function (): void {
    Role::findOrCreate('Transport Reader', 'web')->syncPermissions(['transport-settings.view']);
    $user = plainUser();
    $user->assignRole('Transport Reader');

    actingAs($user)
        ->patch(route('transport.settings.update'), [
            'require_pass_for_scan' => true,
            'allow_pay_as_you_go' => false,
            'scan_nonce_required' => true,
        ])
        ->assertForbidden();
});
