<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\CommitteeType;
use App\Enums\Performance\AdjustmentStatus;
use App\Enums\Performance\AppealDecision;
use App\Enums\Performance\AppealStatus;
use App\Enums\Performance\ResultStatus;
use App\Models\GrievanceCommittee;
use App\Models\PerformanceAppeal;
use App\Models\PerformanceResult;
use App\Models\User;
use App\Services\Performance\Calculation\Dec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Appeals (docs/epms-workflow.md §7). The employee appeals their own released
 * result within the configured window. A committee of type
 * performance_appeal (reused grievance committee model, 3–5 members:
 * chairperson, secretary/writer, members) decides. A changed score creates a
 * NEW result revision; the finalized one is kept, so history is never lost.
 */
final class PerformanceAppealService
{
    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
        private readonly EpmsSettings $settings,
        private readonly EmployeeScoreCalculator $calculator,
        private readonly PerformanceNotifier $notifier,
    ) {}

    public function file(PerformanceResult $result, string $reason, ?UploadedFile $attachment, User $actor): PerformanceAppeal
    {
        $this->access->authorize($actor->can('performance_appeals.create') && $this->access->isOwn($actor, $result->agreement));

        if ($result->status !== ResultStatus::Released || ! $result->is_current) {
            throw ValidationException::withMessages(['result' => __('performance.errors.appeal_not_released')]);
        }
        $deadline = $result->released_at?->copy()->addDays($this->settings->appealWindowDays())->endOfDay();
        if ($deadline === null || now()->gt($deadline)) {
            throw ValidationException::withMessages(['result' => __('performance.errors.appeal_window_closed')]);
        }
        if (PerformanceAppeal::query()->where('result_id', $result->getKey())->whereIn('status', [AppealStatus::Submitted->value, AppealStatus::UnderReview->value])->exists()) {
            throw ValidationException::withMessages(['result' => __('performance.errors.appeal_open')]);
        }

        $committee = GrievanceCommittee::query()
            ->where('committee_type', CommitteeType::PerformanceAppeal->value)
            ->where('organization_id', $result->organization_id)->where('status', 'active')
            ->orderByRaw('organization_unit_id is null')->first();

        return DB::transaction(function () use ($result, $reason, $attachment, $actor, $committee): PerformanceAppeal {
            $path = $attachment?->storeAs('performance-appeals', Str::uuid7().'.'.$attachment->extension(), 'local');

            $appeal = new PerformanceAppeal([
                'employee_id' => $result->employee_id,
                'result_id' => $result->getKey(),
                'cycle_id' => $result->cycle_id,
                'organization_id' => $result->organization_id,
                'committee_id' => $committee?->getKey(),
                'reason' => $reason,
                'attachment_path' => $path,
                'attachment_name' => $attachment?->getClientOriginalName(),
                'submitted_at' => now(),
            ]);
            $appeal->forceFill(['appeal_no' => $this->nextNumber(), 'status' => AppealStatus::Submitted])->save();

            $this->audit->record(AuditEventType::PerformanceAppealFiled, $actor, $appeal, ['appeal_no' => $appeal->appeal_no, 'result' => $result->getKey()]);

            return $appeal;
        });
    }

    public function canDecide(User $user, PerformanceAppeal $appeal): bool
    {
        if (! $user->can('performance_appeals.decide') || $this->access->employeeOf($user)?->getKey() === $appeal->employee_id) {
            return false;
        }

        return $appeal->committee_id !== null
            ? $this->access->isCommitteeMember($user, $appeal->committee)
            : $this->access->inScope($user, 'performance_appeals.decide', $appeal->organization_id);
    }

    public function decide(PerformanceAppeal $appeal, AppealDecision $decision, string $reason, ?string $newScore, User $actor): PerformanceAppeal
    {
        $this->access->authorize($this->canDecide($actor, $appeal));
        if (in_array($appeal->status, [AppealStatus::Decided, AppealStatus::Withdrawn], true)) {
            throw ValidationException::withMessages(['appeal' => __('performance.errors.stale')]);
        }

        $changes = $decision !== AppealDecision::Rejected && $newScore !== null && $newScore !== '';
        if ($changes) {
            $value = Dec::of($newScore);
            if ($value === null || $value->isNegative() || $value->isGreaterThan(200)) {
                throw ValidationException::withMessages(['decided_score' => __('performance.errors.score_range')]);
            }
        }

        return DB::transaction(function () use ($appeal, $decision, $reason, $newScore, $changes, $actor): PerformanceAppeal {
            if ($changes) {
                $this->revise($appeal->result, Dec::str(Dec::of($newScore)), $reason, $actor, $appeal);
            }

            $appeal->forceFill([
                'status' => AppealStatus::Decided,
                'decision' => $decision,
                'decision_reason' => $reason,
                'decided_score' => $changes ? Dec::str(Dec::of($newScore)) : null,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
            ])->save();

            $this->audit->record(AuditEventType::PerformanceAppealDecided, $actor, $appeal, ['decision' => $decision->value, 'decided_score' => $appeal->decided_score], null, $reason);
            $this->notifier->toEmployee($appeal->employee, 'appeal_decided');

            return $appeal;
        });
    }

    /** New revision; the previous finalized result stays untouched except is_current. */
    private function revise(PerformanceResult $old, string $score, string $reason, User $actor, PerformanceAppeal $appeal): PerformanceResult
    {
        $rating = $this->calculator->ratingFor($score);
        $snapshot = $old->snapshot_json;
        $snapshot['appeal'] = ['appeal_no' => $appeal->appeal_no, 'previous_score' => $old->final_score, 'decided_score' => $score, 'reason' => $reason];

        $new = $old->replicate(['id']);
        $new->forceFill([
            'revision_no' => $old->revision_no + 1,
            'supersedes_result_id' => $old->getKey(),
            'is_current' => true,
            'final_score' => $score,
            'rating_band_id' => $rating['band_id'],
            'rating_label_en' => $rating['label_en'],
            'rating_label_am' => $rating['label_am'],
            'status' => ResultStatus::Released,
            'released_at' => now(),
            'released_by' => $actor->getKey(),
            'finalized_at' => now(),
            'finalized_by' => $actor->getKey(),
            'snapshot_json' => $snapshot,
        ]);

        $old->forceFill(['is_current' => false])->save();
        $new->save();

        $new->adjustments()->create([
            'adjustment_type' => 'APPEAL',
            'original_score' => $old->final_score,
            'adjusted_score' => $score,
            'reason' => $reason,
            'requested_by' => $actor->getKey(),
        ])->forceFill(['status' => AdjustmentStatus::Approved, 'decided_by' => $actor->getKey(), 'decided_at' => now()])->save();

        return $new;
    }

    private function nextNumber(): string
    {
        $prefix = 'PA-'.now()->format('Y').'-';
        $last = PerformanceAppeal::query()->where('appeal_no', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('appeal_no')->value('appeal_no');
        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
