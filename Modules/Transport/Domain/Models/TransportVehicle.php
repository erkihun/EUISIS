<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Providers\Domain\Models\Provider;

class TransportVehicle extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'provider_id',
        'vehicle_code',
        'plate_number',
        'vehicle_type',
        'capacity',
        'standing_capacity',
        'inspection_expires_at',
        'insurance_expires_at',
        'permit_expires_at',
        'maintenance_until',
        'status',
        'assigned_route_id',
        'model',
        'year',
        'color',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'standing_capacity' => 'integer',
            'year' => 'integer',
            'inspection_expires_at' => 'date:Y-m-d',
            'insurance_expires_at' => 'date:Y-m-d',
            'permit_expires_at' => 'date:Y-m-d',
            'maintenance_until' => 'date:Y-m-d',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'assigned_route_id');
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(TransportDriver::class, 'assigned_vehicle_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(TransportDevice::class, 'transport_vehicle_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(TransportTrip::class, 'transport_vehicle_id');
    }

    /** Seats plus approved standing places; null when no capacity is recorded. */
    public function approvedCapacity(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return $this->capacity + ($this->standing_capacity ?? 0);
    }

    /** Blocked by maintenance or by a lapsed compliance date the city tracks. */
    public function isAvailableOn(Carbon $date): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return $this->maintenance_until === null || $this->maintenance_until->lt($date);
    }
}
