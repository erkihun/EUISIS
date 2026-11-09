<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Modules\ServiceMonitoring\Domain\Models\SubmissionHistory;

/**
 * The submission state machine (D9). Every move is checked against the
 * allowed transitions, refused in a locked period, and written to the
 * append-only history. Nothing else changes `status`.
 *
 *   draft     → submitted
 *   returned  → submitted   (resubmission)
 *   submitted → returned | accepted
 *   accepted  → superseded  (when the next revision is accepted)
 */
class SubmissionStateMachine
{
    /** @var array<string, list<SubmissionStatus>> */
    private const ALLOWED = [
        'draft' => [SubmissionStatus::Submitted],
        'returned' => [SubmissionStatus::Submitted],
        'submitted' => [SubmissionStatus::Returned, SubmissionStatus::Accepted],
        'accepted' => [SubmissionStatus::Superseded],
        'superseded' => [],
    ];

    public static function allows(SubmissionStatus $from, SubmissionStatus $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value], true);
    }

    public function move(Submission $submission, SubmissionStatus $to, ?User $actor, ?string $reason = null): void
    {
        $from = $submission->status;
        if (! self::allows($from, $to)) {
            throw new LogicException("Submission {$submission->id} cannot move from {$from->value} to {$to->value}.");
        }
        if ($submission->period->isLocked()) {
            throw ValidationException::withMessages(['period' => __('service-monitoring.validation.period_locked')]);
        }

        $submission->status = $to;
        $submission->save();

        $this->record($submission, $from, $to, $actor, $reason);
    }

    public function record(Submission $submission, ?SubmissionStatus $from, SubmissionStatus $to, ?User $actor, ?string $reason = null): void
    {
        SubmissionHistory::query()->create([
            'submission_id' => $submission->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
