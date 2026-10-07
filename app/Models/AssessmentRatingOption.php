<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A selectable rating of a criterion, with its decimal score.
 */
class AssessmentRatingOption extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'assessment_rating_options';

    protected $fillable = [
        'criterion_id',
        'label_en',
        'label_am',
        'description_en',
        'description_am',
        'score',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(AssessmentCriterion::class, 'criterion_id');
    }
}
