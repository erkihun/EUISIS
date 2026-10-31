<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\SystemSetting;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Inertia\Testing\AssertableInertia;

/*
 * System settings secrets (SMTP password, Telegram bot token, SMS key and
 * secret) are write-only: no page, shared prop or flashed old input ever
 * carries them back; a blank field keeps the stored value and only `clear`
 * removes it (#3, d732138).
 */

const SETTINGS_HARDENING_SECRETS = [
    'mail_password' => 'smtp-secret-7f3a',
    'telegram_bot_token' => '123456:telegram-secret-9b1c',
    'sms_api_key' => 'sms-key-4d2e',
    'sms_api_secret' => 'sms-secret-8a6f',
];

function settingsHardeningAdmin(): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now(), 'must_change_password' => false]);
    $user->roles()->attach(Role::query()->firstOrCreate(['name' => 'admin']));

    return $user;
}

test('stored secrets never reach the settings page or any shared page props', function (): void {
    SystemSetting::query()->create(['app_name' => 'Court MS', ...SETTINGS_HARDENING_SECRETS]);
    $admin = settingsHardeningAdmin();

    foreach ([route('court.settings.system.edit'), route('court.settings.maintenance'), route('court.admin.dashboard'), route('court.landing.home')] as $url) {
        $response = $this->actingAs($admin)->get($url)->assertOk();
        foreach (SETTINGS_HARDENING_SECRETS as $secret) {
            $response->assertDontSee($secret, false);
        }
    }

    // The page learns only that each secret is set.
    $this->actingAs($admin)->get(route('court.settings.system.edit'))->assertInertia(function (AssertableInertia $page) {
        $fields = collect($page->toArray()['props']['settingGroups'])->flatMap(fn (array $group) => $group['fields'])->keyBy('key');
        foreach (array_keys(SETTINGS_HARDENING_SECRETS) as $key) {
            expect($fields[$key])->toMatchArray(['is_encrypted' => true, 'configured' => true, 'value' => null]);
        }

        return $page;
    });
});

test('secrets are not flashed back after a validation error, and blank keeps them', function (): void {
    $settings = SystemSetting::query()->create(['app_name' => 'Court MS', ...SETTINGS_HARDENING_SECRETS]);
    $admin = settingsHardeningAdmin();

    $this->actingAs($admin)->from(route('court.settings.system.edit'))
        ->patch(route('court.system-settings.sms.update'), ['sms_api_url' => 'not a url', 'sms_api_key' => 'typed-sms-key', 'sms_api_secret' => 'typed-sms-secret'])
        ->assertSessionHasErrors('sms_api_url');
    $this->actingAs($admin)->from(route('court.settings.system.edit'))
        ->patch(route('court.system-settings.email.update'), ['mail_port' => 'x', 'mail_password' => 'typed-smtp-password'])
        ->assertSessionHasErrors('mail_port');
    $this->actingAs($admin)->from(route('court.settings.system.edit'))
        ->patch(route('court.system-settings.telegram.update'), ['telegram_default_chat_id' => str_repeat('9', 200), 'telegram_bot_token' => 'typed-telegram-token'])
        ->assertSessionHasErrors('telegram_default_chat_id');

    foreach (['telegram_bot_token', 'sms_api_key', 'sms_api_secret', 'mail_password'] as $field) {
        expect(session()->getOldInput($field))->toBeNull();
    }

    $this->actingAs($admin)->patch(route('court.system-settings.telegram.update'), ['telegram_bot_token' => ''])->assertSessionHasNoErrors();
    $this->actingAs($admin)->patch(route('court.system-settings.email.update'), ['mail_password' => ''])->assertSessionHasNoErrors();
    $this->actingAs($admin)->patch(route('court.system-settings.sms.update'), ['sms_api_key' => '', 'sms_api_secret' => '', 'clear' => ['sms_api_secret']])->assertSessionHasNoErrors();

    $settings->refresh();
    expect($settings->telegram_bot_token)->toBe(SETTINGS_HARDENING_SECRETS['telegram_bot_token'])
        ->and($settings->sms_api_key)->toBe(SETTINGS_HARDENING_SECRETS['sms_api_key'])
        ->and($settings->mail_password)->toBe(SETTINGS_HARDENING_SECRETS['mail_password'])
        ->and($settings->sms_api_secret)->toBeNull();
});
