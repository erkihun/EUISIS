<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GrievanceResponseStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GrievanceResponse extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'grievance_id',
        'committee_id',
        'drafted_by_employee_id',
        'compiled_by_employee_id',
        'approved_by_user_id',
        'rejected_by_user_id',
        'response_body_en',
        'response_body_am',
        'status',
        'rejection_reason',
        'revision_round',
        'drafted_at',
        'compiled_at',
        'approved_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GrievanceResponseStatus::class,
            'revision_round' => 'integer',
            'drafted_at' => 'datetime',
            'compiled_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(GrievanceCommittee::class, 'committee_id');
    }

    public function draftedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'drafted_by_employee_id');
    }

    public function compiledByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'compiled_by_employee_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function decisionLetter(): HasOne
    {
        return $this->hasOne(GrievanceDecisionLetter::class, 'response_id');
    }
}
