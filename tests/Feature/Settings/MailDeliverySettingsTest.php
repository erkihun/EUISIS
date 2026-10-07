<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use App\Models\SystemSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Mail;

/**
 * Email settings must reach the mail transport as configured, and the
 * "send test" buttons must really send and report the server's answer.
 */
function mailSetting(string $key, mixed $value, string $type = 'string'): void
{
    SystemSetting::query()->updateOrCreate(['group' => 'email', 'key' => $key], ['value' => $value, 'type' => $type]);
}

function applyMailSettings(): array
{
    app(SystemSettingsService::class)->clearCache();
    $provider = new AppServiceProvider(app());
    (fn () => $this->applyRuntimeSystemSettings())->call($provider);

    return (array) config('mail.mailers.smtp');
}

it('turns the encryption setting into the connection scheme Laravel 12 uses', function (string $encryption, int $port, string $scheme, bool $autoTls): void {
    // A contradicting .env value must not win over the settings screen.
    config(['mail.mailers.smtp.scheme' => 'smtps']);
    mailSetting('mail_encryption', $encryption, 'select');
    mailSetting('mail_port', (string) $port, 'integer');

    $smtp = applyMailSettings();

    expect($smtp['scheme'])->toBe($scheme)
        ->and($smtp['port'])->toBe($port)
        ->and($smtp['auto_tls'])->toBe($autoTls);
})->with([
    'TLS on 587 is STARTTLS, not implicit TLS' => ['tls', 587, 'smtp', true],
    'SSL on 465' => ['ssl', 465, 'smtps', true],
    'port 465 is always TLS from the start' => ['tls', 465, 'smtps', true],
    'no encryption never upgrades' => ['none', 25, 'smtp', false],
]);

it('gives up on an unreachable mail server quickly', function (): void {
    expect(config('mail.mailers.smtp.timeout'))->toBe(15);
});

function channelTester(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('system-settings.testNotificationChannels');

    return $user;
}

it('really sends the test email and says so', function (): void {
    Mail::fake();

    $this->actingAs(channelTester())
        ->post(route('system-settings.test-email'), ['recipient' => 'admin@example.test'])
        ->assertSessionHas('flash.type', 'success');
});

it('reports the mail server error instead of claiming the test was sent', function (): void {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);

    $this->actingAs(channelTester())
        ->post(route('system-settings.test-email'), ['recipient' => 'admin@example.test'])
        ->assertSessionHas('flash.type', 'error')
        ->assertSessionHas('flash.message', fn (string $message): bool => str_contains($message, '127.0.0.1'));
});

it('reports a refused test SMS', function (): void {
    app()->instance(SmsGateway::class, new class implements SmsGateway
    {
        public function send(string $phoneNumber, string $message): bool
        {
            return false;
        }

        public function isConfigured(): bool
        {
            return false;
        }
    });

    $this->actingAs(channelTester())
        ->post(route('system-settings.test-sms'), ['phone' => '+251911000000'])
        ->assertSessionHas('flash.type', 'error')
        ->assertSessionHas('flash.message', fn (string $message): bool => str_contains($message, __('settings.messages.test_sms_refused')));
});

function emailAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['system-settings.view', 'system-settings.manageEmail', 'system-settings.testNotificationChannels']);

    return $user;
}

function emailForm(array $overrides = []): array
{
    return array_merge(['mail_mailer' => 'smtp', 'mail_host' => 'mail.example.test', 'mail_port' => 587, 'mail_encryption' => 'tls'], $overrides);
}

it('refuses a port and encryption pair that can never connect', function (): void {
    $admin = emailAdmin();

    $this->actingAs($admin)->patch(route('system-settings.email.update'), emailForm(['mail_encryption' => 'ssl', 'mail_port' => 587]))
        ->assertSessionHasErrors(['mail_port' => __('settings.messages.mail_port_ssl')]);
    $this->actingAs($admin)->patch(route('system-settings.email.update'), emailForm(['mail_encryption' => 'tls', 'mail_port' => 465]))
        ->assertSessionHasErrors(['mail_encryption' => __('settings.messages.mail_port_465')]);
    $this->actingAs($admin)->patch(route('system-settings.email.update'), emailForm(['mail_encryption' => 'ssl', 'mail_port' => 465]))
        ->assertSessionHasNoErrors();
});

it('shows the mail settings in use and where each comes from, without secrets', function (): void {
    config(['mail.mailers.smtp.host' => 'env-host.example.test', 'mail.mailers.smtp.password' => 'secret-pass']);
    mailSetting('mail_port', '587', 'integer');

    $this->actingAs(emailAdmin())->get(route('system-settings.index', ['tab' => 'email']))->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('emailInEffect.port.source', 'settings')
            ->where('emailInEffect.host.source', 'server')
            ->where('emailInEffect.password.value', 'set')
            ->where('settingGroups.email.fields', fn ($fields): bool => collect($fields)->pluck('key')->intersect(['email_queue_enabled', 'email_rate_limit_per_minute'])->isEmpty()))
        ->assertDontSee('secret-pass');
});

it('sends the test email to the admin when no test recipient is saved', function (): void {
    Mail::fake();
    $admin = emailAdmin();

    $this->actingAs($admin)->post(route('system-settings.test-email'))
        ->assertSessionHas('flash.type', 'success')
        ->assertSessionHas('flash.message', fn (string $message): bool => str_contains($message, $admin->email));
});
