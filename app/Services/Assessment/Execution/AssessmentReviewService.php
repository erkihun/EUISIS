<?php

declare(strict_types=1);

namespace App\Services\Assessment\Execution;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\AssessmentRecord;
use App\Models\AssessmentResponse;
use App\Models\AssessmentResultBandPolicy;
use App\Models\User;
use App\Notifications\PerformanceNotification;
use App\Services\Assessment\Oversight\AssessmentResultDistributionService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Review, return for correction, finalization and controlled reopening
 * (docs/assessment-evaluator-workflow.md).
 *
 *   all evaluator components submitted → SUBMITTED (awaiting review)
 *     → return one component for correction (reason; previous version kept)
 *     → FINALIZE (immutable; band resolved and frozen)
 *   review not required by the form → finalized automatically on the last submission.
 *
 * A reviewer never edits an evaluator's answers. Finalized records change
 * only through REOPEN (permission + reason), which keeps the previous result
 * in the audit log.
 */
class AssessmentReviewService
{
    public function __construct(
        private readonly AssessmentResultCalculator $calculator,
        private readonly AssessmentResultDistributionService $bands,
        private readonly AssessmentResultVersionService $versions,
        private readonly OrganizationScopeService $scope,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function canReview(User $actor, AssessmentRecord $record): bool
    {
        return $actor->can('assessments.review') && $this->scope->canAccessOrganization($actor, $record->organization_id) && ! $this->isParticipant($actor, $record);
    }

    public function canFinalize(User $actor, AssessmentRecord $record): bool
    {
        return $actor->can('assessments.finalize') && $this->scope->canAccessOrganization($actor, $record->organization_id)
            && ($record->reviewer_id === null || $record->reviewer_id === $actor->id) && ! $this->isParticipant($actor, $record);
    }

    /** Called inside the submission transaction. */
    public function afterSubmission(AssessmentRecord $record): void
    {
        $result = $this->calculator->calculate($record);
        if ($result === null) {
            return;
        }
        $record->update(['status' => 'submitted', 'percentage' => $result['percentage'], 'contribution' => $result['contribution'], 'score_breakdown' => $result['breakdown']]);
        if (! $record->version->review_required) {
            $this->complete($record, null, $result);

            return;
        }
        $reviewers = $record->reviewer_id
            ? User::query()->whereKey($record->reviewer_id)->get()
            : User::permission('assessments.finalize')->where('status', 'active')->limit(50)->get()->filter(fn (User $u) => $this->canFinalize($u, $record));
        if ($reviewers->isNotEmpty()) {
            Notification::send($reviewers, new PerformanceNotification('assessment_review_pending', route('assessment-reviews.index', [], false), ['database']));
        }
    }

    public function returnForCorrection(User $actor, AssessmentResponse $response, string $reason): void
    {
        abort_unless($actor->can('assessments.return_for_correction') && $this->canReview($actor, $response->record), 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('assessments.execution.reason_required')]);
        }

        DB::transaction(function () use ($actor, $response, $reason): void {
            $response = AssessmentResponse::query()->lockForUpdate()->findOrFail($response->id);
            $record = AssessmentRecord::query()->lockForUpdate()->findOrFail($response->assessment_record_id);
            if ($response->status !== 'submitted' || $record->isFinalized()) {
                throw ValidationException::withMessages(['response' => __('assessments.execution.cannot_return')]);
            }
            $response->revisions()->create([
                'revision_no' => (int) $response->revisions()->max('revision_no') + 1,
                'items' => $response->items()->get(['criterion_id', 'rating_option_id', 'score_snapshot', 'comment'])->toArray(),
                'score_snapshot' => $response->score_snapshot, 'submitted_at' => $response->submitted_at,
                'returned_at' => now(), 'returned_by' => $actor->id, 'return_reason' => $reason, 'created_at' => now(),
            ]);
            $response->update(['status' => 'returned', 'returned_at' => now(), 'returned_by' => $actor->id, 'return_reason' => $reason, 'lock_version' => $response->lock_version + 1]);
            $record->update(['status' => 'assigned', 'percentage' => null, 'contribution' => null, 'score_breakdown' => null]);
            $this->audit->execute(AuditEventType::AssessmentResponseReturned, $actor, $record, $record->organization_id, newValues: ['response_id' => $response->id], reason: $reason, request: request());
            $this->notifyUser($response->evaluator_id, 'assessment_returned', route('assessment-workspace.show', $response->id, false));
        });
    }

    public function finalize(User $actor, AssessmentRecord $record, ?string $comment = null): void
    {
        abort_unless($this->canFinalize($actor, $record), 403);

        DB::transaction(function () use ($actor, $record, $comment): void {
            $record = AssessmentRecord::query()->lockForUpdate()->findOrFail($record->id);
            if ($record->status !== 'submitted') {
                throw ValidationException::withMessages(['record' => __('assessments.execution.cannot_finalize')]);
            }
            $result = $this->calculator->calculate($record);
            if ($result === null) {
                throw ValidationException::withMessages(['record' => __('assessments.execution.incomplete')]);
            }
            $this->complete($record, $actor, $result, $comment);
        });
    }

    public function reopen(User $actor, AssessmentRecord $record, string $reason): void
    {
        abort_unless($this->canFinalize($actor, $record), 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('assessments.execution.reason_required')]);
        }
        DB::transaction(function () use ($actor, $record, $reason): void {
            $record = AssessmentRecord::query()->lockForUpdate()->findOrFail($record->id);
            if (! $record->isFinalized()) {
                throw ValidationException::withMessages(['record' => __('assessments.execution.not_finalized')]);
            }
            $old = $record->only(['status', 'percentage', 'contribution', 'band_code', 'finalized_at', 'finalized_by']);
            $record->update(['status' => 'submitted', 'reopened_at' => now(), 'reopen_reason' => $reason, 'finalized_at' => null, 'finalized_by' => null,
                'reviewed_at' => null, 'acknowledged_at' => null, 'acknowledged_by' => null, 'band_code' => null, 'band_label_en' => null, 'band_label_am' => null, 'band_policy_id' => null]);
            $this->audit->execute(AuditEventType::AssessmentReopened, $actor, $record, $record->organization_id, $old, ['status' => 'submitted'], $reason, request());
        });
    }

    /** Freeze the result: status, finalizer, band of the cycle's pinned policy. */
    private function complete(AssessmentRecord $record, ?User $actor, array $result, ?string $comment = null): void
    {
        $band = null;
        $policy = $record->cycle?->result_band_policy_id ? AssessmentResultBandPolicy::query()->with('bands')->find($record->cycle->result_band_policy_id) : null;
        if ($policy !== null && $result['percentage'] !== null) {
            $band = $this->bands->classify($policy, $result['percentage']);
        }
        $record->update([
            'status' => 'reviewed', 'reviewed_at' => now(), 'finalized_at' => now(), 'finalized_by' => $actor?->id,
            'percentage' => $result['percentage'], 'contribution' => $result['contribution'], 'score_breakdown' => $result['breakdown'],
            'band_policy_id' => $policy?->id, 'band_code' => $band?->code, 'band_label_en' => $band?->label_en, 'band_label_am' => $band?->label_am,
        ]);
        $this->versions->ensureOriginal($record, $actor);
        $this->audit->execute(AuditEventType::AssessmentFinalized, $actor, $record, $record->organization_id,
            newValues: ['percentage' => $result['percentage'], 'band' => $band?->code, 'automatic' => $actor === null], reason: $comment, request: request());
        $employeeUsers = User::query()->where('employee_id', $record->employee_id)->where('status', 'active')->get();
        if ($employeeUsers->isNotEmpty()) {
            Notification::send($employeeUsers, new PerformanceNotification('assessment_result_available', route('employee.assessments.index', [], false), ['database']));
        }
    }

    private function isParticipant(User $actor, AssessmentRecord $record): bool
    {
        return $actor->employee_id === $record->employee_id
            || $record->responses()->where('evaluator_id', $actor->id)->whereIn('status', AssessmentResponse::ACTIVE)->exists();
    }

    private function notifyUser(int $userId, string $kind, string $url): void
    {
        $user = User::query()->whereKey($userId)->where('status', 'active')->first();
        if ($user) {
            $user->notify(new PerformanceNotification($kind, $url, ['database']));
        }
    }
}
