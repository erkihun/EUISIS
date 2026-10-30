<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Transport\Domain\Enums\TransportDeviceStatus;

/**
 * A registered scanner (phone, handheld, validator) with its own bearer
 * credential. Only the SHA-256 of the credential is stored; the plain value
 * is shown once at registration. Revoking a device never touches another.
 */
class TransportDevice extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    public const CREDENTIAL_PREFIX = 'tdev_';

    protected $fillable = [
        'provider_id', 'transport_vehicle_id', 'device_code', 'name', 'status', 'credential_hash', 'credential_prefix',
        'platform', 'software_version', 'last_seen_at', 'last_sync_at', 'last_ip', 'registered_by', 'revoked_at', 'revoked_by',
        'revocation_reason', 'metadata',
    ];

    protected $hidden = ['credential_hash'];

    protected function casts(): array
    {
        return [
            'status' => TransportDeviceStatus::class,
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'transport_vehicle_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TransportTransaction::class);
    }

    public function isActive(): bool
    {
        return $this->status === TransportDeviceStatus::Active && $this->revoked_at === null;
    }

    public static function hashCredential(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
