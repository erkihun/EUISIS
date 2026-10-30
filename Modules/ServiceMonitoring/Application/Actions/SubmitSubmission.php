<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringSettings;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Application\Services\SubmissionStateMachine;
use Modules\ServiceMonitoring\Application\Services\SubmissionValidator;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Submits (or resubmits) a report: every section complete or "የለም" (D10),
 * the deadline or an active extension for a first submission (D6), the
 * attester's permission and declaration (D11), and the stamped copy when the
 * setting requires it. A return reopens the report until the period is
 * locked, so a resubmission is not held to the original deadline.
 */
final readonly class SubmitSubmission
{
    public function __construct(
        private SubmissionGuard $guard,
        private SubmissionValidator $validator,
        private SubmissionStateMachine $states,
        private ServiceMonitoringSettings $settings,
        private ServiceMonitoringAudit $audit,
    ) {}

    public function execute(Submission $submission, User $actor, bool $declarationAccepted, ?CarbonImmutable $now = null): Submission
    {
        $this->guard->assertWritable($submission);
        if (! $actor->can('service_monitoring.attest')) {
            throw ValidationException::withMessages(['attest' => __('service-monitoring.validation.attest_permission')]);
        }
        if (! $declarationAccepted) {
            throw ValidationException::withMessages(['declaration' => __('service-monitoring.validation.declaration')]);
        }

        $resubmission = $submission->status === SubmissionStatus::Returned;
        $extension = $resubmission ? null : $this->guard->lateUnder($submission->period, $submission->participant_id, $now);

        $problems = $this->validator->problems($submission);
        if ($problems !== []) {
            throw ValidationException::withMessages(collect($problems)->mapWithKeys(fn (array $messages, int $section): array => ["section_{$section}" => $messages])->all());
        }
        if ($this->settings->requiresStampedCopy() && ! $submission->attachments()->where('kind', MonitoringAttachment::KIND_STAMPED_COPY)->exists()) {
            throw ValidationException::withMessages(['stamped_copy' => __('service-monitoring.validation.stamped_copy_required')]);
        }

        return DB::transaction(function () use ($submission, $actor, $extension, $resubmission, $now): Submission {
            $assignment = $actor->employee?->currentAssignment;
            $locale = app()->getLocale();
            $organization = $submission->organization;
            $submission->fill([
                'attested_by' => $actor->id,
                'attested_at' => $now ?? now(),
                'attester_name_snapshot' => mb_substr((string) ($actor->employee?->full_name ?: $actor->name), 0, 255),
                'attester_title_snapshot' => mb_substr((string) ($locale === 'am' ? ($assignment?->position?->title_am ?: $assignment?->position?->title_en) : $assignment?->position?->title_en), 0, 255) ?: null,
                'attester_organization_snapshot' => mb_substr((string) ($locale === 'am' && $organization?->name_am ? $organization->name_am : $organization?->name_en), 0, 255),
                'submitted_at' => $now ?? now(),
                'submitted_by' => $actor->id,
            ]);
            if (! $resubmission) {
                $submission->extension_id = $extension?->id;
                $submission->submitted_late = $extension !== null;
            }
            $this->states->move($submission, SubmissionStatus::Submitted, $actor, $resubmission ? 'resubmitted' : null);

            $this->audit->record(AuditEventType::ServiceMonitoringSubmitted, $actor, $submission, $submission->organization_id, [
                'revision_no' => $submission->revision_no,
                'resubmission' => $resubmission,
                'late_under_extension' => $submission->submitted_late,
                'extension_id' => $submission->extension_id,
            ]);

            return $submission;
        });
    }
}
