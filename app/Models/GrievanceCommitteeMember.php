<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function isChairperson(): bool
    {
        return $this->role === 'chairperson';
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->effective_to === null || $this->effective_to->isFuture());
    }
}
