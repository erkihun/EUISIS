<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Transport\TransportDirection;
use App\Enums\Transport\TransportTripStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One actual (or planned) operation of a route in one direction on one
 * date, by one vehicle. A route is the reusable path; the trip is the
 * service event every boarding is tied to.
 */
class TransportTrip extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'provider_id',
        'transport_route_id',
        'transport_vehicle_id',
        'transport_driver_id',
        'transport_schedule_id',
        'trip_number',
        'direction',
        'trip_date',
        'departure_time',
        'arrival_time',
        'planned_departure_at',
        'planned_arrival_at',
        'actual_started_at',
        'actual_ended_at',
        'status',
        'capacity',
        'boarded_count',
        'expected_riders',
        'distance_km',
        'is_extra',
        'approved_by',
        'notes',
        'completion_notes',
        'cancellation_reason',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    // started_by / completed_by / cancelled_by stamps are not fillable: the
    // trip service writes them with forceFill, never a form.

    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'direction' => TransportDirection::class,
            'status' => TransportTripStatus::class,
            'capacity' => 'integer',
            'boarded_count' => 'integer',
            'expected_riders' => 'integer',
            'distance_km' => 'decimal:2',
            'is_extra' => 'boolean',
            'planned_departure_at' => 'datetime',
            'planned_arrival_at' => 'datetime',
            'actual_started_at' => 'datetime',
            'actual_ended_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TransportSchedule::class, 'transport_schedule_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TransportTransaction::class, 'transport_trip_id');
    }

    public function vehicleChanges(): HasMany
    {
        return $this->hasMany(TransportTripVehicleChange::class, 'transport_trip_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(TransportException::class, 'transport_trip_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(TransportTripPosition::class, 'transport_trip_id');
    }

    /** The capacity the trip is held to: its own, else the vehicle's approved total. */
    public function effectiveCapacity(): ?int
    {
        if ($this->capacity !== null) {
            return $this->capacity;
        }

        $vehicle = $this->relationLoaded('vehicle') ? $this->vehicle : $this->vehicle()->first();

        return $vehicle?->approvedCapacity();
    }

    /** Boardings ÷ capacity, as a whole percentage; null without a capacity. */
    public function utilizationPercent(): ?int
    {
        $capacity = $this->effectiveCapacity();

        return $capacity !== null && $capacity > 0 ? (int) round($this->boarded_count * 100 / $capacity) : null;
    }

    /** EMPTY / LOW / MEDIUM / HIGH / AT_CAPACITY from the actual count, never from a sensor. */
    public function occupancyStatus(): string
    {
        $percent = $this->utilizationPercent();

        return match (true) {
            $this->boarded_count === 0 => 'empty',
            $percent === null => 'unknown',
            $percent >= 100 => 'at_capacity',
            $percent >= 75 => 'high',
            $percent >= 40 => 'medium',
            default => 'low',
        };
    }
}
