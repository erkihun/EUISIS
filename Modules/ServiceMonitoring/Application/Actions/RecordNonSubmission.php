<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ComplianceService;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Models\NonSubmission;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** Records formally that a participant did not submit for a period, so its sub-city can certify (D8). */
final readonly class RecordNonSubmission
{
    public function __construct(private SubmissionGuard $guard, private ComplianceService $compliance, private ServiceMonitoringAudit $audit) {}

    public function execute(ReportingPeriod $period, Participant $participant, string $reason, User $actor): NonSubmission
    {
        $this->guard->assertPeriodWritable($period);
        $status = $this->compliance->forPeriod($period, [$participant])[$participant->id]['status'];
        if (! in_array($status, ['missing', 'pending'], true)) {
            throw ValidationException::withMessages(['participant_id' => __('service-monitoring.validation.not_submitted_state')]);
        }
        $record = NonSubmission::query()->create(['period_id' => $period->id, 'participant_id' => $participant->id, 'reason' => $reason, 'recorded_by' => $actor->id]);
        $this->audit->record(AuditEventType::ServiceMonitoringNonSubmissionRecorded, $actor, $record, $participant->organization_id, ['period_id' => $period->id], $reason);

        return $record;
    }
}
