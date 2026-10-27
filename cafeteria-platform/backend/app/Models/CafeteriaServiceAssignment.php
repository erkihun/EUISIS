<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CafeteriaGrantStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The contractual authorization of a provider to serve an organization: at
 * one cafeteria, across a network, or provider-wide (both NULL). Policies hang
 * off an assignment; access (where employees may eat) is a separate record.
 */
class CafeteriaServiceAssignment extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'organization_id',
        'provider_id',
        'cafeteria_service_network_id',
        'cafeteria_id',
        'effective_from',
        'effective_to',
        'status',
        'notes',
        'assigned_by',
        'updated_by',
        'approved_by',
        'approved_at',
        'ended_by',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'status' => CafeteriaGrantStatus::class,
            'approved_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(CafeteriaServiceNetwork::class, 'cafeteria_service_network_id');
    }

    public function cafeteria(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_id');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(CafeteriaServicePolicy::class);
    }

    /** @param Builder<self> $query */
    public function scopeEffectiveOn(Builder $query, Carbon $date): Builder
    {
        return $query->where('status', CafeteriaGrantStatus::Active->value)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()));
    }
}
