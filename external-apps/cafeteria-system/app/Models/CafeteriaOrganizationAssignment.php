<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links an EUISIS organization to a cafeteria for a date range.
 *
 * The organization is held by CODE plus a name snapshot rather than a foreign
 * key: organizations live in EUISIS, and this application has no access to that
 * database. The snapshot keeps historical transactions readable even if the
 * organization is later renamed upstream.
 */
class CafeteriaOrganizationAssignment extends Model
{
    use HasUuids;

    protected $fillable = [
        'cafeteria_id',
        'organization_code',
        'organization_name_snapshot',
        'organization_type_snapshot',
        'source_system_organization_id',
        'status',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * Assignments in force on the given date: active, started, and either
     * open-ended or not yet expired.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEffectiveOn(Builder $query, ?string $date = null): Builder
    {
        $on = $date ?? now()->toDateString();

        return $query
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $on)
            ->where(function (Builder $inner) use ($on): void {
                $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on);
            });
    }

    public function isEffectiveOn(?string $date = null): bool
    {
        $on = $date ?? now()->toDateString();

        if ($this->status !== 'active') {
            return false;
        }

        if ($this->effective_from !== null && $this->effective_from->toDateString() > $on) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->toDateString() >= $on;
    }

    /** @return BelongsTo<Cafeteria, $this> */
    public function cafeteria(): BelongsTo
    {
        return $this->belongsTo(Cafeteria::class, 'cafeteria_id');
    }
}
