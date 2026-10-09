<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\AmendmentStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Formal target change: original values kept, approval required, new version created.
 */
class PerformanceTargetAmendment extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_target_amendments';

    protected $attributes = ['status' => 'PENDING'];

    protected $fillable = [
        'subject_type',
        'subject_id',
        'original_values',
        'proposed_values',
        'reason',
        'effective_date',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'original_values' => 'array',
            'proposed_values' => 'array',
            'effective_date' => 'date',
            'status' => AmendmentStatus::class,
            'decided_at' => 'datetime',
        ];
    }
}
