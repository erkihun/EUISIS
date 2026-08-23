<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A day the cafeteria treats as a public holiday.
 *
 * A null provider_id means the holiday is national and applies to every
 * provider; a set provider_id narrows it to one.
 */
class CafeteriaPublicHoliday extends Model
{
    use HasUuids;

    protected $table = 'cafeteria_public_holidays';

    protected $fillable = [
        'provider_id',
        'holiday_date',
        'name_en',
        'name_am',
        'is_recurring',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_recurring' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Active rows visible to one provider, including national ones. */
    public function scopeForProvider(Builder $query, ?string $providerId): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('provider_id')
                ->orWhere('provider_id', $providerId));
    }
}
