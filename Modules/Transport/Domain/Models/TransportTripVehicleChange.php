<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A replacement bus (or driver) during a trip; the earlier boardings keep their vehicle. */
class TransportTripVehicleChange extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'transport_trip_id', 'from_vehicle_id', 'to_vehicle_id', 'from_driver_id', 'to_driver_id', 'reason', 'changed_at',
        'changed_by_provider_user_id', 'changed_by_user_id',
    ];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'transport_trip_id');
    }

    public function fromVehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'from_vehicle_id');
    }

    public function toVehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'to_vehicle_id');
    }
}
