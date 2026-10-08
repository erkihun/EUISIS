<?php

declare(strict_types=1);

namespace App\Services\Assessment\Execution;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\Assessment\FormVersionStatus;
use App\Enums\AuditEventType;
use App\Models\AssessmentCycleEligibility;
use App\Models\AssessmentFormVersion;
use App\Models\AssessmentRecord;
use App\Models\AssessmentResponse;
use App\Models\AssessmentResponseEvidence;
use App\Models\User;
use App\Services\Assessment\AssessmentScoringService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An evaluator's work on one evaluator assignment
 * (docs/assessment-evaluator-workflow.md).
 *
 * Access = permission (what) + this assignment's evaluator (who) + workflow
 * status (when). Answers are rating-option ids; the server resolves every
 * score from the option, so a score sent by a browser is ignored. Drafts are
 * protected by an optimistic lock version; submission locks the rows and
 * recalculates everything in one transaction.
 */
class AssessmentResponseService
{
    public const EVIDENCE_DISK = 'local';

    public const EVIDENCE_MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

    public const EVIDENCE_MAX_KB = 10240;

    public function __construct(
        private readonly AssessmentScoringService $scoring,
        private readonly AssessmentReviewService $reviews,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** May this user open the assignment? Only its own evaluator, with the permission, while it is live. */
    public function canOpen(User $user, AssessmentResponse $response): bool
    {
        return $response->evaluator_id === $user->id && $user->isActive() && $user->can('assessments.view_assigned')
            && $response->status !== 'cancelled' && ! in_array($response->record?->status, ['cancelled', 'pending_assignment'], true);
    }

    public function canEdit(User $user, AssessmentResponse $response): bool
    {
        return $this->canOpen($user, $response) && $user->can('assessments.complete_assigned') && $response->isEditable()
            && in_array($response->record->status, ['assigned', 'submitted'], true);
    }

    /**
     * The version actually used must be the one the cycle snapshot resolved
     * for this employee, and must not be a draft.
     */
    public function assertFormAssignment(AssessmentRecord $record): void
    {
        $version = $record->version;
        if ($version === null || $version->status === FormVersionStatus::Draft) {
            throw new HttpException(409, 'FORM_VERSION_NOT_PUBLISHED');
        }
        if ($record->assessment_cycle_id !== null) {
            $expected = AssessmentCycleEligibility::query()->where('assessment_cycle_id', $record->assessment_cycle_id)
                ->where('employee_id', $record->employee_id)->value('expected_form_version_id');
            if ($expected !== null && $expected !== $record->form_version_id) {
                throw new HttpException(409, 'FORM_ASSIGNMENT_MISMATCH');
            }
        }
    }

    /**
     * Save a partial draft. $answers: criterion id => rating option id (or null);
     * $comments: criterion id => text. The whole client state is sent; items
     * not present are removed. Returns the new lock version.
     *
     * @param  array<string, ?string>  $answers
     * @param  array<string, ?string>  $comments
     */
    public function saveDraft(User $user, AssessmentResponse $response, array $answers, array $comments, int $lockVersion): int
    {
        abort_unless($this->canEdit($user, $response), 403);

        return DB::transaction(function () use ($user, $response, $answers, $comments, $lockVersion): int {
            $response = AssessmentResponse::query()->lockForUpdate()->findOrFail($response->id);
            $this->assertEditable($response, $lockVersion);
            // Do not rely on the GET page check: a crafted draft request must
            // not start work against a form that differs from the cycle's
            // finalized eligibility snapshot.
            $this->assertFormAssignment($response->record);
            $version = $this->version($response);
            $this->writeItems($response, $version, $answers, $comments);
            $first = $response->started_at === null;
            $response->update([
                'status' => $response->status === 'returned' ? 'returned' : 'in_progress',
                'started_at' => $response->started_at ?? now(), 'last_saved_at' => now(), 'lock_version' => $response->lock_version + 1,
            ]);
            // Autosave is not audited per keystroke; the first save is.
            if ($first) {
                $this->audit->execute(AuditEventType::AssessmentDraftStarted, $user, $response->record, $response->record->organization_id, newValues: ['response_id' => $response->id], request: request());
            }

            return $response->lock_version;
        });
    }

    /**
     * Final submission. Everything official is recomputed here.
     *
     * @param  array<string, ?string>  $answers
     * @param  array<string, ?string>  $comments
     */
    public function submit(User $user, AssessmentResponse $response, array $answers, array $comments, int $lockVersion, ?string $lateReason = null): void
    {
        abort_unless($this->canEdit($user, $response) && $user->can('assessments.submit'), 403);
        // 1. Commit the answers as a draft first, so a refused submission never loses work.
        $lockVersion = $this->saveDraft($user, $response, $answers, $comments, $lockVersion);

        // 2. Validate and submit against exactly what was saved.
        DB::transaction(function () use ($user, $response, $lockVersion, $lateReason): void {
            $response = AssessmentResponse::query()->lockForUpdate()->findOrFail($response->id);
            $record = AssessmentRecord::query()->lockForUpdate()->findOrFail($response->assessment_record_id);
            $this->assertEditable($response, $lockVersion);
            $this->assertFormAssignment($record);
            $cycle = $record->cycle;
            if ($cycle !== null && $cycle->status !== 'active') {
                throw ValidationException::withMessages(['cycle' => __('assessments.execution.cycle_closed')]);
            }
            $late = $this->lateness($response, $cycle?->late_submission_policy, $lateReason);
            $version = $this->version($response);
            $items = $response->items()->get()->keyBy('criterion_id');
            $evidence = $response->evidence()->pluck('criterion_id')->unique()->all();

            $errors = [];
            $scores = [];
            foreach ($version->sections as $section) {
                foreach ($section->criteria as $criterion) {
                    $item = $items->get($criterion->id);
                    $option = $item?->rating_option_id ? $criterion->options->firstWhere('id', $item->rating_option_id) : null;
                    if ($option === null) {
                        if ($criterion->is_required) {
                            $errors["answers.{$criterion->id}"] = __('assessments.execution.required_answer', ['criterion' => $criterion->title_en]);
                        }

                        continue;
                    }
                    $scores[$criterion->id] = (string) $option->score;
                    $item->update(['score_snapshot' => $this->scoring->criterionScore($criterion, (string) $option->score)]);
                    if ($criterion->comment_mode?->value === 'required' && trim((string) $item->comment) === '') {
                        $errors["comments.{$criterion->id}"] = __('assessments.execution.required_comment', ['criterion' => $criterion->title_en]);
                    }
                    if ($criterion->evidence_mode?->value === 'required' && ! in_array($criterion->id, $evidence, true)) {
                        $errors["evidence.{$criterion->id}"] = __('assessments.execution.required_evidence', ['criterion' => $criterion->title_en]);
                    }
                }
            }
            if ($errors !== []) {
                // The draft committed in step 1 stays; only the submission is refused.
                throw ValidationException::withMessages($errors);
            }

            $score = $this->scoring->score($version, $scores);
            $response->update([
                'status' => 'submitted', 'submitted_at' => now(), 'submission_count' => $response->submission_count + 1,
                'answers' => $items->map->rating_option_id->filter()->all(),
                'score_snapshot' => [...$score, 'form_version_id' => $version->id, 'calculated_at' => now()->toIso8601String()],
                'submitted_late' => $late, 'late_reason' => $late ? $lateReason : null,
                'started_at' => $response->started_at ?? now(), 'last_saved_at' => now(), 'lock_version' => $response->lock_version + 1,
            ]);
            $this->audit->execute(AuditEventType::AssessmentResponseSubmitted, $user, $record, $record->organization_id,
                newValues: ['response_id' => $response->id, 'evaluator_type' => $response->evaluator_type, 'submission' => $response->submission_count, 'late' => $late], request: request());

            $this->reviews->afterSubmission($record);
        });
    }

    public function attachEvidence(User $user, AssessmentResponse $response, string $criterionId, UploadedFile $file): AssessmentResponseEvidence
    {
        abort_unless($this->canEdit($user, $response), 403);
        $this->assertFormAssignment($response->record);
        $criterion = $this->version($response)->criteria()->whereKey($criterionId)->first();
        if ($criterion === null || $criterion->evidence_mode?->value === 'disabled') {
            throw ValidationException::withMessages(['file' => __('assessments.execution.evidence_not_allowed')]);
        }
        $name = Str::limit(preg_replace('/[^\pL\pN._ -]+/u', '_', basename($file->getClientOriginalName())) ?: 'evidence', 150, '');
        $path = $file->store('assessment-evidence/'.$response->assessment_record_id, self::EVIDENCE_DISK);
        $evidence = $response->evidence()->create([
            'criterion_id' => $criterion->id, 'disk' => self::EVIDENCE_DISK, 'path' => $path, 'original_name' => $name,
            'mime' => (string) $file->getMimeType(), 'size' => (int) $file->getSize(), 'uploaded_by' => $user->id,
        ]);
        $this->audit->execute(AuditEventType::AssessmentEvidenceUploaded, $user, $response->record, $response->record->organization_id, newValues: ['evidence_id' => $evidence->id, 'criterion_id' => $criterion->id], request: request());

        return $evidence;
    }

    public function removeEvidence(User $user, AssessmentResponseEvidence $evidence): void
    {
        abort_unless($this->canEdit($user, $evidence->response), 403);
        Storage::disk($evidence->disk)->delete($evidence->path);
        $evidence->delete();
    }

    private function assertEditable(AssessmentResponse $response, int $lockVersion): void
    {
        if (! $response->isEditable()) {
            throw ValidationException::withMessages(['response' => __('assessments.execution.not_editable')]);
        }
        if ($response->lock_version !== $lockVersion) {
            throw ValidationException::withMessages(['lock_version' => __('assessments.execution.stale')]);
        }
    }

    /** Late handling follows the cycle policy; undecided (NULL) accepts and flags the submission. */
    private function lateness(AssessmentResponse $response, ?string $policy, ?string $reason): bool
    {
        if ($response->due_at === null || now()->toDateString() <= $response->due_at->toDateString()) {
            return false;
        }
        if ($policy === 'block') {
            throw ValidationException::withMessages(['due_at' => __('assessments.execution.late_blocked')]);
        }
        if ($policy === 'allow_with_reason' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['late_reason' => __('assessments.execution.late_reason_required')]);
        }

        return true;
    }

    private function version(AssessmentResponse $response): AssessmentFormVersion
    {
        return $response->record->version()->with('sections.criteria.options')->firstOrFail();
    }

    /**
     * Upsert the evaluator's answers. Every criterion and option id must
     * belong to this form version; nothing else is accepted.
     *
     * @param  array<string, ?string>  $answers
     * @param  array<string, ?string>  $comments
     */
    private function writeItems(AssessmentResponse $response, AssessmentFormVersion $version, array $answers, array $comments): void
    {
        $criteria = $version->sections->flatMap->criteria->keyBy('id');
        $unknown = array_diff(array_keys($answers + $comments), $criteria->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['answers' => __('assessments.execution.unknown_criterion')]);
        }
        $keep = [];
        foreach ($criteria as $criterionId => $criterion) {
            $optionId = $answers[$criterionId] ?? null;
            $comment = $criterion->comment_mode?->value === 'disabled' ? null : (isset($comments[$criterionId]) ? trim((string) $comments[$criterionId]) : null);
            if ($optionId !== null && $optionId !== '' && ! $criterion->options->contains('id', $optionId)) {
                throw ValidationException::withMessages(["answers.{$criterionId}" => __('assessments.execution.invalid_option')]);
            }
            if (($optionId === null || $optionId === '') && ($comment === null || $comment === '')) {
                continue;
            }
            $option = $optionId ? $criterion->options->firstWhere('id', $optionId) : null;
            $response->items()->updateOrCreate(['criterion_id' => $criterionId], [
                'rating_option_id' => $option?->id, 'comment' => $comment === '' ? null : $comment,
                'score_snapshot' => $option ? $this->scoring->criterionScore($criterion, (string) $option->score) : null,
            ]);
            $keep[] = $criterionId;
        }
        $response->items()->whereNotIn('criterion_id', $keep)->delete();
    }
}
