<?php

declare(strict_types=1);

use App\Actions\Users\UpdateUserAction;
use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    app()->setLocale('en');

    foreach (['system-settings.view', 'system-settings.manageSecurity', 'users.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

function defaultPasswordAdmin(): User
{
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->givePermissionTo(['system-settings.view', 'system-settings.manageSecurity', 'users.create']);

    return $admin;
}

function defaultPasswordSecurityPayload(array $overrides = []): array
{
    return array_merge([
        'password_min_length' => 15,
        'password_max_length' => 128,
        'password_history_count' => 5,
        'password_block_personal_info' => true,
        'password_block_common' => true,
        'password_breach_check' => true,
        'session_timeout_minutes' => 120,
        'max_upload_size_mb' => 10,
        'password_complexity_enabled' => true,
        'default_password_enabled' => true,
        'default_password_hash' => 'Default-Start-928!',
        'default_password_hash_confirmation' => 'Default-Start-928!',
        'force_change_default_password' => true,
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
        'password_expiry_days' => 90,
        'mfa_enabled' => false,
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

function seedDefaultPassword(string $plain = 'Default-Start-928!', bool $enabled = true): void
{
    foreach ([
        'default_password_enabled' => [$enabled ? 'true' : 'false', 'boolean'],
        'default_password_hash' => [Hash::make($plain), 'password'],
        'force_change_default_password' => ['true', 'boolean'],
    ] as $key => [$value, $type]) {
        SystemSetting::query()->updateOrCreate(
            ['group' => 'security', 'key' => $key],
            ['value' => $value, 'type' => $type, 'label_en' => $key],
        );
    }

    app(SystemSettingsService::class)->clearCache();
}

test('the shared default password can no longer be configured', function (): void {
    $admin = defaultPasswordAdmin();

    $this->actingAs($admin)
        ->patch(route('system-settings.security.update'), defaultPasswordSecurityPayload([
            'default_password_enabled' => true,
            'default_password_hash' => 'Default-Start-928!',
            'default_password_hash_confirmation' => 'Default-Start-928!',
        ]))
        ->assertSessionHasErrors(['default_password_enabled', 'default_password_hash']);

    expect(SystemSetting::query()->where('group', 'security')->where('key', 'default_password_hash')->exists())->toBeFalse();
    $this->assertDatabaseMissing('audit_logs', ['event_type' => AuditEventType::DefaultPasswordConfigured->value]);
});

test('the settings page offers no default password and never exposes a stored hash', function (): void {
    seedDefaultPassword();

    $this->actingAs(defaultPasswordAdmin())
        ->get(route('system-settings.index'))
        ->assertInertia(function ($page): void {
            $keys = collect($page->toArray()['props']['settingGroups']['security']['fields'])->pluck('key');

            expect($keys)->not->toContain('default_password_hash')
                ->and($keys)->not->toContain('default_password_enabled')
                ->and($keys)->not->toContain('password_complexity_enabled')
                ->and($keys)->not->toContain('password_expiry_days')
                ->and($keys)->toContain('password_history_count');
        });
});

test('a new user without a typed password gets a unique one-time password, never the shared default', function (): void {
    seedDefaultPassword();
    $admin = defaultPasswordAdmin();

    $response = $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Default Password User',
            'email' => 'default.user@example.test',
            'password' => '',
            'password_confirmation' => '',
            'status' => 'active',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    $temporary = (string) session('flash.temporary_password');
    $user = User::query()->where('email', 'default.user@example.test')->firstOrFail();

    expect($temporary)->toMatch('/^[A-Za-z0-9]{5}(-[A-Za-z0-9]{5}){3}$/')
        ->and(Hash::check($temporary, $user->password))->toBeTrue()
        ->and(Hash::check('Default-Start-928!', $user->password))->toBeFalse()
        ->and($user->must_change_password)->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'auditable_id' => $user->id,
        'event_type' => AuditEventType::TemporaryPasswordAssigned->value,
    ]);

    // The one-time password is in no audit row.
    expect(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($temporary);
});

test('two accounts created without a password never share one', function (): void {
    $admin = defaultPasswordAdmin();
    $passwords = [];

    foreach (['first', 'second'] as $who) {
        $this->actingAs($admin)->post(route('users.store'), [
            'name' => ucfirst($who).' Person', 'email' => "{$who}.person@example.test",
            'password' => '', 'password_confirmation' => '', 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $passwords[] = (string) session('flash.temporary_password');
    }

    expect($passwords[0])->not->toBe($passwords[1]);
});

test('login with the configured default records login state and redirects immediately', function (): void {
    seedDefaultPassword();
    $user = User::factory()->create([
        'email' => 'login.default@example.test',
        'password' => 'Default-Start-928!',
        'must_change_password' => false,
        'first_login_at' => null,
        'last_login_at' => null,
    ]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'Default-Start-928!',
    ])->assertRedirect(route('password.forced'));

    $user->refresh();

    expect($user->must_change_password)->toBeTrue()
        ->and($user->last_login_at)->not->toBeNull()
        ->and($user->first_login_at)->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'actor_user_id' => $user->id,
        'event_type' => AuditEventType::UserLoggedInWithDefaultPassword->value,
    ]);
});

test('completed non-default login records first and last login timestamps', function (): void {
    seedDefaultPassword();
    $user = User::factory()->create([
        'email' => 'normal.login@example.test',
        'password' => 'Personal-Login-847!',
        'must_change_password' => false,
        'first_login_at' => null,
        'last_login_at' => null,
    ]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'Personal-Login-847!',
    ])->assertRedirect();

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and($user->first_login_at)->not->toBeNull()
        ->and($user->last_login_at)->not->toBeNull();
});

test('forced user can only reach change password and logout', function (): void {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user)->get(route('password.forced'))->assertOk();
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('password.forced'));
    $this->actingAs($user)->get(route('password.confirm'))->assertRedirect(route('password.forced'));

    $this->actingAs($user)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

test('forced password change rejects both the configured default and the old password', function (): void {
    seedDefaultPassword();
    $user = User::factory()->create([
        'password' => 'Temporary-Start-482!',
        'must_change_password' => true,
    ]);

    $this->actingAs($user)
        ->post(route('password.forced.update'), [
            'current_password' => 'Temporary-Start-482!',
            'password' => 'Default-Start-928!',
            'password_confirmation' => 'Default-Start-928!',
        ])
        ->assertSessionHasErrors('password');

    $this->actingAs($user)
        ->post(route('password.forced.update'), [
            'current_password' => 'Temporary-Start-482!',
            'password' => 'Temporary-Start-482!',
            'password_confirmation' => 'Temporary-Start-482!',
        ])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

test('valid forced password change clears the gate and records timestamps', function (): void {
    seedDefaultPassword();
    $user = User::factory()->create([
        'password' => 'Temporary-Start-482!',
        'must_change_password' => true,
        'first_login_at' => null,
        'password_changed_at' => null,
    ]);

    $this->actingAs($user)
        ->post(route('password.forced.update'), [
            'current_password' => 'Temporary-Start-482!',
            'password' => 'Personal-Choice-583!',
            'password_confirmation' => 'Personal-Choice-583!',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and($user->first_login_at)->not->toBeNull();
});

test('admin password reset always requires another password change and is audited', function (): void {
    seedDefaultPassword();
    $admin = defaultPasswordAdmin();
    $target = User::factory()->create([
        'must_change_password' => false,
        'password_changed_at' => now(),
    ]);

    app(UpdateUserAction::class)->execute([
        'password' => 'Default-Start-928!',
    ], $target, $admin);

    expect($target->fresh()->must_change_password)->toBeTrue()
        ->and($target->fresh()->password_changed_at)->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'actor_user_id' => $admin->id,
        'auditable_id' => $target->id,
        'event_type' => AuditEventType::AdminPasswordReset->value,
    ]);
});
