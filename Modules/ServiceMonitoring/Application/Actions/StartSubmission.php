<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\FormTemplateResolver;
use Modules\ServiceMonitoring\Application\Services\PrefillService;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Application\Services\SubmissionStateMachine;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Opens the participant's report for a period, or returns the one already
 * open (idempotent). The organization, level and form are snapshotted, and
 * the Section II system figures are captured.
 */
final readonly class StartSubmission
{
    public function __construct(
        private SubmissionGuard $guard,
        private SubmissionStateMachine $states,
        private PrefillService $prefill,
        private FormTemplateResolver $templates,
        private ServiceMonitoringAudit $audit,
    ) {}

    public function execute(ReportingPeriod $period, Participant $participant, User $actor): Submission
    {
        $existing = $this->current($period, $participant);
        if ($existing !== null) {
            return $existing;
        }
        $this->guard->assertPeriodWritable($period);
        $this->guard->assertOpened($period);

        return DB::transaction(function () use ($period, $participant, $actor): Submission {
            $existing = $this->current($period, $participant, lock: true);
            if ($existing !== null) {
                return $existing;
            }

            $submission = Submission::query()->create([
                'period_id' => $period->id,
                'participant_id' => $participant->id,
                'organization_id' => $participant->organization_id,
                'level' => $participant->level,
                'form_type' => $participant->formType(),
                'form_version' => $period->form_version,
                // The participant's form template, pinned for the life of the report (null: the official form).
                'form_template_id' => $this->templates->forParticipant($participant)?->id,
                'revision_no' => 1,
                'is_current' => true,
                'status' => SubmissionStatus::Draft,
                'created_by' => $actor->id,
            ]);
            $submission->setRelation('period', $period);
            $this->states->record($submission, null, SubmissionStatus::Draft, $actor);
            $this->prefill->capture($submission);

            $this->audit->record(AuditEventType::ServiceMonitoringSubmissionStarted, $actor, $submission, $submission->organization_id,
                ['period_id' => $period->id, 'participant_id' => $participant->id, 'form_type' => $submission->form_type->value]);

            return $submission;
        });
    }

    private function current(ReportingPeriod $period, Participant $participant, bool $lock = false): ?Submission
    {
        $query = Submission::query()->where('period_id', $period->id)->where('participant_id', $participant->id)->where('is_current', true);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
