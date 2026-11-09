<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Enums\VerificationStatus;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;

/**
 * The Innovation & Technology Bureau verifies a reported digital service
 * (D20). Allowed on submitted or accepted reports of an unlocked period,
 * without changing the report's own status. Only verified rows count.
 */
final readonly class VerifyDigitalService
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    public static function assertVerifiable(DigitalService $service): void
    {
        $submission = $service->submission;
        if (! in_array($submission->status, [SubmissionStatus::Submitted, SubmissionStatus::Accepted], true) || $submission->period->isLocked()) {
            throw ValidationException::withMessages(['verification' => __('service-monitoring.validation.verification_state')]);
        }
    }

    public function execute(DigitalService $service, User $actor): DigitalService
    {
        self::assertVerifiable($service);
        $service->fill(['verification_status' => VerificationStatus::Verified, 'verified_by' => $actor->id, 'verified_at' => now(), 'rejection_reason' => null])->save();
        $this->audit->record(AuditEventType::ServiceMonitoringDigitalServiceVerified, $actor, $service, $service->submission->organization_id, ['submission_id' => $service->submission_id]);

        return $service;
    }
}
