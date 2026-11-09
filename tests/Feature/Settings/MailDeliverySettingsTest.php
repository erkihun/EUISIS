<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

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
        ->assertInertia(fn (AssertableInertia $page) => $page
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

/** What a queue worker does before each job. */
function startQueuedJob(): void
{
    event(new JobProcessing('sync', new SyncJob(app(), json_encode(['uuid' => 'test', 'displayName' => 'Test', 'job' => 'Test', 'data' => []]), 'sync', 'default')));
}

it('lets a running queue worker send with email settings saved after it started', function (): void {
    mailSetting('mail_from_address', 'old@example.test', 'email');
    mailSetting('mail_host', 'old-smtp.example.test');
    applyMailSettings();
    $mailerBefore = Mail::mailer('smtp');

    // An administrator saves new settings while the worker keeps running.
    mailSetting('mail_from_address', 'new@example.test', 'email');
    mailSetting('mail_host', 'new-smtp.example.test');
    app(SystemSettingsService::class)->clearCache();
    startQueuedJob();

    expect(config('mail.from.address'))->toBe('new@example.test')
        ->and(config('mail.mailers.smtp.host'))->toBe('new-smtp.example.test')
        // The mailer built with the old host is dropped, so the next message connects anew.
        ->and(Mail::mailer('smtp'))->not->toBe($mailerBefore);
});

it('leaves the mail configuration alone between jobs while the settings are unchanged', function (): void {
    applyMailSettings();
    $mailer = Mail::mailer('smtp');
    config(['mail.default' => 'array']);

    startQueuedJob();

    expect(config('mail.default'))->toBe('array')
        ->and(Mail::mailer('smtp'))->toBe($mailer);
});

it('falls back to the server configuration, not an earlier saved value, when a field is cleared', function (): void {
    $serverHost = config('mail.mailers.smtp.host');
    mailSetting('mail_host', 'saved.example.test');
    applyMailSettings();
    expect(config('mail.mailers.smtp.host'))->toBe('saved.example.test');

    mailSetting('mail_host', null);
    app(SystemSettingsService::class)->clearCache();
    startQueuedJob();

    expect(config('mail.mailers.smtp.host'))->toBe((string) $serverHost);
});

it('says the test email was not sent when the mailer only writes to the log', function (): void {
    config(['mail.default' => 'log']);

    $this->actingAs(channelTester())
        ->post(route('system-settings.test-email'), ['recipient' => 'admin@example.test'])
        ->assertSessionHas('flash.type', 'warning')
        ->assertSessionHas('flash.message', __('settings.messages.test_email_not_sent', ['mailer' => 'log']));

    expect(AuditLog::query()->where('event_type', AuditEventType::NotificationChannelTested)->sole()->new_values['delivered'])->toBeFalse();
});

it('removes a saved mail password only when asked, and keeps it on a blank entry', function (): void {
    $admin = emailAdmin();
    $password = fn (): ?string => SystemSetting::query()->where('group', 'email')->where('key', 'mail_password')->value('value');

    $this->actingAs($admin)->patch(route('system-settings.email.update'), emailForm(['mail_username' => 'relay@example.test', 'mail_password' => 'app-password']))
        ->assertSessionHasNoErrors();
    expect(Crypt::decryptString((string) $password()))->toBe('app-password');

    // A blank entry is "keep the saved value".
    $this->actingAs($admin)->patch(route('system-settings.email.update'), emailForm(['mail_password' => '']))->assertSessionHasNoErrors();
    expect($password())->not->toBeNull();

    $this->actingAs($admin)->patch(route('system-settings.email.update'), emailForm(['clear' => ['mail_username', 'mail_password']]))
        ->assertSessionHasNoErrors();
    expect($password())->toBeNull()
        ->and(SystemSetting::query()->where('group', 'email')->where('key', 'mail_username')->value('value'))->toBeNull()
        ->and(AuditLog::query()->where('event_type', AuditEventType::SettingUpdated)->latest('created_at')->latest('id')->first()->new_values['values']['mail_password'])->toBe('not_configured');
});

it('only removes saved mail credentials', function (): void {
    $this->actingAs(emailAdmin())->patch(route('system-settings.email.update'), emailForm(['clear' => ['mail_host']]))
        ->assertSessionHasErrors('clear.0');
});
