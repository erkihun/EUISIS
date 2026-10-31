<?php

declare(strict_types=1);

namespace Modules\Transfer\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Transfer\Contracts\Data\TransferAnnouncementStatus;
use Modules\Transfer\Domain\Models\TransferAnnouncement;

readonly class CloseTransferAnnouncementAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(TransferAnnouncement $announcement, User $actor): TransferAnnouncement
    {
        $announcement = DB::transaction(function () use ($announcement): TransferAnnouncement {
            $locked = TransferAnnouncement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($locked->status !== TransferAnnouncementStatus::Published) {
                throw new DomainException(__('transfers.announcementNotPublished'));
            }
            $locked->update(['status' => TransferAnnouncementStatus::Closed->value]);

            return $locked->fresh();
        });

        $this->writeAuditLogAction->execute(
            AuditEventType::TransferAnnouncementClosed,
            $actor,
            $announcement->fresh(),
            $announcement->organization_id,
            oldValues: ['status' => TransferAnnouncementStatus::Published->value],
            newValues: ['status' => TransferAnnouncementStatus::Closed->value],
        );

        return $announcement->fresh();
    }
}
