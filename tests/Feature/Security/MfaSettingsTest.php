<?php

declare(strict_types=1);

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function mfaSecurityAdmin(): User
{
    Permission::findOrCreate('system-settings.view', 'web');
    Permission::findOrCreate('system-settings.manageSecurity', 'web');

    $user = User::factory()->create();
    $user->givePermissionTo(['system-settings.view', 'system-settings.manageSecurity']);

    return $user;
}

/**
 * Full valid payload for PATCH /system-settings/security.
 */
function mfaSecurityPayload(array $overrides = []): array
{
    return array_merge([
        'password_min_length' => 12,
        'session_timeout_minutes' => 120,
        'max_upload_size_mb' => 10,
        'password_complexity_enabled' => true,
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
        'password_expiry_days' => 90,
        'mfa_enabled' => true,
        'mfa_required_for_all' => false,
        'mfa_required_role_ids' => [],
        'force_https' => false,
        'maintenance_banner_enabled' => false,
        'maintenance_banner_message_en' => null,
        'maintenance_banner_message_am' => null,
        'allowed_file_types' => ['jpg', 'png'],
        'allowed_upload_mime_types' => ['image/jpeg', 'image/png'],
        'audit_retention_days' => 365,
        'sensitive_export_requires_reason' => true,
        'api_rate_limit_per_minute' => 120,
        'verification_rate_limit_per_minute' => 120,
    ], $overrides);
}

function seedMfaSetting(string $key, string $value, string $type): void
{
    SystemSetting::query()->updateOrCreate(
        ['group' => 'security', 'key' => $key],
        ['value' => $value, 'type' => $type, 'label_en' => $key],
    );

    app(SystemSettingsService::class)->clearCache();
}

// ── Settings page ─────────────────────────────────────────────────────────────

test('security settings page shows role-based MFA settings and hides legacy flag', function (): void {
    $admin = mfaSecurityAdmin();
    Role::findOrCreate('HR Officer', 'web');

    $response = $this->actingAs($admin)->get('/system-settings');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('SystemSettings/Index')
        ->has('roles')
        ->where('settingGroups.security.fields', function ($fields) {
            $keys = collect($fields)->pluck('key');

            return $keys->contains('mfa_enabled')
                && $keys->contains('mfa_required_for_all')
                && $keys->contains('mfa_required_role_ids')
                && ! $keys->contains('require_mfa_for_admins');
        })
    );
});

// ── Updating settings ─────────────────────────────────────────────────────────

test('system admin can enable global MFA', function (): void {
    $admin = mfaSecurityAdmin();

    $response = $this->actingAs($admin)->patch('/system-settings/security', mfaSecurityPayload([
        'mfa_enabled' => true,
        'mfa_required_for_all' => true,
    ]));

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    expect(SystemSetting::query()->where('group', 'security')->where('key', 'mfa_enabled')->value('value'))->toBe('true');
    expect(SystemSetting::query()->where('group', 'security')->where('key', 'mfa_required_for_all')->value('value'))->toBe('true');
});

test('system admin can require MFA for selected roles', function (): void {
    $admin = mfaSecurityAdmin();
    $role = Role::findOrCreate('HR Officer', 'web');

    $response = $this->actingAs($admin)->patch('/system-settings/security', mfaSecurityPayload([
        'mfa_required_role_ids' => [$role->id],
    ]));

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $stored = SystemSetting::query()->where('group', 'security')->where('key', 'mfa_required_role_ids')->value('value');
    expect(json_decode((string) $stored, true))->toBe([(string) $role->id]);
});

test('selecting a role id that does not exist is rejected', function (): void {
    $admin = mfaSecurityAdmin();

    $response = $this->actingAs($admin)->patch('/system-settings/security', mfaSecurityPayload([
        'mfa_required_role_ids' => [999999],
    ]));

    $response->assertSessionHasErrors('mfa_required_role_ids.0');
});

test('unauthorized user cannot update MFA settings', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/system-settings/security', mfaSecurityPayload());

    $response->assertForbidden();
});

test('MFA setting changes are audited', function (): void {
    $admin = mfaSecurityAdmin();

    $this->actingAs($admin)
        ->patch('/system-settings/security', mfaSecurityPayload(['mfa_required_for_all' => true]))
        ->assertRedirect();

    $this->assertDatabaseHas('audit_logs', [
        'event_type' => 'setting_updated',
        'actor_user_id' => $admin->id,
    ]);
});

// ── Enforcement ───────────────────────────────────────────────────────────────

test('user with a selected role is required to use MFA', function (): void {
    $role = Role::findOrCreate('HR Officer', 'web');
    seedMfaSetting('mfa_enabled', 'true', 'boolean');
    seedMfaSetting('mfa_required_role_ids', json_encode([(string) $role->id]), 'multiselect');

    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->requiresMfa())->toBeTrue();
});

test('user without a selected role is not required when required_for_all is false', function (): void {
    $role = Role::findOrCreate('HR Officer', 'web');
    Role::findOrCreate('Committee Writer', 'web');
    seedMfaSetting('mfa_enabled', 'true', 'boolean');
    seedMfaSetting('mfa_required_for_all', 'false', 'boolean');
    seedMfaSetting('mfa_required_role_ids', json_encode([(string) $role->id]), 'multiselect');

    $user = User::factory()->create();
    $user->assignRole('Committee Writer');

    expect($user->requiresMfa())->toBeFalse();
});

test('required_for_all forces MFA for every user regardless of role', function (): void {
    seedMfaSetting('mfa_enabled', 'true', 'boolean');
    seedMfaSetting('mfa_required_for_all', 'true', 'boolean');

    $noRoleUser = User::factory()->create();
    $roleUser = User::factory()->create();
    $roleUser->assignRole(Role::findOrCreate('Committee Chairperson', 'web'));

    expect($noRoleUser->requiresMfa())->toBeTrue();
    expect($roleUser->requiresMfa())->toBeTrue();
});

test('disabling global MFA turns off settings-based enforcement', function (): void {
    $role = Role::findOrCreate('HR Officer', 'web');
    seedMfaSetting('mfa_enabled', 'false', 'boolean');
    seedMfaSetting('mfa_required_for_all', 'true', 'boolean');
    seedMfaSetting('mfa_required_role_ids', json_encode([(string) $role->id]), 'multiselect');

    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->requiresMfa())->toBeFalse();
});

test('env baseline roles require MFA while MFA is enabled', function (): void {
    config(['security.mfa_required_roles' => ['Super Admin', 'City Admin']]);
    seedMfaSetting('mfa_enabled', 'true', 'boolean');

    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Super Admin', 'web'));

    expect($user->requiresMfa())->toBeTrue();
});

test('disabling MFA in settings also lifts the env baseline requirement', function (): void {
    config(['security.mfa_required_roles' => ['Super Admin', 'City Admin']]);
    seedMfaSetting('mfa_enabled', 'false', 'boolean');

    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Super Admin', 'web'));

    expect($user->requiresMfa())->toBeFalse();
});

// ── Backward compatibility ────────────────────────────────────────────────────

test('legacy mfa_for_admins setting remains backward compatible', function (): void {
    config(['security.mfa_required_roles' => []]);
    seedMfaSetting('require_mfa_for_admins', 'true', 'boolean');

    $adminUser = User::factory()->create();
    $adminUser->assignRole(Role::findOrCreate('Organization Admin', 'web'));

    $regularUser = User::factory()->create();
    $regularUser->assignRole(Role::findOrCreate('HR Officer', 'web'));

    expect($adminUser->requiresMfa())->toBeTrue();
    expect($regularUser->requiresMfa())->toBeFalse();
});

test('saving the new MFA settings retires the legacy admin-only flag', function (): void {
    config(['security.mfa_required_roles' => []]);
    seedMfaSetting('require_mfa_for_admins', 'true', 'boolean');

    $admin = mfaSecurityAdmin();
    $this->actingAs($admin)
        ->patch('/system-settings/security', mfaSecurityPayload())
        ->assertRedirect();

    expect(SystemSetting::query()->where('group', 'security')->where('key', 'require_mfa_for_admins')->value('value'))->toBe('false');

    $adminUser = User::factory()->create();
    $adminUser->assignRole(Role::findOrCreate('Organization Admin', 'web'));

    expect($adminUser->requiresMfa())->toBeFalse();
});
