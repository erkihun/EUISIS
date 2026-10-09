<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CafeteriaSpecialDayType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CafeteriaSpecialDay extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'special_date',
        'name_en',
        'name_am',
        'day_type',
        'is_open',
        'is_subsidy_day',
        'cafeteria_provider_id',
        'organization_id',
        'open_time',
        'close_time',
        'reason_en',
        'reason_am',
        'is_active',
        'created_by',
        'updated_by',
        'deleted_by',
        'deletion_reason',
    ];

    protected $casts = [
        'special_date'   => 'date',
        'day_type'       => CafeteriaSpecialDayType::class,
        'is_open'        => 'boolean',
        'is_subsidy_day' => 'boolean',
        'is_active'      => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
