<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\IdCard;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\TransportRoute;
use App\Models\TransportTransaction;
use App\Models\TransportTrip;
use App\Services\EmployeeServiceEligibilityService;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransportQrScanService
{
    public function __construct(
        private readonly CardQrPayloadService $qrPayloadService,
        private readonly TransportEligibilityService $eligibility,
        private readonly EmployeeServiceEligibilityService $serviceEligibility,
    ) {}

    /**
     * @param  array{qr_token: string, scan_nonce: string, transport_route_id?: string|null, transport_trip_id?: string|null, scanned_at?: string|null}  $payload
     * @return array{accepted: bool, result_code: string, transaction: TransportTransaction|null, message_key: string}
     */
    public function process(ProviderUser $providerUser, array $payload): array
    {
        $provider = $providerUser->provider;
        abort_if($provider === null || ! $providerUser->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $this->processForProvider($provider, $payload, 'provider_portal', $providerUser->id);
    }

    /**
     * @param  array{qr_token: string, scan_nonce: string, transport_route_id?: string|null, transport_trip_id?: string|null, scanned_at?: string|null}  $payload
     * @return array{accepted: bool, result_code: string, transaction: TransportTransaction|null, message_key: string}
     */
    public function processForAdmin(Provider $provider, array $payload): array
    {
        abort_unless($provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $this->processForProvider($provider, $payload, 'admin_transport_scan');
    }

    /**
     * @param  array{qr_token: string, scan_nonce: string, transport_route_id?: string|null, transport_trip_id?: string|null, scanned_at?: string|null}  $payload
     * @return array{accepted: bool, result_code: string, transaction: TransportTransaction|null, message_key: string}
     */
    private function processForProvider(Provider $provider, array $payload, string $source, ?string $providerUserId = null): array
    {
        $existing = TransportTransaction::query()
            ->with(['employee.currentAssignment.organization', 'route', 'trip', 'pass'])
            ->where('scan_nonce', $payload['scan_nonce'])
            ->first();

        if ($existing !== null) {
            return [
                'accepted' => $existing->status === 'accepted',
                'result_code' => 'duplicate_scan_nonce',
                'transaction' => $existing,
                'message_key' => 'transport.duplicate_scan',
            ];
        }

        $scannedAt = isset($payload['scanned_at']) && $payload['scanned_at'] !== null
            ? Carbon::parse($payload['scanned_at'])
            : now();

        $card = $this->resolveCard($payload['qr_token']);
        $route = $this->providerRoute($provider->id, $payload['transport_route_id'] ?? null);
        $trip = $this->providerTrip($provider->id, $payload['transport_trip_id'] ?? null);

        if ($card === null || $card->employee === null) {
            return ['accepted' => false, 'result_code' => 'card_not_found', 'transaction' => null, 'message_key' => 'transport.scan_rejected'];
        }

        $employee = $card->employee;
        $serviceEligibility = $this->serviceEligibility->check(
            $employee,
            $card,
            'transport',
            providerId: $provider->id,
            attemptedBy: $providerUserId,
        );

        if (! $serviceEligibility['eligible']) {
            return [
                'accepted' => false,
                'result_code' => $serviceEligibility['reason_code'],
                'transaction' => null,
                'message_key' => $serviceEligibility['message_key'],
            ];
        }

        $eligibility = $this->eligibility->check($employee, $card, $provider, $route, $scannedAt);
        $status = $eligibility['eligible'] ? 'accepted' : 'rejected';
        $resultCode = $eligibility['reason'] ?? 'transport_scan_accepted';

        $transaction = DB::transaction(fn (): TransportTransaction => TransportTransaction::query()->create([
            'provider_id' => $provider->id,
            'employee_id' => $employee->id,
            'id_card_id' => $card->id,
            'transport_pass_id' => $eligibility['pass']?->id,
            'transport_route_id' => $route?->id,
            'transport_trip_id' => $trip?->id,
            'scanned_at' => $scannedAt,
            'transaction_date' => $scannedAt->toDateString(),
            'status' => $status,
            'result_code' => $resultCode,
            'rejection_reason' => $eligibility['eligible'] ? null : $resultCode,
            'scan_nonce' => $payload['scan_nonce'],
            'qr_reference_hash' => hash('sha256', $payload['qr_token']),
            'scanned_by_provider_user_id' => $providerUserId,
            'metadata' => ['source' => $source],
        ]));

        $transaction->load(['employee.currentAssignment.organization', 'route', 'trip', 'pass']);

        return [
            'accepted' => $eligibility['eligible'],
            'result_code' => $resultCode,
            'transaction' => $transaction,
            'message_key' => $eligibility['eligible'] ? 'transport.scan_accepted' : 'transport.scan_rejected',
        ];
    }

    private function resolveCard(string $scanValue): ?IdCard
    {
        $publicUuid = $this->qrPayloadService->resolvePublicUuidFromScanValue($scanValue);

        if ($publicUuid === null) {
            return null;
        }

        return IdCard::query()
            ->with(['employee.currentAssignment.organization'])
            ->where('public_card_uuid', $publicUuid)
            ->first();
    }

    private function providerRoute(string $providerId, ?string $routeId): ?TransportRoute
    {
        return $routeId === null ? null : TransportRoute::query()
            ->where('provider_id', $providerId)
            ->whereKey($routeId)
            ->firstOrFail();
    }

    private function providerTrip(string $providerId, ?string $tripId): ?TransportTrip
    {
        return $tripId === null ? null : TransportTrip::query()
            ->where('provider_id', $providerId)
            ->whereKey($tripId)
            ->firstOrFail();
    }
}
