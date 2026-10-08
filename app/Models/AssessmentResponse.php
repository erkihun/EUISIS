<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentResponse extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = [];

    /** Oversight aggregates of the record's cycle are cached; a changed response invalidates them. */
    protected static function booted(): void
    {
        static::saved(fn (AssessmentResponse $response) => \App\Services\Assessment\Oversight\AssessmentCoverageService::bump(
            AssessmentRecord::query()->whereKey($response->assessment_record_id)->value('assessment_cycle_id')));
    }

    protected function casts(): array
    {
        return [
            'answers' => 'array', 'score_snapshot' => 'array', 'submitted_at' => 'datetime', 'is_anonymous' => 'bool', 'submitted_late' => 'bool',
            'due_at' => 'date:Y-m-d', 'started_at' => 'datetime', 'last_saved_at' => 'datetime', 'returned_at' => 'datetime',
            'conflict_declared_at' => 'datetime', 'cancelled_at' => 'datetime', 'last_reminded_at' => 'datetime', 'lock_version' => 'int', 'submission_count' => 'int',
        ];
    }

    /** Statuses in which the evaluator may still change answers. */
    public const EDITABLE = ['not_started', 'in_progress', 'returned'];

    /** Statuses that still count towards the record (a cancelled or recused assignment does not). */
    public const ACTIVE = ['not_started', 'in_progress', 'submitted', 'returned'];

    public function record(): BelongsTo { return $this->belongsTo(AssessmentRecord::class, 'assessment_record_id'); }
    public function evaluator(): BelongsTo { return $this->belongsTo(User::class, 'evaluator_id'); }
    public function scheme(): BelongsTo { return $this->belongsTo(AssessmentEvaluatorScheme::class, 'evaluator_scheme_id'); }
    public function items(): HasMany { return $this->hasMany(AssessmentResponseItem::class, 'response_id'); }
    public function evidence(): HasMany { return $this->hasMany(AssessmentResponseEvidence::class, 'response_id'); }
    public function revisions(): HasMany { return $this->hasMany(AssessmentResponseRevision::class, 'response_id')->orderBy('revision_no'); }

    public function isEditable(): bool { return in_array($this->status, self::EDITABLE, true); }
}
