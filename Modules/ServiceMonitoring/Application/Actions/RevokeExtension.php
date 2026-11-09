<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Models\DeadlineExtension;

/** Revokes an extension; the row is kept and a submission already made under it keeps its "late (extension)" label. */
final readonly class RevokeExtension
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    public function execute(DeadlineExtension $extension, User $actor, string $reason): DeadlineExtension
    {
        if ($extension->revoked_at === null) {
            $extension->fill(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason])->save();
            $this->audit->record(AuditEventType::ServiceMonitoringExtensionRevoked, $actor, $extension, $extension->participant?->organization_id,
                ['period_id' => $extension->period_id, 'participant_id' => $extension->participant_id], $reason);
        }

        return $extension;
    }
}
