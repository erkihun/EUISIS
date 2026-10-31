<?php

declare(strict_types=1);

namespace Modules\Grievance\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Support\Database\ReadOnlyModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Grievance\Contracts\Data\GrievanceCommitteeRole;

/** A committee member and their term, published with the committee read model. Writes are refused. */
final class GrievanceCommitteeMemberRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_committee_members';

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    /** The name stored in polymorphic columns, as for the Grievance module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\GrievanceCommitteeMember';
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(GrievanceCommitteeRecord::class, 'committee_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function roleEnum(): GrievanceCommitteeRole
    {
        return $this->role === 'secretary'
            ? GrievanceCommitteeRole::Writer
            : (GrievanceCommitteeRole::tryFrom((string) $this->role) ?? GrievanceCommitteeRole::Member);
    }

    public function isChairperson(): bool
    {
        return $this->roleEnum() === GrievanceCommitteeRole::Chairperson;
    }

    /** Members serving on a date: active and inside their term. */
    public function scopeServingOn(Builder $query, CarbonInterface $date): void
    {
        $day = $date->toDateString();
        $query->where($query->qualifyColumn('status'), 'active')
            ->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('effective_from'))->orWhereDate($q->qualifyColumn('effective_from'), '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('effective_to'))->orWhereDate($q->qualifyColumn('effective_to'), '>=', $day));
    }
}
