<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceEvaluationScore extends Model
{
    protected $table = 'court_performance_evaluation_scores';

    protected $fillable = ['evaluation_id', 'criterion_id', 'score', 'comment'];

    protected $casts = ['score' => 'integer'];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(PerformanceEvaluation::class, 'evaluation_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(PerformanceCriterion::class, 'criterion_id');
    }
}
