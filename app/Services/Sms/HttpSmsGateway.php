<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SMS transport configured under System Settings > SMS.
 *
 * Ethio Telecom publishes no public HTTP SMS API. An institution reaches its
 * subscribers one of two ways, and each has its own request shape here:
 *
 * - `ethio_telecom`: Ethio Telecom's enterprise bulk SMS is an SMPP link under
 *   contract. SMPP is bridged by an SMS gateway (Kannel or one with the same
 *   `sendsms` interface) bound to Ethio Telecom's SMPC; this class calls the
 *   bridge's HTTP `sendsms` URL with the bridge's user name and password.
 * - `afromessage`: AfroMessage, an aggregator on Ethio Telecom's network,
 *   with the account's token, identifier id and approved sender name.
 *
 * Every other provider value keeps the generic JSON shape
 * `{to, message, sender_id}` with an optional bearer token.
 */
class HttpSmsGateway implements SmsGateway
{
    public const AFROMESSAGE_DEFAULT_URL = 'https://api.afromessage.com/api/send';

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function send(string $phoneNumber, string $message): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('SMS not delivered because the HTTP gateway is not configured.', [
                'to' => $this->mask($phoneNumber),
            ]);

            return false;
        }

        $provider = $this->provider();

        try {
            [$response, $accepted] = match ($provider) {
                'ethio_telecom' => $this->sendThroughSmppBridge($phoneNumber, $message),
                'afromessage' => $this->sendThroughAfroMessage($phoneNumber, $message),
                default => $this->sendGenericJson($phoneNumber, $message),
            };

            if (! $accepted) {
                Log::error('SMS gateway rejected a message.', [
                    'to' => $this->mask($phoneNumber),
                    'provider' => $provider,
                    'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('SMS gateway request failed.', [
                'to' => $this->mask($phoneNumber),
                'provider' => $provider,
                // Connection errors echo the request URL; a provider key or the
                // bridge password in its query string must never reach the log (SBH-004).
                'error' => preg_replace('#(https?://[^\s?]+)\?\S*#i', '$1?[redacted]', $exception->getMessage()),
            ]);

            return false;
        }
    }

    public function isConfigured(): bool
    {
        try {
            return match ($this->provider()) {
                'disabled' => false,
                // The bridge refuses a send without its user name and password.
                'ethio_telecom' => $this->apiUrl() !== '' && $this->accountId() !== '' && $this->apiKey() !== '',
                'afromessage' => $this->apiKey() !== '',
                default => $this->apiUrl() !== '',
            };
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Kannel-style `sendsms`: GET with user, password, from, to and text.
     * `coding=2` sends UCS-2 so Amharic arrives intact; Kannel answers
     * 202 "0: Accepted for delivery" when it queues the message.
     *
     * @return array{0: Response, 1: bool}
     */
    private function sendThroughSmppBridge(string $phoneNumber, string $message): array
    {
        $query = array_filter([
            'username' => $this->accountId(),
            'password' => $this->apiKey(),
            'from' => $this->senderId(),
            // SMPP destination addresses carry no leading "+".
            'to' => ltrim($this->normalizePhone($phoneNumber), '+'),
            'text' => $message,
            'charset' => 'UTF-8',
            'coding' => $this->isGsmText($message) ? null : '2',
        ], fn ($value) => $value !== null && $value !== '');

        $response = $this->request()->get($this->apiUrl(), $query);

        return [$response, $response->successful()];
    }

    /**
     * AfroMessage answers HTTP 200 for refused sends too; only
     * `acknowledge: "success"` means the message was accepted.
     *
     * @return array{0: Response, 1: bool}
     */
    private function sendThroughAfroMessage(string $phoneNumber, string $message): array
    {
        $payload = array_filter([
            'from' => $this->accountId(),
            'sender' => $this->senderId(),
            'to' => $this->normalizePhone($phoneNumber),
            'message' => $message,
        ], fn ($value) => $value !== '');

        $url = $this->apiUrl() !== '' ? $this->apiUrl() : self::AFROMESSAGE_DEFAULT_URL;
        $response = $this->request()->asJson()->withToken($this->apiKey())->post($url, $payload);

        return [$response, $response->successful() && $response->json('acknowledge') === 'success'];
    }

    /** @return array{0: Response, 1: bool} */
    private function sendGenericJson(string $phoneNumber, string $message): array
    {
        $request = $this->request()->asJson();

        if ($this->apiKey() !== '') {
            $request = $request->withToken($this->apiKey());
        }

        $response = $request->post($this->apiUrl(), [
            'to' => $this->normalizePhone($phoneNumber),
            'message' => $message,
            'sender_id' => $this->settings->get('sms', 'sms_sender_id'),
        ]);

        return [$response, $response->successful()];
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout($this->timeout());
    }

    private function provider(): string
    {
        return (string) $this->settings->get('sms', 'sms_provider', 'disabled');
    }

    private function apiUrl(): string
    {
        return trim((string) $this->settings->get('sms', 'sms_api_url', ''));
    }

    private function apiKey(): string
    {
        return trim((string) $this->settings->get('sms', 'sms_api_key', ''));
    }

    private function accountId(): string
    {
        return trim((string) $this->settings->get('sms', 'sms_account_id', ''));
    }

    private function senderId(): string
    {
        return trim((string) $this->settings->get('sms', 'sms_sender_id', ''));
    }

    private function timeout(): int
    {
        return max(1, min(120, (int) $this->settings->get('sms', 'sms_timeout_seconds', 10)));
    }

    /** Plain ASCII fits the default GSM alphabet; anything else (Ethiopic) needs UCS-2. */
    private function isGsmText(string $message): bool
    {
        return preg_match('/^[\x20-\x7E\r\n]*$/', $message) === 1;
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
