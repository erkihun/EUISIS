<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationDesignStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A manpower estimation methodology (brief §47–48): a whitelisted strategy,
 * its parameters and a rounding rule, versioned like the other design
 * records. City-level; plans and lines record the version they used.
 */
class WorkforcePlanningMethodology extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'lineage_key', 'version_no', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'calculation_type', 'parameters', 'rounding_rule',
        'status', 'effective_from', 'effective_to', 'approved_by', 'approved_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'parameters' => 'array',
            'status' => OrganizationDesignStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'lineage_key', 'lineage_key')->orderBy('version_no');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The published version of this lineage, or this one when none is published. */
    public function inForce(): self
    {
        return self::query()->where('lineage_key', $this->lineage_key)->where('status', OrganizationDesignStatus::Published)->orderByDesc('version_no')->first() ?? $this;
    }
}
