<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TransferApplicationStatus;
use App\Enums\TransferApprovalStatus;
use App\Enums\TransferApprovalType;
use App\Models\TransferApplication;
use App\Models\TransferApproval;
use App\Models\TransferSetting;
use App\Models\User;
use App\Services\Transfers\CanonicalTransferCreationService;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class ApproveTransferApprovalAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private CanonicalTransferCreationService $canonicalTransfers,
    ) {}

    public function execute(TransferApproval $approval, User $actor): TransferApproval
    {
        if (! $approval->isPending()) {
            throw new DomainException('This approval has already been decided.');
        }

        return DB::transaction(function () use ($approval, $actor): TransferApproval {
            $approval = TransferApproval::query()->lockForUpdate()->findOrFail($approval->id);
            if (! $approval->isPending()) {
                throw new DomainException('This approval has already been decided.');
            }

            $approval->update([
                'status' => TransferApprovalStatus::Approved->value,
                'approver_id' => $actor->id,
                'decided_at' => now(),
            ]);

            $application = $approval->transferApplication()->lockForUpdate()->firstOrFail();
            $settings = TransferSetting::current();

            $auditEvent = match ($approval->approval_type) {
                TransferApprovalType::Release => AuditEventType::TransferReleaseApproved,
                TransferApprovalType::Receiving => AuditEventType::TransferReceivingApproved,
                TransferApprovalType::Final => AuditEventType::TransferFinalApproved,
            };

            $this->writeAuditLogAction->execute(
                $auditEvent,
                $actor,
                $application,
                $actor->organizationScopes()->first()?->organization_id,
                newValues: $approval->toArray(),
            );

            // Advance application status based on what's next in the chain
            $nextStatus = $this->resolveNextStatus($approval->approval_type, $settings);
            $application->update(['status' => $nextStatus->value]);

            // Approval is not implementation.  Capacity and assignment state are
            // rechecked by the separately authorized implementation operation.
            if ($nextStatus === TransferApplicationStatus::Approved) {
                $application->update(['approved_at' => now()]);
                $this->createCanonicalTransfer($application, $actor);
            }

            return $approval->fresh();
        });
    }

    private function resolveNextStatus(TransferApprovalType $type, TransferSetting $settings): TransferApplicationStatus
    {
        return match ($type) {
            TransferApprovalType::Release => $settings->receiving_consent_required
                ? TransferApplicationStatus::ReceivingPending
                : ($settings->final_approval_required
                    ? TransferApplicationStatus::FinalApprovalPending
                    : TransferApplicationStatus::Approved),

            TransferApprovalType::Receiving => $settings->final_approval_required
                ? TransferApplicationStatus::FinalApprovalPending
                : TransferApplicationStatus::Approved,

            TransferApprovalType::Final => TransferApplicationStatus::Approved,
        };
    }

    /**
     * Selection/approval creates the HR movement record, never an assignment.
     * The unique transfer_application_id constraint makes retries idempotent.
     */
    private function createCanonicalTransfer(TransferApplication $application, User $actor): void
    {
        $this->canonicalTransfers->create($application, $actor);
    }
}
