<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ReminderService;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\VerificationStatus;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;

/** Rejects a reported digital service with a reason and notifies the institution (D20). */
final readonly class RejectDigitalService
{
    public function __construct(private ReminderService $notices, private ServiceMonitoringAudit $audit) {}

    public function execute(DigitalService $service, User $actor, string $reason): DigitalService
    {
        VerifyDigitalService::assertVerifiable($service);
        $service->fill(['verification_status' => VerificationStatus::Rejected, 'verified_by' => $actor->id, 'verified_at' => now(), 'rejection_reason' => $reason])->save();
        $this->audit->record(AuditEventType::ServiceMonitoringDigitalServiceRejected, $actor, $service, $service->submission->organization_id, ['submission_id' => $service->submission_id], $reason);
        $this->notices->notifyDigitalServiceRejected($service);

        return $service;
    }
}
