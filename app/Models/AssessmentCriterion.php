<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Assessment\InputMode;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What is rated, in a section. Optionally linked to an EPMS catalog competency.
 */
class AssessmentCriterion extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'assessment_criteria';

    protected $fillable = [
        'section_id',
        'form_version_id',
        'competency_id',
        'code',
        'title_en',
        'title_am',
        'description_en',
        'description_am',
        'max_score',
        'weight',
        'is_required',
        'comment_mode',
        'evidence_mode',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:4',
            'weight' => 'decimal:4',
            'is_required' => 'bool',
            'comment_mode' => InputMode::class,
            'evidence_mode' => InputMode::class,
            'sort_order' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(AssessmentFormSection::class, 'section_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(AssessmentRatingOption::class, 'criterion_id')->orderBy('sort_order');
    }
}
