<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentRecord extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = [];

    /**
     * A record belongs to the oversight cycle with the same assessment type
     * and exactly the same period (docs/assessment-oversight.md).
     */
    protected static function booted(): void
    {
        static::creating(function (AssessmentRecord $record): void {
            $record->assessment_cycle_id ??= AssessmentCycle::query()
                ->where('assessment_type_id', $record->assessment_type_id)
                ->whereDate('period_start', $record->period_start?->toDateString())
                ->whereDate('period_end', $record->period_end?->toDateString())
                ->value('id');
        });
        // Oversight aggregates are cached per cycle; any change to a record invalidates them.
        static::saved(fn (AssessmentRecord $record) => \App\Services\Assessment\Oversight\AssessmentCoverageService::bump($record->assessment_cycle_id));
    }

    protected function casts(): array
    {
        return ['employee_snapshot' => 'array', 'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'reviewed_at' => 'datetime', 'acknowledged_at' => 'datetime', 'percentage' => 'decimal:4', 'contribution' => 'decimal:4',
            'score_breakdown' => 'array', 'finalized_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function version(): BelongsTo { return $this->belongsTo(AssessmentFormVersion::class, 'form_version_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function responses(): HasMany { return $this->hasMany(AssessmentResponse::class); }
    public function cycle(): BelongsTo { return $this->belongsTo(AssessmentCycle::class, 'assessment_cycle_id'); }
    public function evaluatorChanges(): HasMany { return $this->hasMany(AssessmentEvaluatorChange::class)->orderBy('created_at'); }

    /** Finalized: the only state that feeds dashboards, oversight and EPMS. */
    public function isFinalized(): bool { return in_array($this->status, ['reviewed', 'acknowledged'], true); }
}
