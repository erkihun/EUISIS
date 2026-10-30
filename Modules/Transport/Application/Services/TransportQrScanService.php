<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use App\Models\User;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Providers\Domain\Models\ProviderUser;
use Modules\Transport\Application\Services\Boarding\BoardingRequest;
use Modules\Transport\Application\Services\Boarding\CardCredentialResolver;
use Modules\Transport\Application\Services\Boarding\TransportBoardingService;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;
use Modules\Transport\Domain\Models\TransportTransaction;

/**
 * The attended terminals' entry point (provider portal and admin scan
 * screen). It only shapes the request; every rule lives in
 * TransportBoardingService, shared with the device API and offline sync.
 */
class TransportQrScanService
{
    public function __construct(private readonly TransportBoardingService $engine, private readonly ProviderDirectory $providers) {}

    /**
     * @param  array{qr_token?: string|null, verification_method?: string|null, scan_nonce?: string|null, transport_route_id?: string|null, transport_trip_id?: string|null, transport_route_stop_id?: string|null, scanned_at?: string|null, manual_employee_id?: string|null, manual_reason?: string|null}  $payload
     * @return array{accepted: bool, result_code: string, transaction: TransportTransaction|null, message_key: string, warnings: array}
     */
    public function process(ProviderUser $providerUser, array $payload): array
    {
        // hasService is false when the user's provider is missing or archived.
        abort_unless($providerUser->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $this->engine->board($this->request((string) $providerUser->provider_id, $payload, BoardingRequest::SOURCE_PROVIDER_PORTAL, providerUser: $providerUser))->toArray();
    }

    /**
     * @param  array{qr_token?: string|null, verification_method?: string|null, scan_nonce?: string|null, transport_route_id?: string|null, transport_trip_id?: string|null, transport_route_stop_id?: string|null, scanned_at?: string|null, manual_employee_id?: string|null, manual_reason?: string|null}  $payload
     * @return array{accepted: bool, result_code: string, transaction: TransportTransaction|null, message_key: string, warnings: array}
     */
    public function processForAdmin(string $providerId, array $payload, ?User $user = null): array
    {
        abort_unless($this->providers->hasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));

        return $this->engine->board($this->request($providerId, $payload, BoardingRequest::SOURCE_ADMIN_TERMINAL, user: $user))->toArray();
    }

    /** @param  array{result_code: string, message_key: string}  $result */
    public function message(array $result): string
    {
        $key = 'transport.reasons.'.$result['result_code'];

        return trans()->has($key) ? __($key) : __($result['message_key']);
    }

    /** @param  array<string, mixed>  $payload */
    private function request(string $providerId, array $payload, string $source, ?ProviderUser $providerUser = null, ?User $user = null): BoardingRequest
    {
        $credential = isset($payload['qr_token']) && $payload['qr_token'] !== '' ? (string) $payload['qr_token'] : null;
        $manual = ($payload['manual_employee_id'] ?? null) !== null && $credential === null;

        $method = match (true) {
            $manual => TransportVerificationMethod::ManualException,
            ($payload['verification_method'] ?? null) === 'nfc' => TransportVerificationMethod::Nfc,
            ($payload['verification_method'] ?? null) === 'qr' => TransportVerificationMethod::Qr,
            default => CardCredentialResolver::methodFor((string) $credential),
        };

        return new BoardingRequest(
            providerId: $providerId,
            source: $source,
            method: $method,
            credential: $credential,
            tripId: $payload['transport_trip_id'] ?? null,
            routeId: $payload['transport_route_id'] ?? null,
            stopId: $payload['transport_route_stop_id'] ?? null,
            idempotencyKey: $payload['scan_nonce'] ?? null,
            clientRecordedAt: $payload['scanned_at'] ?? null,
            providerUser: $providerUser,
            user: $user,
            manualEmployeeId: $manual ? (string) $payload['manual_employee_id'] : null,
            manualReason: $manual ? ($payload['manual_reason'] ?? null) : null,
        );
    }
}
