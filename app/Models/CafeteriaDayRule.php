<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeteriaDayRule extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'day_of_week',
        'is_open',
        'is_subsidy_day',
        'open_time',
        'close_time',
        'notes',
        'effective_from',
        'effective_to',
        'is_active',
        'updated_by',
    ];

    protected $casts = [
        'day_of_week'    => 'integer',
        'is_open'        => 'boolean',
        'is_subsidy_day' => 'boolean',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getDayNameAttribute(): string
    {
        return match ($this->day_of_week) {
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
            default => 'Unknown',
        };
    }
}
