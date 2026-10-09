<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommitteeType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A committee of selected employees (not an organization unit). Shared with
 * EPMS, which reuses it for performance appeal / calibration panels and reads
 * `status = active`, member `role = chairperson` and member effective dates.
 */
class GrievanceCommittee extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'organization_id',
        'organization_unit_id',
        'committee_type',
        'name_en',
        'name_am',
        'description_en',
        'description_am',
        'effective_from',
        'effective_to',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'committee_type' => CommitteeType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
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

    public function members(): HasMany
    {
        return $this->hasMany(GrievanceCommitteeMember::class, 'committee_id');
    }

    /**
     * Members serving today. (The first version OR-ed the date check without
     * grouping, which also matched members of other committees.)
     */
    public function activeMembers(): HasMany
    {
        return $this->members()->servingOn(now());
    }

    /** @deprecated First-module assignments; stages reference committees directly. */
    public function assignments(): HasMany
    {
        return $this->hasMany(GrievanceAssignment::class, 'committee_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(GrievanceCaseStage::class, 'committee_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->effective_to === null || $this->effective_to->gte(now()->startOfDay()));
    }

    public function isEffectiveOn(CarbonInterface $date): bool
    {
        return ($this->effective_from === null || $this->effective_from->lte($date))
            && ($this->effective_to === null || $this->effective_to->gte($date->copy()->startOfDay()));
    }
}
