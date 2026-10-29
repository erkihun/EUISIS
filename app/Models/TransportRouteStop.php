<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One ordered stop on a route (GTFS stop + stop_time sequence, folded together). */
class TransportRouteStop extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'transport_route_id', 'stop_code', 'name_en', 'name_am', 'latitude', 'longitude', 'sequence',
        'pickup_allowed', 'dropoff_allowed', 'is_active', 'effective_from', 'effective_to', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'sequence' => 'integer',
            'pickup_allowed' => 'boolean',
            'dropoff_allowed' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeServingOn(Builder $query, string $date): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
