<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\ClarificationStatus;
use Modules\ServiceMonitoring\Domain\Models\Clarification;

/** Closes a clarification thread; a closed thread takes no further messages. */
final readonly class CloseClarification
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    public function execute(Clarification $clarification, User $actor): Clarification
    {
        if ($clarification->status !== ClarificationStatus::Closed) {
            $clarification->forceFill(['status' => ClarificationStatus::Closed, 'closed_at' => now(), 'closed_by' => $actor->id])->save();
            $this->audit->record(AuditEventType::ServiceMonitoringClarificationClosed, $actor, $clarification, $clarification->participant?->organization_id);
        }

        return $clarification;
    }
}
