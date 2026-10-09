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
 * Whether employees of an organization may eat in a cafeteria network, and
 * where: the primary cafeteria only, or every allowed location in the network.
 * Sharing a policy or a parent cafeteria never implies this record.
 */
class OrganizationCafeteriaAccess extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'organization_cafeteria_access';

    protected $fillable = [
        'organization_id',
        'cafeteria_service_network_id',
        'primary_cafeteria_id',
        'allow_cross_location_usage',
        'effective_from',
        'effective_to',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
        'ended_by',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'allow_cross_location_usage' => 'boolean',
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

    public function network(): BelongsTo
    {
        return $this->belongsTo(CafeteriaServiceNetwork::class, 'cafeteria_service_network_id');
    }

    public function primaryCafeteria(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'primary_cafeteria_id');
    }

    public function locationExceptions(): HasMany
    {
        return $this->hasMany(OrganizationCafeteriaLocationAccess::class);
    }

    /** @param Builder<self> $query */
    public function scopeEffectiveOn(Builder $query, Carbon $date): Builder
    {
        return $query->where('status', CafeteriaGrantStatus::Active->value)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()));
    }
}
