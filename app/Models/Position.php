<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Position extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'organization_unit_id',
        'occupation_id',
        'job_position_code',
        'old_code',
        'title_en',
        'title_am',
        'bpr_name',
        'code',
        'description_en',
        'description_am',
        'grade_level',
        'job_family',
        'is_active',
        'effective_from',
        'effective_to',
        'metadata',
        'deleted_by',
        'deletion_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function isSelectable(?Carbon $onDate = null): bool
    {
        $onDate ??= now();

        if (! $this->is_active) {
            return false;
        }

        if ($this->effective_from !== null && $this->effective_from->isAfter($onDate)) {
            return false;
        }

        return ! ($this->effective_to !== null && $this->effective_to->isBefore($onDate));
    }
}
