<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionStateMachine;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * The reviewer accepts a submitted report. A corrected revision supersedes
 * the accepted one it was copied from, in the same transaction, so exactly
 * one accepted revision counts at any time (D9).
 */
final readonly class AcceptSubmission
{
    public function __construct(private SubmissionStateMachine $states, private ServiceMonitoringAudit $audit) {}

    public function execute(Submission $submission, User $actor): Submission
    {
        return DB::transaction(function () use ($submission, $actor): Submission {
            $superseded = null;
            if ($submission->supersedes_submission_id !== null) {
                $superseded = Submission::query()->whereKey($submission->supersedes_submission_id)->lockForUpdate()->first();
                if ($superseded !== null && $superseded->status === SubmissionStatus::Accepted) {
                    $superseded->setRelation('period', $submission->period);
                    $this->states->move($superseded, SubmissionStatus::Superseded, $actor, 'superseded by revision '.$submission->revision_no);
                }
            }

            $submission->fill(['reviewed_at' => now(), 'reviewed_by' => $actor->id, 'accepted_at' => now(), 'accepted_by' => $actor->id]);
            $this->states->move($submission, SubmissionStatus::Accepted, $actor);

            $this->audit->record(AuditEventType::ServiceMonitoringAccepted, $actor, $submission, $submission->organization_id,
                ['revision_no' => $submission->revision_no, 'superseded_submission_id' => $superseded?->id]);

            return $submission;
        });
    }
}
