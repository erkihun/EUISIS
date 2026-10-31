<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\SystemSetting;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function systemSettingsAdministrator(): User
{
    $user = User::factory()->create(['must_change_password' => false, 'email' => 'admin-'.uniqid().'@example.test']);
    $role = Role::query()->firstOrCreate(['name' => 'admin']);
    $user->roles()->attach($role);

    return $user;
}

/** One field of a section of the EUISIS settings page payload. */
function systemSettingsField(AssertableInertia $page, string $section, string $key): array
{
    $fields = collect($page->toArray()['props']['settingGroups'][$section]['fields']);

    return (array) $fields->firstWhere('key', $key);
}

test('a poisoned system_settings cache key self-heals instead of crashing', function () {
    SystemSetting::query()->create(['app_name' => 'Court MS', 'maintenance_mode' => false]);

    // Simulate the old bug: a scalar cached under the shared settings key.
    Cache::put('system_settings', false, 3600);

    $user = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($user)
        ->get('/court/profile')
        ->assertOk();

    expect(SystemSetting::cached())->toBeInstanceOf(SystemSetting::class);
});

test('system settings and maintenance require settings.manage', function () {
    SystemSetting::query()->create(['app_name' => 'Court MS']);
    $user = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($user)->get(route('court.settings.system.edit'))->assertRedirect(route('court.dashboard'));
    $this->actingAs($user)->get(route('court.settings.maintenance'))->assertRedirect(route('court.dashboard'));
    $this->actingAs($user)->patch(route('court.system-settings.general.update'), ['application_name' => 'X'])->assertRedirect(route('court.dashboard'));
    expect(SystemSetting::query()->value('app_name'))->toBe('Court MS');
});

test('the settings page is the EUISIS page with court-ms settings in its sections', function () {
    SystemSetting::query()->create(['app_name' => 'Court MS', 'login_max_attempts' => 7, 'mail_host' => 'smtp.example.test', 'use_ethiopian_calendar' => true]);
    $mfaRole = Role::query()->create(['name' => 'registrar', 'mfa_required' => true]);

    $this->actingAs(systemSettingsAdministrator())
        ->get(route('court.settings.system.edit'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($mfaRole) {
            $page->component('SystemSettings/Index')
                ->has('settingGroups.general.fields')
                ->has('settingGroups.security.fields')
                ->where('can.manageSecurity', true)
                ->where('can.manageIdCards', false);

            // EUISIS keys where EUISIS has the setting, so the cards match; court-ms's own keep their names.
            expect(systemSettingsField($page, 'general', 'application_name'))->toMatchArray(['value' => 'Court MS', 'is_required' => true, 'type' => 'string'])
                ->and(systemSettingsField($page, 'security', 'max_login_attempts')['value'])->toBe(7)
                ->and(systemSettingsField($page, 'email', 'mail_host')['value'])->toBe('smtp.example.test')
                ->and(systemSettingsField($page, 'localization', 'use_ethiopian_calendar')['value'])->toBeTrue()
                ->and(systemSettingsField($page, 'security', 'mfa_required_role_ids')['value'])->toBe([(string) $mfaRole->id])
                ->and(systemSettingsField($page, 'general', 'application_name')['label_am'])->not->toBe('');

            return $page;
        });
});

test('the maintenance page shows the live protection status, database and system information', function () {
    SystemSetting::query()->create([
        'app_name' => 'Court MS', 'session_lifetime' => 45, 'password_min_length' => 12,
        'password_require_uppercase' => true, 'password_require_number' => true, 'password_require_symbol' => true,
        'login_max_attempts' => 5, 'lockout_minutes' => 15,
    ]);

    $this->actingAs(systemSettingsAdministrator())
        ->get(route('court.settings.maintenance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Settings/Maintenance')
            ->where('securityMetrics.strong_password', true)
            ->where('securityMetrics.strict_lockout', true)
            ->has('securityMetrics.score')
            ->has('databaseMetrics.driver')
            ->has('systemInfo.info_php'));

    expect(config('session.lifetime'))->toBe(45);
});

test('each section saves its own fields into the court-ms columns', function () {
    $settings = SystemSetting::query()->create(['app_name' => 'Court MS', 'contact_email' => 'old@example.test']);
    $role = Role::query()->create(['name' => 'judge', 'mfa_required' => false]);
    $admin = systemSettingsAdministrator();

    // Invalid input first: saving force_https below sends later plain-HTTP test requests to HTTPS.
    $this->actingAs($admin)->patch(route('court.system-settings.general.update'), ['application_name' => '', 'support_email' => 'not-an-email'])
        ->assertSessionHasErrors(['application_name', 'support_email']);
    $this->actingAs($admin)->patch(route('court.system-settings.localization.update'), ['timezone' => 'Mars/Olympus'])
        ->assertSessionHasErrors('timezone');

    $this->actingAs($admin)->patch(route('court.system-settings.security.update'), [
        'password_min_length' => 10, 'password_require_uppercase' => '1', 'password_require_number' => '0', 'password_require_symbol' => '1',
        'session_timeout_minutes' => 60, 'max_login_attempts' => 4, 'lockout_minutes' => 20, 'force_https' => '1', 'mfa_enabled' => '0',
        'mfa_required_role_ids' => [$role->id], 'audit_retention_days' => 400, 'api_rate_limit_per_minute' => 120,
        'session_warning_minutes' => 5, 'session_extend_on_activity' => '1', 'audit_logging_enabled' => '1', 'api_enabled' => '0',
        'maintenance_mode' => '0', 'registration_open' => '1',
    ])->assertRedirect(route('court.settings.system.edit', ['tab' => 'security']))->assertSessionHasNoErrors();

    $settings->refresh();
    expect((int) $settings->login_max_attempts)->toBe(4)
        ->and((int) $settings->session_lifetime)->toBe(60)
        ->and((int) $settings->api_rate_limit)->toBe(120)
        ->and((bool) $settings->password_require_number)->toBeFalse()
        ->and((bool) $settings->force_https)->toBeTrue()
        ->and((bool) $role->fresh()->mfa_required)->toBeTrue()
        // Another section is untouched.
        ->and($settings->contact_email)->toBe('old@example.test');

});

test('a branding image is stored, and the remove choice clears it', function () {
    Storage::fake('court_public');
    $settings = SystemSetting::query()->create(['app_name' => 'Court MS']);
    $admin = systemSettingsAdministrator();

    $this->actingAs($admin)->patch(route('court.system-settings.general.update'), [
        'application_name' => 'Court MS', 'identity_system_logo' => UploadedFile::fake()->image('logo.png', 120, 120),
    ])->assertSessionHasNoErrors();
    $settings->refresh();
    expect($settings->logo_path)->not->toBeNull();
    Storage::disk('court_public')->assertExists($settings->logo_path);

    $this->actingAs($admin)->get(route('court.settings.system.edit'))
        ->assertInertia(function (AssertableInertia $page) use ($settings) {
            $logo = systemSettingsField($page, 'general', 'identity_system_logo');
            expect($logo)->toMatchArray(['configured' => true, 'value' => null])
                ->and($logo['asset_url'])->toContain($settings->logo_path);

            return $page;
        });

    $this->actingAs($admin)->patch(route('court.system-settings.general.update'), ['application_name' => 'Court MS', 'remove_assets' => ['identity_system_logo']])
        ->assertSessionHasNoErrors();
    expect($settings->fresh()->logo_path)->toBeNull();
});

test('stored secrets never reach the page; blank keeps them and clear removes them', function () {
    $settings = SystemSetting::query()->create([
        'app_name' => 'Court MS', 'mail_password' => 'smtp-secret-value',
        'telegram_bot_token' => 'telegram-secret-value', 'sms_api_key' => 'sms-key-value', 'sms_api_secret' => 'sms-secret-value',
    ]);
    $admin = systemSettingsAdministrator();

    $this->actingAs($admin)->get(route('court.settings.system.edit'))
        ->assertOk()
        ->assertDontSee('smtp-secret-value', false)
        ->assertDontSee('telegram-secret-value', false)
        ->assertDontSee('sms-key-value', false)
        ->assertDontSee('sms-secret-value', false)
        ->assertInertia(function (AssertableInertia $page) {
            expect(systemSettingsField($page, 'email', 'mail_password'))->toMatchArray(['is_encrypted' => true, 'configured' => true, 'value' => null]);

            return $page;
        });

    $this->actingAs($admin)->patch(route('court.system-settings.telegram.update'), ['telegram_bot_token' => '', 'telegram_default_chat_id' => '-100'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->patch(route('court.system-settings.sms.update'), ['sms_api_key' => 'new-sms-key', 'sms_api_secret' => '', 'clear' => ['sms_api_secret']])->assertSessionHasNoErrors();
    $this->actingAs($admin)->patch(route('court.system-settings.email.update'), ['mail_password' => '', 'clear' => ['mail_password']])->assertSessionHasNoErrors();

    $settings->refresh();
    expect($settings->telegram_bot_token)->toBe('telegram-secret-value')
        ->and($settings->sms_api_key)->toBe('new-sms-key')
        ->and($settings->sms_api_secret)->toBeNull()
        ->and($settings->mail_password)->toBeNull();
});

test('SMTP secrets are not flashed back after validation errors', function () {
    SystemSetting::query()->create(['app_name' => 'Court MS']);

    $this->actingAs(systemSettingsAdministrator())
        ->from(route('court.settings.system.edit', ['tab' => 'email']))
        ->patch(route('court.system-settings.email.update'), ['mail_port' => 'not-a-port', 'mail_password' => 'smtp-secret-value'])
        ->assertSessionHasErrors('mail_port');

    expect(session()->getOldInput('mail_password'))->toBeNull();
});

test('the email Test button sends a synchronous diagnostic email to the administrator', function () {
    Mail::fake();
    Queue::fake();
    SystemSetting::query()->create(['app_name' => 'Court MS']);

    $this->actingAs(systemSettingsAdministrator())
        ->from(route('court.settings.system.edit', ['tab' => 'email']))
        ->post(route('court.system-settings.test-email'))
        ->assertRedirect(route('court.settings.system.edit', ['tab' => 'email']))
        ->assertSessionHasNoErrors();

    Mail::assertOutgoingCount(1);
    Queue::assertNotPushed(SendQueuedNotifications::class);
});

test('the Telegram Test button reports whether Telegram accepted the message', function () {
    SystemSetting::query()->create(['app_name' => 'Court MS', 'telegram_bot_token' => '123:abc', 'telegram_default_chat_id' => '-100']);
    Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true])->push(['ok' => false], 400)]);
    $admin = systemSettingsAdministrator();

    $this->actingAs($admin)->post(route('court.system-settings.test-telegram'))->assertSessionHas('ok');
    $this->actingAs($admin)->post(route('court.system-settings.test-telegram'))->assertSessionHas('error');
});

test('the channel tests require settings permission', function () {
    $user = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($user)->post(route('court.system-settings.test-email'))->assertRedirect(route('court.dashboard'));
    $this->actingAs($user)->post(route('court.system-settings.test-telegram'))->assertRedirect(route('court.dashboard'));
});

test('closed registration setting blocks applicant self registration', function () {
    SystemSetting::query()->create([
        'app_name' => 'Court MS',
        'registration_open' => false,
    ]);

    $this->get('/court/applicant/register')->assertForbidden();
});
