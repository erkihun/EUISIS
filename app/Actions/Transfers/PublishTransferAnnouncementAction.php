<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TransferAnnouncementStatus;
use App\Jobs\Transfers\NotifyEligibleTransferAudience;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\TransferAnnouncement;
use App\Models\User;
use App\Services\Transfers\TransferAnnouncementReadinessService;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class PublishTransferAnnouncementAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private TransferAnnouncementReadinessService $readiness,
    ) {}

    public function execute(TransferAnnouncement $announcement, User $actor): TransferAnnouncement
    {
        if ($announcement->status !== TransferAnnouncementStatus::Draft) {
            throw new DomainException(__('transfers.announcementNotDraft'));
        }

        DB::transaction(function () use ($announcement, $actor): void {
            $announcement = TransferAnnouncement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($announcement->status !== TransferAnnouncementStatus::Draft) {
                throw new DomainException(__('transfers.announcementNotDraft'));
            }

            // This is deliberately repeated after acquiring the workflow row
            // lock; a UI readiness result is advisory and capacity can change.
            $result = $this->readiness->assess($announcement);
            if (! $result['ready']) {
                throw new DomainException($result['issues'][0]['message'] ?? 'This announcement is not ready for publication.');
            }
            $positions = $announcement->positions()->lockForUpdate()->get();

            // Legacy single-position announcements remain supported during rollout.
            if ($positions->isEmpty()) {
                $positions = collect([(object) [
                    'organization_id' => $announcement->organization_id,
                    'position_id' => $announcement->position_id,
                    'vacancy_count' => $announcement->number_of_vacancies,
                ]]);
            }

            foreach ($positions as $line) {
                $position = Position::query()->lockForUpdate()->find($line->position_id);
                if ($position === null || ! $position->isSelectable()) {
                    throw new DomainException('An announced position is no longer active or selectable.');
                }
                $establishments = PositionEstablishment::query()
                    ->where('organization_id', $line->organization_id)
                    ->where('position_id', $line->position_id)
                    ->where('status', 'approved')
                    ->lockForUpdate()
                    ->get();
                $available = $establishments->sum(fn (PositionEstablishment $e) => $e->availableSlots());

                if ($available < (int) $line->vacancy_count) {
                    throw new DomainException(__('transfers.publishNoEstablishment'));
                }
            }

            $announcement->update([
                'status' => TransferAnnouncementStatus::Published->value,
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);
        });

        $this->writeAuditLogAction->execute(
            AuditEventType::TransferAnnouncementPublished,
            $actor,
            $announcement->fresh(),
            $announcement->organization_id,
            oldValues: ['status' => TransferAnnouncementStatus::Draft->value],
            newValues: ['status' => TransferAnnouncementStatus::Published->value],
        );

        NotifyEligibleTransferAudience::dispatch($announcement->id)->afterCommit();

        return $announcement->fresh();
    }
}
