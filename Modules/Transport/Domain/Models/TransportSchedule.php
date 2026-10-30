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
use Modules\Transport\Domain\Enums\TransportDirection;

/** A recurring planned departure of a route in one direction (the GTFS trip pattern + calendar). */
class TransportSchedule extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'provider_id', 'transport_route_id', 'direction', 'service_days', 'planned_departure', 'planned_arrival',
        'skips_public_holidays', 'default_vehicle_id', 'default_driver_id', 'effective_from', 'effective_to', 'is_active', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'direction' => TransportDirection::class,
            'service_days' => 'array',
            'skips_public_holidays' => 'boolean',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_active' => 'boolean',
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

    public function defaultVehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'default_vehicle_id');
    }

    public function defaultDriver(): BelongsTo
    {
        return $this->belongsTo(TransportDriver::class, 'default_driver_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(TransportTrip::class);
    }

    /** Whether the schedule is in force on the date and lists its weekday. */
    public function runsOn(Carbon $date): bool
    {
        if (! $this->is_active || $this->effective_from->gt($date) || ($this->effective_to !== null && $this->effective_to->lt($date))) {
            return false;
        }

        return in_array($date->isoWeekday(), array_map('intval', $this->service_days ?? []), true);
    }

    /** HH:MM, as the forms post it (PostgreSQL returns HH:MM:SS). */
    public function departureHm(): ?string
    {
        return $this->planned_departure !== null ? substr((string) $this->planned_departure, 0, 5) : null;
    }

    public function arrivalHm(): ?string
    {
        return $this->planned_arrival !== null ? substr((string) $this->planned_arrival, 0, 5) : null;
    }
}
