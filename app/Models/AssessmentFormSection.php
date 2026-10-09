<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A logical section of a form version.
 */
class AssessmentFormSection extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'assessment_form_sections';

    protected $fillable = [
        'form_version_id',
        'code',
        'title_en',
        'title_am',
        'description_en',
        'description_am',
        'weight',
        'max_score',
        'sort_order',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:4',
            'max_score' => 'decimal:4',
            'sort_order' => 'integer',
            'is_required' => 'bool',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AssessmentFormVersion::class, 'form_version_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(AssessmentCriterion::class, 'section_id')->orderBy('sort_order');
    }
}
