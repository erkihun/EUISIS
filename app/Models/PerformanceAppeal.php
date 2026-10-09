<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\AppealDecision;
use App\Enums\Performance\AppealStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Appeal against a released result. Decided by a reused grievance committee of type performance_appeal.
 */
class PerformanceAppeal extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_appeals';

    protected $fillable = [
        'employee_id',
        'result_id',
        'cycle_id',
        'organization_id',
        'committee_id',
        'reason',
        'attachment_path',
        'attachment_name',
        'submitted_at',
    ];

    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return [
            'status' => AppealStatus::class,
            'decision' => AppealDecision::class,
            'decided_score' => 'decimal:4',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(PerformanceResult::class, 'result_id');
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(GrievanceCommittee::class, 'committee_id');
    }
}
