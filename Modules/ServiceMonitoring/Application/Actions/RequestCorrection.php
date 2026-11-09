<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\CorrectionStatus;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Models\CorrectionRequest;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** The institution asks to correct an accepted report before the period is locked (D9). */
final readonly class RequestCorrection
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    public function execute(Submission $submission, User $actor, string $reason): CorrectionRequest
    {
        if ($submission->status !== SubmissionStatus::Accepted || ! $submission->is_current || $submission->period->isLocked()) {
            throw ValidationException::withMessages(['submission' => __('service-monitoring.validation.correction_state')]);
        }

        return DB::transaction(function () use ($submission, $actor, $reason): CorrectionRequest {
            if (CorrectionRequest::query()->where('submission_id', $submission->id)->where('status', CorrectionStatus::Pending->value)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['submission' => __('service-monitoring.validation.correction_open')]);
            }
            $request = CorrectionRequest::query()->create([
                'submission_id' => $submission->id,
                'requested_by' => $actor->id,
                'reason' => $reason,
                'status' => CorrectionStatus::Pending,
            ]);
            $this->audit->record(AuditEventType::ServiceMonitoringCorrectionRequested, $actor, $request, $submission->organization_id,
                ['submission_id' => $submission->id], $reason);

            return $request;
        });
    }
}
