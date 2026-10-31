<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\CourtStaffMember as User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformanceEvaluation extends Model
{
    protected $table = 'court_performance_evaluations';

    protected $fillable = [
        'evaluated_user_id', 'evaluator_id', 'reviewed_by',
        'period_start', 'period_end', 'period_type',
        'overall_score', 'status', 'notes', 'reviewer_notes', 'reviewed_at',
    ];

    protected $casts = [
        'period_start'  => 'date',
        'period_end'    => 'date',
        'reviewed_at'   => 'datetime',
        'overall_score' => 'float',
    ];

    public function evaluatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_user_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(PerformanceEvaluationScore::class, 'evaluation_id');
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'submitted' => 'bg-blue-50 text-blue-700 border-blue-200',
            'reviewed'  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            default     => 'bg-amber-50 text-amber-700 border-amber-200',
        };
    }

    public function getScoreColorAttribute(): string
    {
        return match(true) {
            $this->overall_score >= 85 => 'text-emerald-600',
            $this->overall_score >= 70 => 'text-blue-600',
            $this->overall_score >= 50 => 'text-amber-600',
            default                    => 'text-red-600',
        };
    }

    public function getScoreLabelAttribute(): string
    {
        return match(true) {
            $this->overall_score >= 85 => __('court::performance.score_labels.excellent'),
            $this->overall_score >= 70 => __('court::performance.score_labels.good'),
            $this->overall_score >= 50 => __('court::performance.score_labels.satisfactory'),
            default                    => __('court::performance.score_labels.needs_improvement'),
        };
    }

    /** Recalculate overall_score from weighted criterion scores and save. */
    public function recalculateScore(): void
    {
        $scores = $this->scores()->with('criterion')->get();

        if ($scores->isEmpty()) {
            $this->overall_score = 0;
            $this->save();
            return;
        }

        $totalWeight    = $scores->sum(fn($s) => $s->criterion->weight ?? 0);
        $weightedPoints = $scores->sum(fn($s) => ($s->score / 10) * 100 * ($s->criterion->weight ?? 0));

        $this->overall_score = $totalWeight > 0
            ? round($weightedPoints / $totalWeight, 2)
            : 0;

        $this->save();
    }
}
