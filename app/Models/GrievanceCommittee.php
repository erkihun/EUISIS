<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommitteeType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrievanceCommittee extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'organization_id',
        'organization_unit_id',
        'committee_type',
        'name_en',
        'name_am',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'committee_type' => CommitteeType::class,
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

    public function activeMembers(): HasMany
    {
        return $this->members()
            ->where('status', 'active')
            ->whereNull('effective_to')
            ->orWhere('effective_to', '>=', now()->toDateString());
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(GrievanceAssignment::class, 'committee_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
