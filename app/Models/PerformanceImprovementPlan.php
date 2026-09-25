<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\DevelopmentStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Support plan for an employee below the configured threshold. Supportive, never an automatic sanction. Confidential HR data.
 */
class PerformanceImprovementPlan extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_improvement_plans';

    protected $attributes = ['status' => 'DRAFT'];

    protected $fillable = [
        'employee_id',
        'agreement_id',
        'result_id',
        'organization_id',
        'identified_gap',
        'required_improvement',
        'support_action',
        'training',
        'manager_support',
        'start_date',
        'end_date',
        'review_dates',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'review_dates' => 'array',
            'status' => DevelopmentStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceAgreement::class, 'agreement_id');
    }
}
