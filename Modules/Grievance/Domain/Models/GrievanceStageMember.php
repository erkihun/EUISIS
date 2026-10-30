<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceCommitteeRole;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The committee panel of one case stage, snapshotted when the stage is created so history shows who sat on the case. Recusals and replacements are recorded here, never by editing the committee.
 */
class GrievanceStageMember extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_stage_members';

    protected $fillable = [
        'case_stage_id',
        'employee_id',
        'committee_member_id',
        'role',
        'source',
        'is_active',
        'joined_at',
        'left_at',
        'recused_at',
        'replaces_stage_member_id',
        'added_by',
    ];

    protected function casts(): array
    {
        return [
            'role' => GrievanceCommitteeRole::class,
            'is_active' => 'bool',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'recused_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'case_stage_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function committeeMember(): BelongsTo
    {
        return $this->belongsTo(GrievanceCommitteeMember::class, 'committee_member_id');
    }

    /** A historical panel snapshot does not extend the original appointment. */
    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('recused_at')
            ->where(fn (Builder $q) => $q->where('source', 'replacement')
                ->orWhereHas('committeeMember', fn (Builder $membership) => $membership->servingOn(now())));
    }
}
