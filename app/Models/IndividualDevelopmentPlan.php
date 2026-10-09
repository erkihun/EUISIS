<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\DevelopmentStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Individual development plan (IDP).
 */
class IndividualDevelopmentPlan extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'individual_development_plans';

    protected $attributes = ['status' => 'DRAFT'];

    protected $fillable = [
        'employee_id',
        'cycle_id',
        'agreement_id',
        'competency_id',
        'competency_gap',
        'development_objective',
        'training',
        'coaching',
        'expected_outcome',
        'due_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'status' => DevelopmentStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
