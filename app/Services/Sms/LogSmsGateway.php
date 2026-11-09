<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Placeholder SMS gateway that writes to the log instead of sending.
 *
 * BLOCKER: no SMS provider is integrated. System Settings already carries the
 * provider fields (url, key, sender id, country code), but nothing consumes
 * them, so `sms_provider` is effectively always `disabled`.
 *
 * Consequence for the public ID Checker: the one-time code reaches the
 * employee by EMAIL ONLY. The flow is safe — the code still goes to the card
 * holder, never to the anonymous checker — but an employee with no email on
 * file cannot approve a check until a real gateway is bound here.
 *
 * The message body is logged so the flow is testable in development; a real
 * gateway must not log message contents in production.
 */
class LogSmsGateway implements SmsGateway
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function send(string $phoneNumber, string $message): bool
    {
        Log::warning('SMS not delivered — no provider integrated.', [
            // Masked: an operator needs to recognise the number, not read it.
            'to' => $this->mask($phoneNumber),
            'body_length' => mb_strlen($message),
            'configured_provider' => $this->provider(),
        ]);

        if (app()->environment('local', 'testing')) {
            Log::debug('SMS body (non-production only): '.$message);
        }

        return false;
    }

    public function isConfigured(): bool
    {
        return $this->provider() !== 'disabled';
    }

    private function provider(): string
    {
        try {
            return (string) $this->settings->get('sms', 'sms_provider', 'disabled');
        } catch (Throwable) {
            // Settings table may not exist yet during install or migration.
            return 'disabled';
        }
    }

    /** Keep the last two digits only, so logs cannot rebuild a phone number. */
    private function mask(string $phoneNumber): string
    {
        $length = mb_strlen($phoneNumber);

        return $length <= 2
            ? str_repeat('*', $length)
            : str_repeat('*', $length - 2).mb_substr($phoneNumber, -2);
    }
}
