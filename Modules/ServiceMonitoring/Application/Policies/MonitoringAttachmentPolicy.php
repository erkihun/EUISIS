<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** Downloads of stamped copies and evidence: whoever may see the record the file belongs to. Every check goes through ServiceMonitoringAccess. */
final class MonitoringAttachmentPolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function download(User $user, MonitoringAttachment $attachment): bool
    {
        return match ($attachment->attachable_type) {
            MonitoringAttachment::TYPE_SUBMISSION => ($submission = Submission::query()->find($attachment->attachable_id)) !== null && $this->access->canViewSubmission($user, $submission),
            MonitoringAttachment::TYPE_ACTION_ITEM => ($item = ActionItem::query()->with('visit.participant')->find($attachment->attachable_id)) !== null
                && $item->visit !== null && ($this->access->canViewVisit($user, $item->visit) || $this->access->canUpdateActionItem($user, $item)),
            default => false,
        };
    }
}
