<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Settings › Email in effect: a value saved on the settings page replaces the
 * server's .env value for the mailer, its SMTP connection and the sender, as
 * in EUISIS. A blank field keeps the .env value.
 */
final class MailSettings
{
    public function __construct(private readonly CoreSettings $settings) {}

    public function apply(): void
    {
        $value = fn (string $key): mixed => $this->settings->configured("email.{$key}") ? $this->settings->get("email.{$key}") : null;
        $config = array_filter([
            'mail.default' => $value('mail_mailer'),
            'mail.mailers.smtp.host' => $value('mail_host'),
            'mail.mailers.smtp.port' => $value('mail_port'),
            'mail.mailers.smtp.username' => $value('mail_username'),
            'mail.mailers.smtp.password' => $value('mail_password'),
            'mail.from.address' => $value('mail_from_address'),
            'mail.from.name' => $value('mail_from_name'),
        ], fn (mixed $setting): bool => $setting !== null && $setting !== '');
        $encryption = $value('mail_encryption');
        if (is_string($encryption) && $encryption !== '') {
            // SSL is an implicit TLS connection (smtps); TLS upgrades a plain one; none sends in the clear.
            $config['mail.mailers.smtp.scheme'] = $encryption === 'ssl' ? 'smtps' : 'smtp';
            $config['mail.mailers.smtp.auto_tls'] = $encryption !== 'none';
        }
        config($config);
    }
}
