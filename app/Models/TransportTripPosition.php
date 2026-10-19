<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where the vehicle was (never where an employee was). */
class TransportTripPosition extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['transport_trip_id', 'transport_device_id', 'latitude', 'longitude', 'speed_kmh', 'heading', 'recorded_at', 'created_at'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'speed_kmh' => 'decimal:2', 'heading' => 'integer', 'recorded_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'transport_trip_id');
    }
}
