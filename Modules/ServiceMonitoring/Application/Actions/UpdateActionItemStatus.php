<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\ActionItemStatus;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;

/** Moves an action item through open → in progress → done (or cancelled), with a completion note when closed. */
final readonly class UpdateActionItemStatus
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    public function execute(ActionItem $item, ActionItemStatus $status, ?string $note, User $actor): ActionItem
    {
        $old = $item->status->value;
        $item->fill([
            'status' => $status,
            'completion_note' => $status->isClosed() ? $note : $item->completion_note,
            'completed_at' => $status->isClosed() ? now() : null,
            'completed_by' => $status->isClosed() ? $actor->id : null,
        ])->save();
        $this->audit->record(AuditEventType::ServiceMonitoringActionItemSaved, $actor, $item, $item->owner_organization_id, ['status' => $status->value], $note, ['status' => $old]);

        return $item;
    }
}
