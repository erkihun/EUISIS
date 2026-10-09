<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Generic JSON SMS transport configured under System Settings > SMS. */
class HttpSmsGateway implements SmsGateway
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function send(string $phoneNumber, string $message): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('SMS not delivered because the HTTP gateway is not configured.', [
                'to' => $this->mask($phoneNumber),
            ]);

            return false;
        }

        try {
            $request = Http::acceptJson()->asJson()->timeout($this->timeout());
            $apiKey = trim((string) $this->settings->get('sms', 'sms_api_key', ''));

            if ($apiKey !== '') {
                $request = $request->withToken($apiKey);
            }

            $response = $request->post($this->apiUrl(), [
                'to' => $this->normalizePhone($phoneNumber),
                'message' => $message,
                'sender_id' => $this->settings->get('sms', 'sms_sender_id'),
            ]);

            if (! $response->successful()) {
                Log::error('SMS gateway rejected a message.', [
                    'to' => $this->mask($phoneNumber),
                    'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('SMS gateway request failed.', [
                'to' => $this->mask($phoneNumber),
                // Connection errors echo the request URL; a provider key in its
                // query string must never reach the log (SBH-004).
                'error' => preg_replace('#(https?://[^\s?]+)\?\S*#i', '$1?[redacted]', $exception->getMessage()),
            ]);

            return false;
        }
    }

    public function isConfigured(): bool
    {
        try {
            return (string) $this->settings->get('sms', 'sms_provider', 'disabled') !== 'disabled'
                && $this->apiUrl() !== '';
        } catch (Throwable) {
            return false;
        }
    }

    private function apiUrl(): string
    {
        return trim((string) $this->settings->get('sms', 'sms_api_url', ''));
    }

    private function timeout(): int
    {
        return max(1, min(120, (int) $this->settings->get('sms', 'sms_timeout_seconds', 10)));
    }

    private function normalizePhone(string $phoneNumber): string
    {
        $phone = preg_replace('/[\s()-]+/', '', trim($phoneNumber)) ?? trim($phoneNumber);
        $countryCode = trim((string) $this->settings->get('sms', 'sms_default_country_code', '+251'));

        return str_starts_with($phone, '0') && $countryCode !== ''
            ? $countryCode.substr($phone, 1)
            : $phone;
    }

    private function mask(string $phoneNumber): string
    {
        $length = mb_strlen($phoneNumber);

        return $length <= 2
            ? str_repeat('*', $length)
            : str_repeat('*', $length - 2).mb_substr($phoneNumber, -2);
    }
}
