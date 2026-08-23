<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A date whose normal open/subsidy rules are overridden.
 *
 * `open_day` opens a day that would otherwise be closed (a working Saturday);
 * `no_subsidy` keeps the cafeteria open but withdraws the subsidy.
 */
class CafeteriaSpecialDay extends Model
{
    use HasUuids;

    public const TYPE_OPEN_DAY = 'open_day';

    public const TYPE_NO_SUBSIDY = 'no_subsidy';

    protected $table = 'cafeteria_special_days';

    protected $fillable = [
        'provider_id',
        'special_date',
        'name_en',
        'name_am',
        'day_type',
        'is_open',
        'is_subsidy_day',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'special_date' => 'date',
            'is_open' => 'boolean',
            'is_subsidy_day' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Active rows visible to one provider, including global ones. */
    public function scopeForProvider(Builder $query, ?string $providerId): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('provider_id')
                ->orWhere('provider_id', $providerId));
    }
}
