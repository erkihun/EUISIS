<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\IdCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Providers\Domain\Models\Provider;
use Modules\Providers\Domain\Models\ProviderUser;
use Modules\Transport\Domain\Enums\TransportDirection;
use Modules\Transport\Domain\Enums\TransportSyncState;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;

/**
 * One boarding scan, accepted or refused: the evidence record. Append-only
 * in practice — a correction voids it (voided_at + reason) and never edits
 * or deletes it.
 */
class TransportTransaction extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'provider_id',
        'employee_id',
        'id_card_id',
        'transport_pass_id',
        'transport_route_id',
        'transport_trip_id',
        'transport_vehicle_id',
        'transport_device_id',
        'transport_route_stop_id',
        'direction',
        'verification_method',
        'boarding_leg',
        'eligibility_snapshot',
        'sync_state',
        'offline_recorded_at',
        'scanned_at',
        'transaction_date',
        'status',
        'result_code',
        'rejection_reason',
        'scan_nonce',
        'qr_reference_hash',
        'scanned_by_provider_user_id',
        'recorded_by_user_id',
        'manual_reason',
        'metadata',
    ];

    protected $hidden = [
        'qr_reference_hash',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'offline_recorded_at' => 'datetime',
            'voided_at' => 'datetime',
            'transaction_date' => 'date:Y-m-d',
            'direction' => TransportDirection::class,
            'verification_method' => TransportVerificationMethod::class,
            'sync_state' => TransportSyncState::class,
            'eligibility_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function idCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class);
    }

    public function pass(): BelongsTo
    {
        return $this->belongsTo(TransportPass::class, 'transport_pass_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'transport_trip_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'transport_vehicle_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(TransportDevice::class, 'transport_device_id');
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(TransportRouteStop::class, 'transport_route_stop_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(ProviderUser::class, 'scanned_by_provider_user_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(TransportSettlement::class, 'transport_settlement_id');
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_ACCEPTED && $this->voided_at === null;
    }

    /**
     * Accepted, un-voided boardings whose sync state the settlement trusts.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACCEPTED)
            ->whereNull('voided_at')
            ->whereIn('sync_state', TransportSyncState::settleable());
    }

    public static function legFor(string $date, ?TransportDirection $direction): ?string
    {
        return $direction === null ? null : $date.':'.$direction->value;
    }
}
