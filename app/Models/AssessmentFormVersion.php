<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Assessment\FormVersionStatus;
use App\Enums\Assessment\PeriodType;
use App\Enums\Assessment\ScoringMethod;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of an assessment form. A DRAFT is edited; once PUBLISHED it
 * never changes, and every assessment keeps the version it used.
 */
class AssessmentFormVersion extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'assessment_form_versions';

    protected $fillable = [
        'form_id',
        'version_no',
        'status',
        'name_en',
        'name_am',
        'description_en',
        'description_am',
        'instructions_en',
        'instructions_am',
        'period_type',
        'scoring_method',
        'max_total_score',
        'overall_contribution_weight',
        'result_scale_id',
        'acknowledgement_required',
        'review_required',
        'effective_from',
        'effective_to',
        'published_by',
        'published_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'status' => FormVersionStatus::class,
            'scoring_method' => ScoringMethod::class,
            'period_type' => PeriodType::class,
            'max_total_score' => 'decimal:4',
            'overall_contribution_weight' => 'decimal:4',
            'acknowledgement_required' => 'bool',
            'review_required' => 'bool',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(AssessmentForm::class, 'form_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(AssessmentFormSection::class, 'form_version_id')->orderBy('sort_order');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(AssessmentCriterion::class, 'form_version_id');
    }

    public function targetRules(): HasMany
    {
        return $this->hasMany(AssessmentTargetRule::class, 'form_version_id');
    }

    public function evaluatorSchemes(): HasMany
    {
        return $this->hasMany(AssessmentEvaluatorScheme::class, 'form_version_id')->orderBy('sort_order');
    }

    public function resultScale(): BelongsTo
    {
        return $this->belongsTo(PerformanceRatingScale::class, 'result_scale_id');
    }

    public function isDraft(): bool
    {
        return $this->status === FormVersionStatus::Draft;
    }
}
