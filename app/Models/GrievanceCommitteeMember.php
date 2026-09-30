<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceCommitteeRole;
use App\Models\Concerns\HasUuidPrimaryKey;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A committee term of one employee. History is kept by effective dates: a
 * member leaving gets effective_to/end_reason, never a delete, so past cases
 * still show who served. `role` stays a plain string because EPMS writes it
 * directly; roleEnum() maps it (including the first module's "secretary").
 */
class GrievanceCommitteeMember extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'committee_id',
        'employee_id',
        'role',
        'effective_from',
        'effective_to',
        'status',
        'appointed_by',
        'appointment_reference',
        'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(GrievanceCommittee::class, 'committee_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function appointedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appointed_by');
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

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->effective_from === null || $this->effective_from->lte(now()))
            && ($this->effective_to === null || $this->effective_to->gte(now()->startOfDay()));
    }

    /**
     * Members serving on a date: active and inside their term.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeServingOn(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->toDateString();

        return $query->where($query->qualifyColumn('status'), 'active')
            ->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('effective_from'))->orWhereDate($q->qualifyColumn('effective_from'), '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('effective_to'))->orWhereDate($q->qualifyColumn('effective_to'), '>=', $day));
    }
}
