<?php

declare(strict_types=1);

namespace Modules\Grievance\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Support\Database\ReadOnlyModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Grievance\Contracts\Data\CommitteeType;

/**
 * A standing committee (Regulation 201): Discipline seats disciplinary
 * committees, Performance reuses committees for appeals and calibration, and
 * the statutory rules check their composition. Writes are refused; the
 * Grievance module manages committees.
 */
final class GrievanceCommitteeRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_committees';

    protected function casts(): array
    {
        return [
            'committee_type' => CommitteeType::class,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'approved_at' => 'datetime',
        ];
    }

    /** The name stored in polymorphic columns, as for the Grievance module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\GrievanceCommittee';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(GrievanceCommitteeMemberRecord::class, 'committee_id');
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
