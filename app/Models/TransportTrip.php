<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportTrip extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'provider_id',
        'transport_route_id',
        'transport_vehicle_id',
        'transport_driver_id',
        'trip_number',
        'trip_date',
        'departure_time',
        'arrival_time',
        'status',
        'capacity',
        'boarded_count',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'capacity' => 'integer',
            'boarded_count' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'transport_vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(TransportDriver::class, 'transport_driver_id');
    }
}
