<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\PeriodStatus;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/**
 * Reopens a locked period with a reason (D9). Stored snapshots stay as they
 * were; reports read live figures again until the period is locked anew.
 */
final readonly class ReopenPeriod
{
    public function __construct(private ConsolidationService $consolidation, private ServiceMonitoringAudit $audit) {}

    public function execute(ReportingPeriod $period, User $actor, string $reason): ReportingPeriod
    {
        if (! $period->isLocked()) {
            throw ValidationException::withMessages(['period' => __('service-monitoring.validation.reopen_state')]);
        }
        $period->status = PeriodStatus::Open;
        $period->status = $period->effectiveStatus();
        $period->fill(['reopened_at' => now(), 'reopened_by' => $actor->id, 'reopened_reason' => $reason])->save();
        $this->consolidation->forget();
        $this->audit->record(AuditEventType::ServiceMonitoringPeriodReopened, $actor, $period, null, ['status' => $period->status->value], $reason);

        return $period;
    }
}
