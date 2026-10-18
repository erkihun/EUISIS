<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;

/**
 * System Settings › Email, turned into the mail configuration.
 *
 * A request applies it once at boot. A queue worker boots once and then runs
 * for an hour or more, so it re-applies before each job: without that, queued
 * notification email kept the host, password and sender the worker started
 * with, and a rotated SMTP password failed every queued message until the
 * worker restarted. Mailers already built are dropped when the settings
 * change, so the next message connects with the new ones.
 *
 * Blank settings fall back to the server's own configuration (.env), captured
 * when this object is created, never to a value applied earlier.
 */
final class RuntimeMailSettings
{
    /** @var array<string, mixed> */
    private readonly array $server;

    private ?string $applied = null;

    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly Application $app,
    ) {
        $this->server = [
            'default' => config('mail.default', 'smtp'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port', 587),
            'username' => config('mail.mailers.smtp.username'),
            'password' => config('mail.mailers.smtp.password'),
            'encryption' => config('mail.mailers.smtp.encryption'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
        ];
    }

    /**
     * Applies the saved settings when they differ from what was last applied.
     * Returns whether anything changed.
     */
    public function apply(): bool
    {
        $values = $this->resolve();
        $fingerprint = hash('xxh128', serialize($values));

        if ($fingerprint === $this->applied) {
            return false;
        }

        config($values);
        $this->applied = $fingerprint;

        if ($this->app->resolved('mail.manager')) {
            /** @var MailManager $manager */
            $manager = $this->app->make('mail.manager');
            $manager->forgetMailers();
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function resolve(): array
    {
        $port = (int) $this->settings->get('email', 'mail_port', $this->server['port']);
        $encryption = $this->normalizeEncryption($this->settings->get('email', 'mail_encryption', $this->server['encryption']));
        $appName = (string) $this->settings->get('general', 'application_name', config('app.name'));

        return [
            'mail.default' => (string) $this->settings->get('email', 'mail_mailer', $this->server['default']),
            'mail.mailers.smtp.host' => (string) $this->settings->get('email', 'mail_host', $this->server['host']),
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.username' => $this->settings->get('email', 'mail_username', $this->server['username']),
            'mail.mailers.smtp.password' => $this->settings->get('email', 'mail_password', $this->server['password']),
            'mail.mailers.smtp.encryption' => $encryption,
            // Laravel 12 ignores "encryption" and connects by "scheme", so
            // the setting has to become one, or MAIL_SCHEME in .env wins
            // and can contradict the port chosen here.
            'mail.mailers.smtp.scheme' => $this->scheme($encryption, $port),
            'mail.mailers.smtp.auto_tls' => $encryption !== null,
            'mail.from.address' => (string) $this->settings->get('email', 'mail_from_address', $this->server['from_address']),
            'mail.from.name' => (string) $this->settings->get('email', 'mail_from_name', $this->server['from_name'] ?? $appName),
        ];
    }

    /**
     * SSL, or port 465, is a TLS connection from the first byte ("smtps").
     * TLS on 587 or 25 is a plain connection upgraded with STARTTLS, which
     * the "smtp" scheme does on its own. Implicit TLS on 587 (smtps with
     * port 587) never completes a handshake, and every message fails.
     */
    private function scheme(?string $encryption, int $port): string
    {
        return $encryption === 'ssl' || $port === 465 ? 'smtps' : 'smtp';
    }

    private function normalizeEncryption(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'none') {
            return null;
        }

        return (string) $value;
    }
}
