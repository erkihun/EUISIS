<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services\Boarding;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Providers\Domain\Models\Provider;
use Modules\Providers\Domain\Models\ProviderUser;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;
use Modules\Transport\Domain\Models\TransportDevice;

/**
 * Everything one boarding attempt brings to the engine. The engine trusts
 * none of it for authority: the provider comes from the signed-in actor or
 * the device, and every id named here is checked to belong to it.
 */
final readonly class BoardingRequest
{
    public const SOURCE_PROVIDER_PORTAL = 'provider_portal';

    public const SOURCE_ADMIN_TERMINAL = 'admin_transport_scan';

    public const SOURCE_DEVICE_API = 'device_api';

    public const SOURCE_OFFLINE_SYNC = 'offline_sync';

    public function __construct(
        public Provider $provider,
        public string $source,
        public TransportVerificationMethod $method,
        /** The scanned QR value or NFC credential reference; null for a manual exception. */
        public ?string $credential,
        public ?string $tripId = null,
        public ?string $routeId = null,
        public ?string $stopId = null,
        /** Client idempotency key; the engine makes one when the settings allow. */
        public ?string $idempotencyKey = null,
        /** What the device claimed; never used for validity. */
        public ?string $clientRecordedAt = null,
        public ?ProviderUser $providerUser = null,
        public ?User $user = null,
        public ?TransportDevice $device = null,
        /** Manual exception: the employee named by the operator and the reason. */
        public ?string $manualEmployeeId = null,
        public ?string $manualReason = null,
        /** Offline sync: when the device recorded the boarding. */
        public ?Carbon $offlineRecordedAt = null,
    ) {}

    public function isManual(): bool
    {
        return $this->method === TransportVerificationMethod::ManualException;
    }

    public function isOffline(): bool
    {
        return $this->source === self::SOURCE_OFFLINE_SYNC;
    }
}
