<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\ReviewStatus;
use App\Enums\Performance\ReviewType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mid-year or year-end review of one agreement.
 */
class PerformanceReview extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_reviews';

    protected $attributes = ['status' => 'DRAFT'];

    protected $fillable = [
        'agreement_id',
        'review_type',
        'employee_self_assessment',
        'achievements',
        'challenges',
        'contributions',
        'development_needs',
        'manager_comment',
        'manager_private_note',
        'at_risk_item_ids',
        'improvement_actions',
        'return_reason',
    ];

    protected $hidden = ['manager_private_note'];

    protected function casts(): array
    {
        return [
            'review_type' => ReviewType::class,
            'status' => ReviewStatus::class,
            'employee_submitted_at' => 'datetime',
            'manager_reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
            'at_risk_item_ids' => 'array',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceAgreement::class, 'agreement_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }
}
