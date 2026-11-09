<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\ActionItemStatus;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;

/** Adds a follow-up action to a visit (D22): owner organization, optional owner user, due date. */
final readonly class CreateActionItem
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    /** @param array{title: string, finding_id?: ?string, owner_organization_id: string, owner_user_id?: ?int, due_on: string} $data */
    public function execute(MonitoringVisit $visit, array $data, User $actor): ActionItem
    {
        $findingId = $data['finding_id'] ?? null;
        $item = ActionItem::query()->create([
            'visit_id' => $visit->id,
            'finding_id' => $findingId !== null && $visit->findings()->whereKey($findingId)->exists() ? $findingId : null,
            'title' => $data['title'],
            'owner_organization_id' => $data['owner_organization_id'],
            'owner_user_id' => $data['owner_user_id'] ?? null,
            'due_on' => $data['due_on'],
            'status' => ActionItemStatus::Open,
            'created_by' => $actor->id,
        ]);
        $this->audit->record(AuditEventType::ServiceMonitoringActionItemSaved, $actor, $item, $item->owner_organization_id, ['visit_id' => $visit->id, 'due_on' => $data['due_on'], 'status' => 'open']);

        return $item;
    }
}
