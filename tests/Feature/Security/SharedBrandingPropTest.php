<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The globally shared `settings` prop carries the whole appearance
 * configuration — sidebar colour, primary colour, logo. A page that renders a
 * prop of the same name replaces it, and the screen loses its branding: the
 * sidebar falls back to unstyled white and ApplicationLogo shows the default
 * framework logo.
 *
 * Transfers/Settings did exactly that. These tests stop it recurring there or
 * anywhere else.
 */
beforeEach(function (): void {
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());

    $this->admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $this->admin->assignRole('Super Admin');
});

test('the appearance settings survive on every admin page', function (string $route): void {
    $response = $this->actingAs($this->admin)->get(route($route))->assertOk();

    $settings = $response->viewData('page')['props']['settings'] ?? [];

    expect($settings)
        ->toHaveKey('appearance.sidebar_color')
        ->toHaveKey('appearance.primary_color')
        ->toHaveKey('general.identity_system_logo_url');
})->with(['employees.index', 'transfer-settings.show']);

test('the transfer settings page still receives its own data', function (): void {
    $response = $this->actingAs($this->admin)->get(route('transfer-settings.show'))->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props)->toHaveKey('transferSettings')
        ->and($props['transferSettings'])->toHaveKey('minimum_service_months');
});

/* No controller may render a page prop that shadows the shared one. */
test('no controller renders a page prop named settings', function (): void {
    $offenders = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Http/Controllers/{,*/,*/*/}*.php', GLOB_BRACE) as $file) {
        foreach (file($file) as $number => $line) {
            if (preg_match("/^\s*'settings'\s*=>/", $line)) {
                $offenders[] = basename($file).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
