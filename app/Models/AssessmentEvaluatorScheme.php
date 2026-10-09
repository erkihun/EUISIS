<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Assessment\EvaluatorType;
use App\Enums\Assessment\SelectionMethod;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who evaluates under a form version, how many, and with what weight.
 */
class AssessmentEvaluatorScheme extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'assessment_evaluator_schemes';

    protected $fillable = [
        'form_version_id',
        'evaluator_type',
        'required_count',
        'contribution_weight',
        'selection_method',
        'aggregation_method',
        'is_anonymous',
        'requires_review',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'evaluator_type' => EvaluatorType::class,
            'selection_method' => SelectionMethod::class,
            'required_count' => 'integer',
            'contribution_weight' => 'decimal:4',
            'is_anonymous' => 'bool',
            'requires_review' => 'bool',
            'sort_order' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AssessmentFormVersion::class, 'form_version_id');
    }
}
