<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hearing of a public complaint (ADR 0035): stage 1 the complaint
 * committee, stage 2 the appeal committee, stage 3 the head of the
 * institution. The response is what the complainant reads on the tracking
 * page; the appeal reason is what they wrote when they took it further.
 */
class PublicComplaintStage extends Model
{
    use HasUuidPrimaryKey;

    public const COMMITTEE = 1;

    public const APPEAL_COMMITTEE = 2;

    public const HEAD = 3;

    /** The handling-unit stage that hears each stage number. */
    public const UNIT_STAGES = [self::COMMITTEE => 'first_instance', self::APPEAL_COMMITTEE => 'appeal', self::HEAD => 'head_office'];

    public const DECISIONS = ['answered', 'upheld', 'overturned'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'opened_at' => 'datetime',
            'decided_at' => 'datetime',
            'appeal_deadline_at' => 'datetime',
            'citizen_responded_at' => 'datetime',
            'appeal_reason' => 'encrypted',
            'response' => 'encrypted',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(EmployeeServiceFeedback::class, 'submission_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isDecided(): bool
    {
        return $this->decided_at !== null;
    }

    /** Decided, final for this stage, and the complainant has not yet answered it. */
    public function awaitsComplainant(): bool
    {
        return $this->isDecided() && $this->citizen_outcome === null && $this->stage < self::HEAD;
    }

    public function appealOpen(): bool
    {
        return $this->awaitsComplainant() && ($this->appeal_deadline_at === null || $this->appeal_deadline_at->isFuture());
    }
}
