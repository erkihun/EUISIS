<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Competency rating inside an agreement (the employee self-rates; the manager rating counts).
 */
class EmployeeCompetencyAssessment extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'employee_competency_assessments';

    protected $fillable = [
        'agreement_id',
        'competency_id',
        'weight',
        'self_rating',
        'manager_rating',
        'comment',
        'rated_by',
        'rated_at',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:4',
            'rated_at' => 'datetime',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceAgreement::class, 'agreement_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
