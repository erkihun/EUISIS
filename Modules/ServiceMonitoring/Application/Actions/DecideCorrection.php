<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionStateMachine;
use Modules\ServiceMonitoring\Domain\Enums\CorrectionStatus;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Enums\VerificationStatus;
use Modules\ServiceMonitoring\Domain\Models\CorrectionRequest;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * The reviewer decides a correction request (D9). Approval copies the
 * accepted revision n into a new draft n+1 (the working revision); n stays
 * accepted and keeps counting until n+1 is accepted and supersedes it.
 * Verification of unchanged digital services is carried over.
 */
final readonly class DecideCorrection
{
    public function __construct(private SubmissionStateMachine $states, private ServiceMonitoringAudit $audit) {}

    public function execute(CorrectionRequest $request, User $actor, bool $approve, ?string $note): CorrectionRequest
    {
        $original = $request->submission;
        if ($request->status !== CorrectionStatus::Pending || $original->status !== SubmissionStatus::Accepted || $original->period->isLocked()) {
            throw ValidationException::withMessages(['submission' => __('service-monitoring.validation.correction_state')]);
        }

        return DB::transaction(function () use ($request, $original, $actor, $approve, $note): CorrectionRequest {
            $revision = $approve ? $this->copy($original, $actor) : null;
            $request->fill([
                'status' => $approve ? CorrectionStatus::Approved : CorrectionStatus::Rejected,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'new_submission_id' => $revision?->id,
            ])->save();

            $this->audit->record(AuditEventType::ServiceMonitoringCorrectionDecided, $actor, $request, $original->organization_id,
                ['approved' => $approve, 'new_submission_id' => $revision?->id, 'revision_no' => $revision?->revision_no], $note);

            return $request;
        });
    }

    private function copy(Submission $original, User $actor): Submission
    {
        $original->load(['accountabilityEntries', 'institutionBuilding', 'prefillValues', 'digitalServices', 'serviceTotals', 'standardPerformance']);
        $original->forceFill(['is_current' => false])->save();

        $revision = $original->replicate([
            'is_current', 'status', 'revision_no', 'attested_by', 'attested_at', 'attester_name_snapshot', 'attester_title_snapshot',
            'attester_organization_snapshot', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'accepted_at', 'accepted_by',
            'supersedes_submission_id', 'extension_id', 'submitted_late', 'created_by',
            // MySQL's generated unique-key columns (see the migration) are never written.
            'current_key', 'accepted_key',
        ]);
        $revision->fill([
            'revision_no' => $original->revision_no + 1,
            'is_current' => true,
            'status' => SubmissionStatus::Draft,
            'supersedes_submission_id' => $original->id,
            'created_by' => $actor->id,
        ])->save();
        $revision->setRelation('period', $original->period);
        $this->states->record($revision, null, SubmissionStatus::Draft, $actor, 'correction of revision '.$original->revision_no);

        foreach ($original->accountabilityEntries as $entry) {
            $entry->replicate(['submission_id'])->fill(['submission_id' => $revision->id])->save();
        }
        foreach ([$original->institutionBuilding, $original->serviceTotals, $original->standardPerformance] as $row) {
            $row?->replicate(['submission_id'])->fill(['submission_id' => $revision->id])->save();
        }
        foreach ($original->prefillValues as $prefill) {
            $prefill->replicate(['submission_id'])->fill(['submission_id' => $revision->id])->save();
        }

        // Main services first, so sub services can point at their copied parent.
        $map = [];
        foreach ($original->digitalServices->sortBy(fn (DigitalService $d): int => $d->parent_entry_id === null ? 0 : 1) as $service) {
            $copy = $service->replicate(['submission_id', 'parent_entry_id'])->fill([
                'submission_id' => $revision->id,
                'parent_entry_id' => $service->parent_entry_id !== null ? ($map[$service->parent_entry_id] ?? null) : null,
                'verification_status' => $service->verification_status === VerificationStatus::Verified ? VerificationStatus::Verified : VerificationStatus::Pending,
            ]);
            $copy->save();
            $map[$service->id] = $copy->id;
        }

        return $revision;
    }
}
