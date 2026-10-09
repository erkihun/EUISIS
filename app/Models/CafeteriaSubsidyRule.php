<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class CafeteriaSubsidyRule extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'subsidy_amount',
        'currency',
        'effective_from',
        'effective_to',
        'applies_to',
        'organization_id',
        'employee_type',
        'is_active',
        'exclude_weekends',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'deletion_reason',
    ];

    protected $casts = [
        'subsidy_amount'  => 'decimal:2',
        'effective_from'  => 'date',
        'effective_to'    => 'date',
        'is_active'       => 'boolean',
        'exclude_weekends' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEffectiveOn(Carbon $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->effective_from->gt($date)) {
            return false;
        }

        if ($this->effective_to && $this->effective_to->lt($date)) {
            return false;
        }

        return true;
    }
}
