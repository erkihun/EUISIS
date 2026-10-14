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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One boarding scan. The server clock dates every scan (a client-supplied
 * time only goes to metadata), a repeated nonce replays the provider's own
 * earlier answer, and an accepted boarding on a trip is counted against the
 * trip's capacity under a row lock.
 */
class TransportQrScanService
{
    /** Trips that can still take passengers. */
    public const BOARDABLE_TRIP_STATUSES = ['scheduled', 'boarding'];

    public function __construct(
        private readonly CardQrPayloadService $qrPayloadService,
        private readonly TransportEligibilityService $eligibility,
        private readonly EmployeeServiceEligibilityService $serviceEligibility,
    ) {}

    /**
     * @param  array{qr_token: string, scan_nonce?: string|null, transport_route_id?: string|null, transport_trip_id?: string|null, scanned_at?: string|null}  $payload
     * @return array{accepted: bool, result_code: string, transaction: TransportTransaction|null, message_key: string}
     */
    public function process(ProviderUser $providerUser, array $payload): array
    {
        $provider = $providerUser->provider;
        abort_if($provider === null || ! $providerUser->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $this->processForProvider($provider, $payload, 'provider_portal', $providerUser->id);
    }

    /**
     * @param  array{qr_token: string, scan_nonce?: string|null, transport_route_id?: string|null, transport_trip_id?: string|null, scanned_at?: string|null}  $payload
     * @return array{accepted: bool, result_code: string, transaction: TransportTransaction|null, message_key: string}
     */
    public function processForAdmin(Provider $provider, array $payload): array
    {
        abort_unless($provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $this->processForProvider($provider, $payload, 'admin_transport_scan');
    }

    /**
     * @param  array{qr_token: string, scan_nonce?: string|null, transport_route_id?: string|null, transport_trip_id?: string|null, scanned_at?: string|null}  $payload
     * @return array{accepted: bool, result_code: string, transaction: TransportTransaction|null, message_key: string}
     */
    private function processForProvider(Provider $provider, array $payload, string $source, ?string $providerUserId = null): array
    {
        $nonce = $payload['scan_nonce'] ?? null;

        if ($nonce !== null && ($replay = $this->replay($provider, $nonce)) !== null) {
            return $replay;
        }

        $nonce ??= (string) Str::uuid();
        $scannedAt = now();

        $trip = $this->providerTrip($provider->id, $payload['transport_trip_id'] ?? null);
        if (($payload['transport_trip_id'] ?? null) !== null && $trip === null) {
            return $this->refuse('trip_not_found');
        }

        $route = $trip?->route ?? $this->providerRoute($provider->id, $payload['transport_route_id'] ?? null);
        if (($payload['transport_route_id'] ?? null) !== null && ($route === null || $route->id !== $payload['transport_route_id'])) {
            // Another provider's route, or a route that is not this trip's.
            return $this->refuse($trip === null ? 'route_not_found' : 'trip_route_mismatch');
        }

        $card = $this->resolveCard($payload['qr_token']);
        if ($card === null || $card->employee === null) {
            return $this->refuse('card_not_found');
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

        try {
            $transaction = DB::transaction(function () use ($provider, $employee, $card, $route, $trip, $eligibility, $scannedAt, $nonce, $payload, $providerUserId, $source): TransportTransaction {
                $resultCode = $eligibility['eligible']
                    ? ($eligibility['reason'] ?? 'transport_scan_accepted')
                    : $eligibility['reason'];

                if ($eligibility['eligible'] && $trip !== null) {
                    $resultCode = $this->tripRefusal($trip, $employee->id, $scannedAt->toDateString()) ?? $resultCode;
                }

                $accepted = $eligibility['eligible'] && ! in_array($resultCode, ['trip_not_boardable', 'trip_not_today', 'trip_full', 'already_boarded'], true);

                if ($accepted && $trip !== null) {
                    TransportTrip::query()->whereKey($trip->id)->increment('boarded_count');
                }

                return TransportTransaction::query()->create([
                    'provider_id' => $provider->id,
                    'employee_id' => $employee->id,
                    'id_card_id' => $card->id,
                    'transport_pass_id' => $eligibility['pass']?->id,
                    'transport_route_id' => $route?->id,
                    'transport_trip_id' => $trip?->id,
                    'scanned_at' => $scannedAt,
                    'transaction_date' => $scannedAt->toDateString(),
                    'status' => $accepted ? 'accepted' : 'rejected',
                    'result_code' => $resultCode,
                    'rejection_reason' => $accepted ? null : $resultCode,
                    'scan_nonce' => $nonce,
                    'qr_reference_hash' => hash('sha256', $payload['qr_token']),
                    'scanned_by_provider_user_id' => $providerUserId,
                    'metadata' => array_filter([
                        'source' => $source,
                        // What the scanning device claimed; never used for validity or billing.
                        'client_scanned_at' => $payload['scanned_at'] ?? null,
                    ]),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The same nonce raced in from a second request: answer with the first.
            return $this->replay($provider, $nonce) ?? $this->refuse('scan_nonce_conflict');
        }

        $transaction->load(['employee.currentAssignment.organization', 'route', 'trip', 'pass']);
        $accepted = $transaction->status === 'accepted';

        return [
            'accepted' => $accepted,
            'result_code' => (string) $transaction->result_code,
            'transaction' => $transaction,
            'message_key' => $accepted ? 'transport.scan_accepted' : 'transport.scan_rejected',
        ];
    }

    /**
     * The earlier answer for a repeated nonce. A nonce another provider used
     * is refused without revealing that provider's transaction.
     *
     * @return array{accepted: bool, result_code: string, transaction: TransportTransaction|null, message_key: string}|null
     */
    private function replay(Provider $provider, string $nonce): ?array
    {
        $existing = TransportTransaction::query()
            ->with(['employee.currentAssignment.organization', 'route', 'trip', 'pass'])
            ->where('scan_nonce', $nonce)
            ->first();

        if ($existing === null) {
            return null;
        }

        if ($existing->provider_id !== $provider->id) {
            return $this->refuse('scan_nonce_conflict');
        }

        return [
            'accepted' => $existing->status === 'accepted',
            'result_code' => 'duplicate_scan_nonce',
            'transaction' => $existing,
            'message_key' => 'transport.duplicate_scan',
        ];
    }

    /** Why the trip cannot take this passenger now, or null. Locks the trip row. */
    private function tripRefusal(TransportTrip $trip, string $employeeId, string $date): ?string
    {
        $locked = TransportTrip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, self::BOARDABLE_TRIP_STATUSES, true)) {
            return 'trip_not_boardable';
        }

        if ($locked->trip_date->toDateString() !== $date) {
            return 'trip_not_today';
        }

        $alreadyBoarded = TransportTransaction::query()
            ->where('transport_trip_id', $locked->id)
            ->where('employee_id', $employeeId)
            ->where('status', 'accepted')
            ->exists();

        if ($alreadyBoarded) {
            return 'already_boarded';
        }

        if ($locked->capacity !== null && $locked->boarded_count >= $locked->capacity) {
            return 'trip_full';
        }

        return null;
    }

    /**
     * What the operator reads: the reason for this result code where there
     * is one (transport.reasons.*), else the general accepted/rejected line.
     *
     * @param  array{result_code: string, message_key: string}  $result
     */
    public function message(array $result): string
    {
        $key = 'transport.reasons.'.$result['result_code'];

        return trans()->has($key) ? __($key) : __($result['message_key']);
    }

    /** @return array{accepted: false, result_code: string, transaction: null, message_key: string} */
    private function refuse(string $code): array
    {
        return ['accepted' => false, 'result_code' => $code, 'transaction' => null, 'message_key' => 'transport.scan_rejected'];
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
            ->first();
    }

    private function providerTrip(string $providerId, ?string $tripId): ?TransportTrip
    {
        return $tripId === null ? null : TransportTrip::query()
            ->with('route')
            ->where('provider_id', $providerId)
            ->whereKey($tripId)
            ->first();
    }
}
