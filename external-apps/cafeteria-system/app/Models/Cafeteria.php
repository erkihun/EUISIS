<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service point operated by a provider. Employees are served here, and each
 * cafeteria may serve only the organizations assigned to it.
 */
class Cafeteria extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'provider_id',
        'name',
        'code',
        'location',
        'status',
        'daily_capacity',
        'operating_days',
        'opens_at',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'operating_days' => 'array',
            'daily_capacity' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<CafeteriaProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'provider_id');
    }

    /** @return HasMany<CafeteriaOrganizationAssignment, $this> */
    public function organizationAssignments(): HasMany
    {
        return $this->hasMany(CafeteriaOrganizationAssignment::class, 'cafeteria_id');
    }

    /** @return HasMany<CafeteriaUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(CafeteriaUser::class, 'cafeteria_id');
    }

    /** @return HasMany<CafeteriaServiceTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(CafeteriaServiceTransaction::class, 'cafeteria_id');
    }
}
