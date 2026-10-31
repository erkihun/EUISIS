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

readonly class CancelTransferAnnouncementAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(TransferAnnouncement $announcement, User $actor): TransferAnnouncement
    {
        [$announcement, $old] = DB::transaction(function () use ($announcement): array {
            $locked = TransferAnnouncement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($locked->status->isFinal()) {
                throw new DomainException(__('transfers.cancelNotAllowed'));
            }
            $old = $locked->status->value;
            $locked->update(['status' => TransferAnnouncementStatus::Cancelled->value]);

            return [$locked->fresh(), $old];
        });

        $this->writeAuditLogAction->execute(
            AuditEventType::TransferAnnouncementCancelled,
            $actor,
            $announcement->fresh(),
            $announcement->organization_id,
            oldValues: ['status' => $old],
            newValues: ['status' => TransferAnnouncementStatus::Cancelled->value],
        );

        return $announcement->fresh();
    }
}
