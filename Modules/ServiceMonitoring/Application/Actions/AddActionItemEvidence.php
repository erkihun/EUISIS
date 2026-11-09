<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Modules\ServiceMonitoring\Application\Services\AttachmentStore;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;

/** Attaches evidence (PDF/JPG/PNG on the private disk) to an action item. */
final readonly class AddActionItemEvidence
{
    public function __construct(private AttachmentStore $store, private ServiceMonitoringAudit $audit) {}

    public function execute(ActionItem $item, UploadedFile $file, User $actor): MonitoringAttachment
    {
        $attachment = $this->store->store($file, MonitoringAttachment::TYPE_ACTION_ITEM, $item->id, MonitoringAttachment::KIND_EVIDENCE, $actor);
        $this->audit->record(AuditEventType::ServiceMonitoringActionItemEvidenceAdded, $actor, $attachment, $item->owner_organization_id,
            ['action_item_id' => $item->id, 'mime' => $attachment->mime, 'size' => $attachment->size, 'sha256' => $attachment->sha256]);

        return $attachment;
    }
}
