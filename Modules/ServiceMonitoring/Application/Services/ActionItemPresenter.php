<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Models\User;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;

/** One action item as the visit and action-item screens show it. */
class ActionItemPresenter
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    /** @return array<string, mixed> */
    public function present(ActionItem $item, User $user, string $today): array
    {
        return [
            'id' => $item->id, 'title' => $item->title, 'due_on' => $item->due_on->toDateString(), 'status' => $item->status->value,
            'overdue' => $item->isOverdue($today), 'completion_note' => $item->completion_note, 'finding_id' => $item->finding_id, 'visit_id' => $item->visit_id,
            'owner_organization' => ['name_en' => $item->ownerOrganization?->name_en, 'name_am' => $item->ownerOrganization?->name_am],
            'owner_user' => $item->ownerUser?->name,
            'evidence' => $item->evidence->map(fn (MonitoringAttachment $a): array => [
                'id' => $a->id, 'original_name' => $a->original_name, 'download_url' => route('service-monitoring.attachments.download', $a),
            ])->values()->all(),
            'can_update' => $this->access->canUpdateActionItem($user, $item),
        ];
    }
}
