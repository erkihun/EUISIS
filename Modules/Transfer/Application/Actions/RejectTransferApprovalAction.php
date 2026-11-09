<?php

declare(strict_types=1);

namespace Modules\Transfer\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;
use Modules\Transfer\Domain\Enums\TransferApprovalStatus;
use Modules\Transfer\Domain\Enums\TransferApprovalType;
use Modules\Transfer\Domain\Models\TransferApplication;
use Modules\Transfer\Domain\Models\TransferApproval;

readonly class RejectTransferApprovalAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(TransferApproval $approval, User $actor, string $reason): TransferApproval
    {
        if (! $approval->isPending()) {
            throw new DomainException('This approval has already been decided.');
        }

        // Locked and re-checked like the approval path (same lock order:
        // approval → application), so a parallel approve and reject cannot
        // both decide, and an approved or transferred application is never
        // relabelled rejected (risk register CON-6).
        return DB::transaction(function () use ($approval, $actor, $reason): TransferApproval {
            $approval = TransferApproval::query()->lockForUpdate()->findOrFail($approval->id);
            if (! $approval->isPending()) {
                throw new DomainException('This approval has already been decided.');
            }
            $application = TransferApplication::query()->whereKey($approval->transfer_application_id)->lockForUpdate()->firstOrFail();
            self::assertAtStage($approval, $application);

            $approval->update([
                'status' => TransferApprovalStatus::Rejected->value,
                'approver_id' => $actor->id,
                'rejection_reason' => $reason,
                'decided_at' => now(),
            ]);

            $application->update([
                'status' => TransferApplicationStatus::Rejected->value,
                'rejected_reason' => $reason,
            ]);

            $auditEvent = match ($approval->approval_type) {
                TransferApprovalType::Release => AuditEventType::TransferReleaseRejected,
                TransferApprovalType::Receiving => AuditEventType::TransferReceivingRejected,
                TransferApprovalType::Final => AuditEventType::TransferFinalRejected,
            };

            $this->writeAuditLogAction->execute(
                $auditEvent,
                $actor,
                $application->fresh(),
                $actor->organizationScopes()->first()?->organization_id,
                newValues: $approval->toArray(),
                reason: $reason,
            );

            return $approval->fresh();
        });
    }

    /**
     * An approval decides only the stage the application is waiting at; a
     * leftover approval of a stage already passed (or of a closed
     * application) cannot move it.
     */
    public static function assertAtStage(TransferApproval $approval, TransferApplication $application): void
    {
        $expected = match ($approval->approval_type) {
            TransferApprovalType::Release => TransferApplicationStatus::ReleasePending,
            TransferApprovalType::Receiving => TransferApplicationStatus::ReceivingPending,
            TransferApprovalType::Final => TransferApplicationStatus::FinalApprovalPending,
        };

        if ($application->status !== $expected) {
            throw new DomainException('The application is no longer waiting for this approval.');
        }
    }
}
